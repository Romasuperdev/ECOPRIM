<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cahier de textes — ECONOMAT.dbo.T_ENTETE_JOURNAL (une semaine, pour une classe) et
 * T_CAHIER_JOURNAL (une ligne par matière enseignée cette semaine-là, avec ce qui a été
 * vu chaque jour).
 *
 * Les matières proposées sont celles réellement affectées à la classe pour l'année
 * (T_CORPROFCLASSE) : on ne consigne pas ce qui n'est pas enseigné là. Une ligne déjà
 * écrite reste modifiable même si l'affectation a depuis changé — la donnée passée
 * n'est jamais invalidée après coup.
 *
 * EXCEPTION ASSUMÉE à la règle « jamais de DELETE », comme pour les emplois du temps,
 * les affectations enseignant-classe et les absences : rien ne dépend en aval d'une
 * semaine ou d'une matière du cahier de textes, et une saisie mal placée n'a pas
 * d'autre voie de correction. Le verrou d'année clôturée s'applique pleinement.
 */
class CahierTextesController extends Controller
{
    private const MOIS = [
        'Septembre', 'Octobre', 'Novembre', 'Décembre', 'Janvier', 'Février',
        'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août',
    ];

    private function t(): EconomatTable
    {
        return EconomatTable::pour('T_ENTETE_JOURNAL', 'CodeEntete');
    }

    private function l(): EconomatTable
    {
        return EconomatTable::pour('T_CAHIER_JOURNAL', 'Num');
    }

    /** Mois proposés, et matières/enseignants affectés à la classe (pour ajouter une ligne). */
    public function referentiels(Request $request)
    {
        $data = $request->validate(['classe' => ['nullable', 'string', 'max:50']]);
        $annee = ContexteScolaire::annee();
        $classe = $data['classe'] ?? null;

        return [
            'mois' => self::MOIS,
            'matieres' => collect($this->matieresAffectees($classe, $annee))
                ->map(fn ($libelle, $code) => ['code' => $code, 'libelle' => $libelle])->values(),
            'enseignants' => collect($this->enseignantsAffectes($classe, $annee))
                ->map(fn ($nom, $code) => ['code' => $code, 'nom' => $nom])->values(),
        ];
    }

    /** @return array<int, string|null> Code professeur => nom. */
    private function enseignantsAffectes(?string $classe, ?string $annee): array
    {
        if (! $classe) {
            return [];
        }

        try {
            $codes = DB::connection('economat')->table('T_CORPROFCLASSE')
                ->where('CodeClasse', $classe)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->whereNotNull('CodeProfesseur')
                ->distinct()->pluck('CodeProfesseur');
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($codes as $code) {
            $out[(int) $code] = $this->nomProfesseur($code);
        }

        return $out;
    }

    /** Les cahiers (semaines) d'une classe, pour l'année de travail. */
    public function index(Request $request)
    {
        $data = $request->validate(['classe' => ['required', 'string', 'max:50']]);
        $annee = ContexteScolaire::annee();

        try {
            $entetes = $this->t()->requete()
                ->where('CodeClasse', $data['classe'])
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'Annee'))
                ->orderByDesc('NumSem')->orderByDesc('CodeEntete')
                ->get();
        } catch (Throwable $e) {
            $entetes = collect();
        }

