<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Services\ReferentielEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\DependancesReferentiel;
use App\Support\PerimetreEtablissement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Classes — ECONOMAT.T_CLASSE.
 *
 * Une classe appartient à une année : elle est créée dans l'année de travail, et une année
 * clôturée reste en consultation seule. La suppression est refusée dès qu'un élève, un
 * créneau, une affectation, une absence ou une note s'y rattache — supprimer une classe
 * qui compte des élèves les laisserait rattachés à un code inexistant, et ECONOMAT ne
 * porte aucune clé étrangère pour l'empêcher.
 *
 * Pas de série : c'est une notion de lycée, sans objet au primaire. La colonne `CodeSerie`
 * existe toujours dans T_CLASSE et ses valeurs sont préservées — NEXORA ne l'écrit plus.
 */
class ClasseController extends Controller
{
    private const LIENS = [
        ['table' => 'T_ETUDIANT', 'colonne' => 'CodeClasse', 'libelle' => 'élève'],
        ['table' => 'T_EMPLOIDUTEMPS', 'colonne' => 'CODECLASSE', 'libelle' => "créneau d'emploi du temps"],
        ['table' => 'T_CORPROFCLASSE', 'colonne' => 'CodeClasse', 'libelle' => 'affectation d’enseignant'],
        ['table' => 'T_ABSENCEELEVE', 'colonne' => 'CodeClasse', 'libelle' => 'absence'],
        ['table' => 'V_NOTECLASSE', 'colonne' => 'CodeClasse', 'libelle' => 'note'],
    ];

    private ReferentielEcrivain $ecrivain;

    public function __construct()
    {
        $this->ecrivain = new ReferentielEcrivain('T_CLASSE', 'num', [
            'code' => 'CodeClasse',
            'nom' => 'LibelleClasse',
            'niveau_code' => 'CodN',
            'annee' => 'ANNEE',
            // Écrit à la création seulement : une classe appartient à l'établissement où
            // elle a été ouverte (voir etablissementDeCreation()).
            'etablissement' => 'CODEETABLISSEMENT',
        ]);
    }

    public function index(Request $request)
    {
        return Classe::with('niveau')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
            // Chaque établissement ne voit que SES classes (voir PerimetreEtablissement).
            ->tap(fn ($q) => PerimetreEtablissement::appliquerParClasse($q))
            ->when($request->filled('q'), fn ($query) => $query->where('LibelleClasse', 'like', "%{$request->input('q')}%"))
            ->orderBy('LibelleClasse')
            ->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Classe $classe)
    {
        PerimetreEtablissement::exigerClasse($classe->getRawOriginal('CodeClasse'));

        return $classe->load('niveau');
    }

    private function regles(bool $creation): array
    {
        return [
            'code' => [$creation ? 'required' : 'sometimes', 'string', 'max:20'],
            'nom' => [$creation ? 'required' : 'sometimes', 'string', 'max:60'],
            'niveau_code' => [$creation ? 'required' : 'sometimes', 'string', 'max:20'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La création d'une classe");
        $this->assertCodeLibre($data['code'], $annee);
        $this->assertNiveauConnu($data['niveau_code'], $annee);

        $num = $this->ecrivain->creer($data + [
            'annee' => $annee,
            'etablissement' => $this->etablissementDeCreation(),
        ]);

        return response()->json(Classe::where('num', $num)->firstOrFail(), 201);
    }

    /**
     * Établissement auquel rattacher une nouvelle classe : celui sur lequel on travaille.
     *
     * Chaque établissement n'affiche que SES classes : une classe créée sans
     * établissement disparaîtrait de la liste aussitôt créée. On la rattache donc à
     * l'établissement visible dans l'en-tête, et l'on refuse si ce choix n'est pas
     * déterminé — plusieurs établissements possibles, ou aucun.
     */
    private function etablissementDeCreation(): string
    {
        $visibles = PerimetreEtablissement::etablissementsVisibles();

        if ($visibles === null || count($visibles) !== 1) {
            throw ValidationException::withMessages([
                'code' => [$visibles === []
                    ? "Cette société n'a aucun établissement : impossible d'y ouvrir une classe."
                    : "Choisissez d'abord, dans l'en-tête, l'établissement où ouvrir cette classe."],
            ]);
        }

        return $visibles[0];
    }

    public function update(Request $request, Classe $classe)
    {
        PerimetreEtablissement::exigerClasse($classe->getRawOriginal('CodeClasse'));
        $data = $request->validate($this->regles(false));
        $num = $classe->getRawOriginal('num');
        $annee = $classe->getRawOriginal('ANNEE');
        AnneeScolaireGuard::assertModifiable($annee, 'La modification de cette classe');

        if (isset($data['code'])) {
            $this->assertCodeLibre($data['code'], $annee, $num);
        }
        if (isset($data['niveau_code'])) {
            $this->assertNiveauConnu($data['niveau_code'], $annee);
        }

        $this->ecrivain->modifier($num, $data);

        return response()->json(Classe::where('num', $num)->firstOrFail());
    }

    public function destroy(Classe $classe)
    {
        PerimetreEtablissement::exigerClasse($classe->getRawOriginal('CodeClasse'));
        AnneeScolaireGuard::assertModifiable($classe->getRawOriginal('ANNEE'), 'La suppression de cette classe');
        DependancesReferentiel::assertRetraitPossible(
            self::LIENS, $classe->getRawOriginal('CodeClasse'), 'Cette classe'
        );

        $this->ecrivain->supprimer($classe->getRawOriginal('num'));

        return response()->noContent();
    }

    /** Un code de classe est unique DANS une année : il se réutilise l'année suivante. */
    private function assertCodeLibre(string $code, ?string $annee, $sauf = null): void
    {
        $existe = $this->ecrivain->requete()
            ->where('CodeClasse', $code)
            ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
            ->when($sauf !== null, fn ($q) => $q->where('num', '!=', $sauf))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'code' => ["La classe « {$code} » existe déjà pour cette année."],
            ]);
        }
    }

    private function assertNiveauConnu(string $niveau, ?string $annee): void
    {
        try {
            $existe = DB::connection('economat')->table('T_NIVEAU')
                ->where('CodeNiveau', $niveau)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->exists();
        } catch (Throwable $e) {
            return;
        }

        if (! $existe) {
            throw ValidationException::withMessages([
                'niveau_code' => ["Le niveau « {$niveau} » n'existe pas pour cette année."],
            ]);
        }
    }
}
