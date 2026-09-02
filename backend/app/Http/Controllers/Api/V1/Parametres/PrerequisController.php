<?php

namespace App\Http\Controllers\Api\V1\Parametres;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use App\Support\AnneeScolaireGuard;
use Illuminate\Http\Request;
use Throwable;

/**
 * Paramétrage des documents / prérequis élèves — ECONOMAT.dbo.T_PREREQUIS.
 * Catalogue par niveau et par année : chaque ligne est un document ou un prérequis
 * à fournir, éventuellement chiffré (MONTANT), exigible à l'inscription (INSCR)
 * et/ou au titre de la scolarité (SCO).
 * Création + modification ; jamais de suppression (table partagée).
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
                ->when($request->filled('annee'), fn ($q) => $q->where('ANNEE', $request->input('annee')))
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
