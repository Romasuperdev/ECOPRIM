<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use App\Support\AnneeScolaireGuard;
use App\Support\ConflitsEmploiDuTemps;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Emplois du temps — ECONOMAT.dbo.T_EMPLOIDUTEMPS.
 *
 * Un créneau est (jour, heure, classe, matière, salle, année). L'enseignant n'est pas
 * stocké : il est déduit de T_CORPROFCLASSE, comme le fait la vue V_EMPLOIDUTEMPS_SIMPLE.
 *
 * EXCEPTION ASSUMÉE à la règle « jamais de DELETE » qui vaut ailleurs dans NEXORA :
 * un emploi du temps est de la planification qu'on réorganise, pas un historique à
 * conserver. Vider une case efface donc réellement la ligne. Le verrou d'année clôturée
 * s'applique en revanche pleinement.
 */
class EmploiDuTempsController extends Controller
{
    private function t(): EconomatTable
    {
        return EconomatTable::pour('T_EMPLOIDUTEMPS', 'CODE');
    }

    /** Jours, heures et salles : la trame de la grille. */
    public function referentiels()
    {
        return [
            'jours' => $this->lire('T_EMPJOUR', fn ($l) => [
                'code' => (int) $l->Code, 'libelle' => $l->Libelle,
            ], 'Code'),
            'heures' => $this->lire('T_HORAIRE', fn ($l) => [
                'code' => (int) $l->COD_HORAIRE,
                'debut' => $l->HEUR_DEBUT,
                'fin' => $l->HEUR_FIN,
                'duree' => $l->DUREEE,
                'libelle' => trim(($l->HEUR_DEBUT ?? '').' - '.($l->HEUR_FIN ?? '')),
            ], 'COD_HORAIRE'),
            'salles' => $this->lire('T_SALLESCLASSE', fn ($l) => [
                'code' => $l->CODESALLE, 'libelle' => $l->LIBELLESALLE ?: $l->CODESALLE,
                'places' => $l->NBREPLACE,
            ], 'LIBELLESALLE'),
        ];
    }

    private function lire(string $table, callable $projection, string $tri): array
    {
        try {
            return DB::connection('economat')->table($table)->orderBy($tri)->get()
                ->map($projection)->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Grille d'une classe pour l'année de travail. */
    public function index(Request $request)
    {
        $data = $request->validate(['classe' => ['required', 'string', 'max:50']]);
        $annee = ContexteScolaire::annee();

        try {
            $creneaux = DB::connection('economat')->table('T_EMPLOIDUTEMPS')
                ->where('CODECLASSE', $data['classe'])
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->get();
        } catch (Throwable $e) {
            $creneaux = collect();
        }

        return [
            'classe' => $data['classe'],
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'creneaux' => $creneaux->map(fn ($c) => $this->ligne($c))->values(),
        ];
    }

    private function ligne(object $c): array
    {
        return [
            'id' => (int) $c->CODE,
            'jour' => (int) $c->CODEJOUR,
            'heure' => (int) $c->CODEHEURE,
            'classe' => $c->CODECLASSE,
            'matiere' => $c->CODEMATIERE,
            'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $c->CODEMATIERE),
            'salle' => $c->CODESALLE,
            'salle_libelle' => $c->CODESALLE
                ? $this->libelle('T_SALLESCLASSE', 'CODESALLE', 'LIBELLESALLE', $c->CODESALLE)
                : null,
            'enseignant' => $this->enseignant($c->CODECLASSE, $c->CODEMATIERE, $c->ANNEE),
            'annee' => $c->ANNEE,
        ];
    }

    /** Nom de l'enseignant déduit de l'affectation prof ↔ classe ↔ matière. */
    private function enseignant(?string $classe, ?string $matiere, ?string $annee): ?string
    {
        if (! $classe || ! $matiere) {
            return null;
        }

        try {
            $p = DB::connection('economat')->table('T_CORPROFCLASSE as c')
                ->join('T_PROFESSEUR as p', 'p.Code', '=', 'c.CodeProfesseur')
                ->where('c.CodeClasse', $classe)
                ->where('c.CodeMatiere', $matiere)
                ->when($annee, fn ($q) => $q->where('c.ANNEE', $annee))
                ->first(['p.NomProfesseur', 'p.PrenomProfesseur']);
        } catch (Throwable $e) {
            return null;
        }

        $nom = trim(($p->PrenomProfesseur ?? '').' '.($p->NomProfesseur ?? ''));

        return $nom !== '' ? $nom : null;
    }

    private function libelle(string $table, string $cle, string $colonne, ?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        try {
            return (string) (DB::connection('economat')->table($table)
                ->where($cle, $code)->value($colonne) ?: $code);
        } catch (Throwable $e) {
            return $code;
        }
    }

    private function regles(): array
    {
        return [
            'jour' => ['required', 'integer'],
            'heure' => ['required', 'integer'],
            'classe' => ['required', 'string', 'max:50', Rule::exists('economat.T_CLASSE', 'CodeClasse')],
            'matiere' => ['required', 'string', 'max:50', Rule::exists('economat.T_MATIERE', 'CodeMatiere')],
            'salle' => ['nullable', 'string', 'max:50', Rule::exists('economat.T_SALLESCLASSE', 'CODESALLE')],
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->regles());
        $d['annee'] = ContexteScolaire::annee();

        AnneeScolaireGuard::assertModifiable($d['annee'], "L'ajout d'un créneau");
        ConflitsEmploiDuTemps::assertLibre($d);

        $code = $this->t()->inserer([
            'CODEJOUR' => $d['jour'],
            'CODEHEURE' => $d['heure'],
            'CODECLASSE' => $d['classe'],
            'CODEMATIERE' => $d['matiere'],
            'CODESALLE' => $d['salle'] ?? null,
            'ANNEE' => $d['annee'],
        ]);

        return response()->json($this->ligne($this->t()->trouver($code)), 201);
    }

    public function update(Request $request, int $creneau)
    {
        $existant = $this->t()->trouver($creneau);
        abort_unless($existant, 404, 'Créneau introuvable.');

        $d = $request->validate($this->regles());
        $d['annee'] = ContexteScolaire::annee();

        AnneeScolaireGuard::assertModifiable($existant->ANNEE, 'La modification de ce créneau');
        AnneeScolaireGuard::assertModifiable($d['annee'], 'Le rattachement à cette année');
        ConflitsEmploiDuTemps::assertLibre($d, $creneau);

        $this->t()->modifier($creneau, [
            'CODEJOUR' => $d['jour'],
            'CODEHEURE' => $d['heure'],
            'CODECLASSE' => $d['classe'],
            'CODEMATIERE' => $d['matiere'],
            'CODESALLE' => $d['salle'] ?? null,
        ]);

        return response()->json($this->ligne($this->t()->trouver($creneau)));
    }

    /** Vide une case : ici la suppression est réelle (voir l'entête de classe). */
    public function destroy(int $creneau)
    {
        $existant = $this->t()->trouver($creneau);
        abort_unless($existant, 404, 'Créneau introuvable.');

        AnneeScolaireGuard::assertModifiable($existant->ANNEE, 'La suppression de ce créneau');

        DB::connection('economat')->table('T_EMPLOIDUTEMPS')->where('CODE', $creneau)->delete();

        return response()->noContent();
    }
}
