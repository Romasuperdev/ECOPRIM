<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Appel\JustificatifService;
use App\Support\PerimetreEtablissement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Application du personnel : les justificatifs d'absence envoyés par les parents, pour
 * les classes de l'établissement de travail. Même décision que celle de l'enseignant.
 */
class JustificatifController extends Controller
{
    public function __construct(private JustificatifService $justificatifs) {}

    public function index(Request $request)
    {
        $statut = $request->input('statut', 'en_attente');

        return [
            'justificatifs' => $this->justificatifs->lister(
                PerimetreEtablissement::codesClasses(),
                $statut === 'tous' ? null : $statut,
            ),
        ];
    }

    public function decider(Request $request, int $justificatif)
    {
        $j = $this->justificatifs->trouver($justificatif);
        abort_unless($j, 404, 'Justificatif introuvable.');
        PerimetreEtablissement::exigerClasse($j->classe_code, 'Justificatif introuvable.');

        $d = $request->validate([
            'decision' => ['required', Rule::in(['justifiee', 'non_justifiee'])],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->justificatifs->forme(
            $this->justificatifs->decider($request->user(), $justificatif, $d['decision'] === 'justifiee', $d['commentaire'] ?? null)
        );
    }

    public function fichier(int $justificatif)
    {
        $j = $this->justificatifs->trouver($justificatif);
        abort_unless($j, 404, 'Justificatif introuvable.');
        PerimetreEtablissement::exigerClasse($j->classe_code, 'Justificatif introuvable.');

        return response()->file($this->justificatifs->cheminFichier($j), array_filter([
            'Content-Type' => $j->fichier_type,
            'Cache-Control' => 'private, no-store',
        ]));
    }
}
