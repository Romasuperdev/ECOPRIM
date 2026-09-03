<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Services\ReferentielEcrivain;
use App\Support\DependancesReferentiel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Années scolaires — ECONOMAT.T_ANNEEACADEMIQUE.
 *
 * Création, modification et suppression conditionnelle. Deux points à savoir :
 *
 *  - **La fiche de l'année reste modifiable même quand l'année est clôturée.** Le verrou
 *    « année clôturée » protège les données DANS une année (inscriptions, notes, emploi du
 *    temps) ; s'il s'appliquait aussi à la définition de l'année, une clôture faite par
 *    erreur serait irréversible. Rouvrir une année est donc possible, et c'est voulu.
 *  - **Une seule année active à la fois** : en activer une désactive les autres, sinon le
 *    contexte de travail ne saurait laquelle prendre par défaut.
 */
class AnneeScolaireController extends Controller
{
    /** Ce qui se rattache à une année et interdit donc de la supprimer. */
    private const LIENS = [
        ['table' => 'T_ETUDIANT', 'colonne' => 'AnneeAcad', 'libelle' => 'élève inscrit'],
        ['table' => 'T_CLASSE', 'colonne' => 'ANNEE', 'libelle' => 'classe'],
        ['table' => 'T_NIVEAU', 'colonne' => 'ANNEE', 'libelle' => 'niveau'],
        ['table' => 'T_EMPLOIDUTEMPS', 'colonne' => 'ANNEE', 'libelle' => "créneau d'emploi du temps"],
        ['table' => 'T_CORPROFCLASSE', 'colonne' => 'ANNEE', 'libelle' => 'affectation d’enseignant'],
        ['table' => 'T_PREREQUIS', 'colonne' => 'ANNEE', 'libelle' => 'document paramétré'],
    ];

    private ReferentielEcrivain $ecrivain;

    public function __construct()
    {
        $this->ecrivain = new ReferentielEcrivain('T_ANNEEACADEMIQUE', 'CODE', [
            'code' => 'CodeAnnee',
            'libelle' => 'LibelleAnnee',
            'date_debut' => 'DEBUT',
            'date_fin' => 'FIN',
            'active' => 'Activer',
            'cloturee' => 'ClotureDefinitive',
            'cloture_partielle' => 'CloturePartielle',
        ]);
    }

    private const BOOLEENS = ['active', 'cloturee', 'cloture_partielle'];

    public function index()
    {
        return AnneeScolaire::orderByDesc('DEBUT')->get();
    }

    public function show(AnneeScolaire $anneeScolaire)
    {
        return $anneeScolaire;
    }

    private function regles(bool $creation): array
    {
        return [
            'code' => [$creation ? 'required' : 'sometimes', 'string', 'max:20'],
            'libelle' => [$creation ? 'required' : 'sometimes', 'string', 'max:50'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after:date_debut'],
            'active' => ['nullable', 'boolean'],
            'cloturee' => ['nullable', 'boolean'],
            'cloture_partielle' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));
        $this->assertLibelleLibre($data['libelle']);

        $code = $this->ecrivain->creer($data, self::BOOLEENS);

        if (! empty($data['active'])) {
            $this->rendreSeuleActive($code);
        }

        return response()->json(AnneeScolaire::findOrFail($code), 201);
    }

    public function update(Request $request, AnneeScolaire $anneeScolaire)
    {
        $data = $request->validate($this->regles(false));
        $id = $anneeScolaire->getRawOriginal('CODE');

        if (isset($data['libelle'])) {
            $this->assertLibelleLibre($data['libelle'], $id);
        }

        $this->ecrivain->modifier($id, $data, self::BOOLEENS);

        if (! empty($data['active'])) {
            $this->rendreSeuleActive($id);
        }

        return response()->json(AnneeScolaire::findOrFail($id));
    }

    public function destroy(AnneeScolaire $anneeScolaire)
    {
        // On compare sur les deux écritures de l'année : ECONOMAT référence tantôt le
        // libellé (« 2025-2026 »), tantôt le code (« 2025 »).
        DependancesReferentiel::assertRetraitPossible(
            self::LIENS,
            [$anneeScolaire->getRawOriginal('LibelleAnnee'), $anneeScolaire->getRawOriginal('CodeAnnee')],
            'Cette année scolaire'
        );

        $this->ecrivain->supprimer($anneeScolaire->getRawOriginal('CODE'));

        return response()->noContent();
    }

    /** Le libellé sert de clé métier partout dans l'application : il doit rester unique. */
    private function assertLibelleLibre(string $libelle, $sauf = null): void
    {
        if ($this->ecrivain->codeExiste('libelle', $libelle, $sauf)) {
            throw ValidationException::withMessages([
                'libelle' => ["L'année « {$libelle} » existe déjà."],
            ]);
        }
    }

    private function rendreSeuleActive($code): void
    {
        $this->ecrivain->requete()->where('CODE', '!=', $code)->update(['Activer' => false]);
        $this->ecrivain->requete()->where('CODE', $code)->update(['Activer' => true]);
    }
}
