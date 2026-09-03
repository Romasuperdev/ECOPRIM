<?php

namespace App\Http\Controllers\Api\V1\Parametres;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Paramétrage des documents / prérequis élèves — ECONOMAT.dbo.T_PREREQUIS.
 * Catalogue par niveau et par année : chaque ligne est un document ou un prérequis
 * à fournir, éventuellement chiffré (MONTANT), exigible à l'inscription (INSCR)
 * et/ou au titre de la scolarité (SCO).
 * Création, modification, et retrait conditionnel : une ligne de paramétrage ne peut pas
 * disparaître si des élèves se sont déjà vu enregistrer ce document (lignes portant un
 * CODEELEVE), sinon leur dossier référencerait un document qui n'existe plus.
 */
class PrerequisController extends Controller
{
    private function t(): EconomatTable
    {
        return EconomatTable::pour('T_PREREQUIS', 'CODES');
    }

    public function index(Request $request)
    {
        try {
            $lignes = $this->t()->requete()
                // Filtre explicite, sinon l'année de travail choisie dans l'en-tête.
                ->when($request->filled('annee'),
                    fn ($q) => $q->where('ANNEE', $request->input('annee')),
                    fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->when($request->filled('niveau'), fn ($q) => $q->where('CODENIVEAU', $request->input('niveau')))
                ->when($request->filled('q'), fn ($q) => $q->where('LIBELLE', 'like', '%'.$request->input('q').'%'))
                ->orderBy('ANNEE', 'desc')->orderBy('CODENIVEAU')->orderBy('LIBELLE')
                ->get();
        } catch (Throwable $e) {
            $lignes = collect();
        }

        return ['data' => $lignes->map(fn ($l) => $this->ligne($l))->values()];
    }

    private function ligne(object $l): array
    {
        return [
            'id' => $l->CODES,
            'code' => $l->CODE,
            'libelle' => $l->LIBELLE,
            'type' => $l->TYPE,
            'niveau' => $l->CODENIVEAU,
            'annee' => $l->ANNEE,
            'montant' => $l->MONTANT !== null ? (float) $l->MONTANT : null,
            'a_inscription' => (bool) $l->INSCR,
            'a_scolarite' => (bool) $l->SCO,
            'quantite' => $l->QUANTITE,
            'societe_code' => $l->CODESOCIETE,
        ];
    }

    /** Largeurs réelles de T_PREREQUIS. */
    private function regles(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:200'],
            'type' => ['nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:50'],
            'niveau' => ['nullable', 'string', 'max:50'],
            'annee' => ['required', 'string', 'max:50'],
            'montant' => ['nullable', 'numeric', 'min:0'],
            'quantite' => ['nullable', 'integer', 'min:0'],
            'a_inscription' => ['nullable', 'boolean'],
            'a_scolarite' => ['nullable', 'boolean'],
            'societe_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function colonnes(array $d): array
    {
        $map = [
            'LIBELLE' => 'libelle', 'TYPE' => 'type', 'CODE' => 'code',
            'CODENIVEAU' => 'niveau', 'ANNEE' => 'annee', 'MONTANT' => 'montant',
            'QUANTITE' => 'quantite', 'CODESOCIETE' => 'societe_code',
        ];

        $ligne = [];
        foreach ($map as $colonne => $cle) {
            if (array_key_exists($cle, $d)) {
                $ligne[$colonne] = $d[$cle];
            }
        }
        if (array_key_exists('a_inscription', $d)) {
            $ligne['INSCR'] = ! empty($d['a_inscription']) ? 1 : 0;
        }
        if (array_key_exists('a_scolarite', $d)) {
            $ligne['SCO'] = ! empty($d['a_scolarite']) ? 1 : 0;
        }

        return $ligne;
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles());
        AnneeScolaireGuard::assertModifiable($data['annee'] ?? null, "L'ajout d'un document");

        $id = $this->t()->inserer($this->colonnes($data));

        return response()->json($this->ligne($this->t()->trouver($id)), 201);
    }

    /**
     * Retrait d'une ligne de paramétrage.
     *
     * Refusé si des élèves ont déjà ce document à leur dossier : T_PREREQUIS sert à la
     * fois de catalogue (CODEELEVE vide) et de suivi par élève (CODEELEVE renseigné).
     */
    public function destroy($prerequis)
    {
        $existant = $this->t()->trouver($prerequis);
        abort_unless($existant, 404, 'Prérequis introuvable.');

        AnneeScolaireGuard::assertModifiable($existant->ANNEE ?? null, 'Le retrait de ce document');

        $rattaches = $this->elevesRattaches($existant);
        if ($rattaches > 0) {
            throw new HttpException(409,
                'Ce document ne peut pas être retiré : il est déjà enregistré au dossier de '
                .$rattaches.' élève'.($rattaches > 1 ? 's' : '')
                .". Retirez-le d'abord de ces dossiers, ou laissez la ligne en place.");
        }

        $this->t()->requete()->where('CODES', $prerequis)->delete();

        return response()->noContent();
    }

    /** Lignes de suivi par élève portant le même document, sur la même année. */
    private function elevesRattaches(object $ligne): int
    {
        try {
            return (int) $this->t()->requete()
                ->whereNotNull('CODEELEVE')
                ->where('CODEELEVE', '!=', '')
                ->when($ligne->CODE ?? null, fn ($q, $c) => $q->where('CODE', $c))
                ->when($ligne->ANNEE ?? null, fn ($q, $a) => $q->where('ANNEE', $a))
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function update(Request $request, $prerequis)
    {
        $existant = $this->t()->trouver($prerequis);
        abort_unless($existant, 404, 'Prérequis introuvable.');

        $data = $request->validate($this->regles());
        AnneeScolaireGuard::assertModifiable($existant->ANNEE ?? null, 'La modification de ce document');
        AnneeScolaireGuard::assertModifiable($data['annee'] ?? null, 'Le rattachement à cette année');

        $this->t()->modifier($prerequis, $this->colonnes($data));

        return response()->json($this->ligne($this->t()->trouver($prerequis)));
    }
}