        return [
            'classe' => $data['classe'],
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'entetes' => $entetes->map(fn ($e) => $this->entete($e))->values(),
        ];
    }

    private function entete(object $e): array
    {
        return [
            'id' => (int) $e->CodeEntete,
            'annee' => $e->Annee,
            'mois' => $e->Mois,
            'semaine' => $e->Semaine,
            'num_sem' => $e->NumSem !== null ? (int) $e->NumSem : null,
            'classe' => $e->CodeClasse,
            'niveau' => $e->CodeNiveau,
            'prof' => $e->Prof ? (int) $e->Prof : null,
            'prof_nom' => $this->nomProfesseur($e->Prof ?? null),
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($e->Annee),
            'lignes' => $this->lignesDe($e),
        ];
    }

    /** Une ligne par matière affectée à la classe, plus les lignes orphelines déjà écrites. */
    private function lignesDe(object $e): array
    {
        $matieres = $this->matieresAffectees($e->CodeClasse, $e->Annee);

        $existantes = collect();
        try {
            $existantes = $this->l()->requete()->where('CodeEntete', $e->CodeEntete)->get()
                ->keyBy(fn ($l) => trim((string) $l->Matiere));
        } catch (Throwable $ex) {
            // laisse $existantes vide
        }

        $lignes = [];
        foreach ($matieres as $code => $libelle) {
            $lignes[] = $this->ligneVue($code, $libelle, $existantes->get($code));
            $existantes->forget($code);
        }

        // Matière écrite mais plus affectée à la classe (ou sans matière) : on ne perd rien.
        foreach ($existantes as $code => $ligne) {
            $lignes[] = $this->ligneVue($code ?: null, $code !== '' ? $this->libelleMatiere($code) : null, $ligne);
        }

        return $lignes;
    }

    private function ligneVue(?string $matiere, ?string $libelle, ?object $ligne): array
    {
        return [
            'id' => $ligne ? (int) $ligne->Num : null,
            'matiere' => $matiere,
            'matiere_libelle' => $libelle ?: $matiere,
            'lundi' => $ligne->Lundi ?? '',
            'mardi' => $ligne->Mardi ?? '',
            'mercredi' => $ligne->Mercredi ?? '',
            'jeudi' => $ligne->Jeudi ?? '',
            'vendredi' => $ligne->Vendredi ?? '',
        ];
    }

    /** @return array<string, string|null> Code matière => libellé. */
    private function matieresAffectees(?string $classe, ?string $annee): array
    {
        if (! $classe) {
            return [];
        }

        try {
            $codes = DB::connection('economat')->table('T_CORPROFCLASSE')
                ->where('CodeClasse', $classe)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->whereNotNull('CodeMatiere')
                ->distinct()->pluck('CodeMatiere');
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code !== '') {
                $out[$code] = $this->libelleMatiere($code);
            }
        }

        return $out;
    }

    private function libelleMatiere(string $code): ?string
    {
        try {
            return DB::connection('economat')->table('T_MATIERE')
                ->where('CodeMatiere', $code)->value('LibelleMatiere') ?: $code;
        } catch (Throwable $e) {
            return $code;
        }
    }

    private function nomProfesseur($code): ?string
    {
        if (! $code) {
            return null;
        }
        try {
            $p = DB::connection('economat')->table('T_PROFESSEUR')->where('Code', (int) $code)->first();
        } catch (Throwable $e) {
            return null;
        }

        return $p ? (trim(($p->PrenomProfesseur ?? '').' '.($p->NomProfesseur ?? '')) ?: (string) $code) : null;
    }

    private function reglesEntete(): array
    {
        return [
            'mois' => ['required', 'string', 'max:50'],
            'semaine' => ['required', 'string', 'max:50'],
            'prof' => ['nullable', 'integer', Rule::exists('economat.T_PROFESSEUR', 'Code')],
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->reglesEntete() + [
            'classe' => ['required', 'string', 'max:50', Rule::exists('economat.T_CLASSE', 'CodeClasse')],
        ]);
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La création d'un cahier de textes");
        $this->assertPasDeDoublon($d['classe'], $d['mois'], $d['semaine'], $annee);

        $code = $this->t()->inserer([
            'Annee' => $annee,
            'Mois' => $d['mois'],
            'Semaine' => $d['semaine'],
            'CodeClasse' => $d['classe'],
            'CodeNiveau' => $this->niveauDe($d['classe']),
            'Prof' => $d['prof'] ?? null,
            'NumSem' => $this->prochainNumSem($d['classe'], $d['mois'], $annee),
        ]);

        return response()->json($this->entete($this->t()->trouver($code)), 201);
    }

    private function niveauDe(string $classe): ?string
    {
        try {
            return DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', $classe)->value('CodN');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function prochainNumSem(string $classe, string $mois, ?string $annee): int
    {
        try {
            return 1 + (int) $this->t()->requete()
                ->where('CodeClasse', $classe)->where('Mois', $mois)
                ->when($annee, fn ($q) => $q->where('Annee', $annee))
                ->count();
        } catch (Throwable $e) {
            return 1;
        }
    }

    private function assertPasDeDoublon(string $classe, string $mois, string $semaine, ?string $annee, ?int $sauf = null): void
    {
        try {
            $existe = $this->t()->requete()
                ->where('CodeClasse', $classe)->where('Mois', $mois)->where('Semaine', $semaine)
                ->when($annee, fn ($q) => $q->where('Annee', $annee))
                ->when($sauf, fn ($q) => $q->where('CodeEntete', '!=', $sauf))
                ->exists();
        } catch (Throwable $e) {
            return;
        }

        if ($existe) {
            throw ValidationException::withMessages([
                'semaine' => ['Un cahier de textes existe déjà pour cette classe, ce mois et cette semaine.'],
            ]);
        }
    }

    public function update(Request $request, int $entete)
    {
        $existant = $this->t()->trouver($entete);
        abort_unless($existant, 404, 'Cahier de textes introuvable.');

        $d = $request->validate($this->reglesEntete());
        AnneeScolaireGuard::assertModifiable($existant->Annee, 'La modification de ce cahier de textes');
        $this->assertPasDeDoublon($existant->CodeClasse, $d['mois'], $d['semaine'], $existant->Annee, $entete);

        $this->t()->modifier($entete, [
            'Mois' => $d['mois'],
            'Semaine' => $d['semaine'],
            'Prof' => $d['prof'] ?? null,
        ]);

        return response()->json($this->entete($this->t()->trouver($entete)));
    }

    /** Supprime la semaine et toutes ses lignes — voir l'exception assumée en tête de fichier. */
    public function destroy(int $entete)
    {
        $existant = $this->t()->trouver($entete);
        abort_unless($existant, 404, 'Cahier de textes introuvable.');

        AnneeScolaireGuard::assertModifiable($existant->Annee, 'La suppression de ce cahier de textes');

        DB::connection('economat')->table('T_CAHIER_JOURNAL')->where('CodeEntete', $entete)->delete();
        DB::connection('economat')->table('T_ENTETE_JOURNAL')->where('CodeEntete', $entete)->delete();

        return response()->noContent();
    }

    /** Enregistre (crée ou met à jour) ce qui a été vu, jour par jour, pour une matière. */
    public function enregistrerLigne(Request $request, int $entete)
    {
        $existant = $this->t()->trouver($entete);
        abort_unless($existant, 404, 'Cahier de textes introuvable.');

        $d = $request->validate([
            'matiere' => ['required', 'string', 'max:50', Rule::exists('economat.T_MATIERE', 'CodeMatiere')],
            'lundi' => ['nullable', 'string', 'max:50'],
            'mardi' => ['nullable', 'string', 'max:50'],
            'mercredi' => ['nullable', 'string', 'max:50'],
            'jeudi' => ['nullable', 'string', 'max:50'],
            'vendredi' => ['nullable', 'string', 'max:50'],
        ]);
        AnneeScolaireGuard::assertModifiable($existant->Annee, 'La saisie du cahier de textes');

        $jours = [
            'Lundi' => $d['lundi'] ?? '', 'Mardi' => $d['mardi'] ?? '', 'Mercredi' => $d['mercredi'] ?? '',
            'Jeudi' => $d['jeudi'] ?? '', 'Vendredi' => $d['vendredi'] ?? '',
        ];

        $ligne = $this->l()->requete()->where('CodeEntete', $entete)->where('Matiere', $d['matiere'])->first();

        if ($ligne) {
            $this->l()->modifier($ligne->Num, $jours);
            $num = $ligne->Num;
        } else {
            // Nouvelle ligne : la matière doit être réellement enseignée dans cette classe.
            // Une ligne déjà écrite reste modifiable même si l'affectation a changé depuis.
            $this->assertMatiereAffectee($existant->CodeClasse, $d['matiere'], $existant->Annee);
            $num = $this->l()->inserer($jours + [
                'Matiere' => $d['matiere'], 'CodeEntete' => $entete, 'NumSem' => $existant->NumSem,
            ]);
        }

        return response()->json(
            $this->ligneVue($d['matiere'], $this->libelleMatiere($d['matiere']), $this->l()->trouver($num))
        );
    }

    private function assertMatiereAffectee(?string $classe, string $matiere, ?string $annee): void
    {
        try {
            $existe = DB::connection('economat')->table('T_CORPROFCLASSE')
                ->where('CodeClasse', $classe)->where('CodeMatiere', $matiere)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->exists();
        } catch (Throwable $e) {
            return; // référentiel injoignable : on ne bloque pas une saisie sur une incertitude technique
        }

        if (! $existe) {
            throw ValidationException::withMessages([
                'matiere' => ["Cette matière n'est pas affectée à cette classe pour cette année."],
            ]);
        }
    }

    /** Retire une matière du cahier — la ligne, pas seulement son contenu. */
    public function supprimerLigne(int $entete, string $matiere)
    {
        $existant = $this->t()->trouver($entete);
        abort_unless($existant, 404, 'Cahier de textes introuvable.');

        AnneeScolaireGuard::assertModifiable($existant->Annee, 'Le retrait de cette matière');

        DB::connection('economat')->table('T_CAHIER_JOURNAL')
            ->where('CodeEntete', $entete)->where('Matiere', $matiere)->delete();

        return response()->noContent();
    }
}
