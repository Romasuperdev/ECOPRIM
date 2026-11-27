<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Appel\AppelService;
use App\Services\Appel\JustificatifService;
use App\Support\PorteeEnseignant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Espace Enseignant : l'appel de ses classes et les justificatifs reçus des parents.
 * Chaque action vérifie d'abord que la classe visée est bien l'une des siennes.
 */
class PortailAppelController extends Controller
{
    public function __construct(
        private AppelService $appels,
        private JustificatifService $justificatifs,
    ) {}

    private function mesClasses(Request $request): array
    {
        return PorteeEnseignant::classes($request->user());
    }

    private function assertMaClasse(Request $request, ?string $classe): void
    {
        if (! $classe || ! in_array($classe, $this->mesClasses($request), true)) {
            throw new HttpException(403, "Cette classe n'est pas parmi celles que vous enseignez.");
        }
    }

    private function criteres(Request $request): array
    {
        return $request->validate([
            'classe' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'creneau' => ['required', Rule::in(array_keys(AppelService::CRENEAUX))],
        ], [
            'date.before_or_equal' => "On ne fait pas l'appel d'un jour à venir.",
        ]);
    }

    public function feuille(Request $request)
    {
        $c = $this->criteres($request);
        $this->assertMaClasse($request, $c['classe']);

        return $this->appels->feuille($c['classe'], $c['date'], $c['creneau']);
    }

    public function enregistrer(Request $request)
    {
        $c = $this->criteres($request);
        $donnees = $request->validate([
            'absents' => ['present', 'array', 'max:200'],
            'absents.*' => ['string', 'max:50'],
            'retards' => ['present', 'array', 'max:200'],
            'retards.*' => ['string', 'max:50'],
            'envoi' => ['nullable', 'string', 'max:64'],
        ]);
        $this->assertMaClasse($request, $c['classe']);

        return $this->appels->enregistrer(
            $request->user(), $c['classe'], $c['date'], $c['creneau'],
            $donnees['absents'], $donnees['retards'], $donnees['envoi'] ?? null,
        );
    }

    public function justificatifs(Request $request)
    {
        $statut = $request->input('statut', 'en_attente');

        return [
            'justificatifs' => $this->justificatifs->lister(
                $this->mesClasses($request),
                $statut === 'tous' ? null : $statut,
            ),
        ];
    }

    public function decider(Request $request, int $justificatif)
    {
        $j = $this->justificatifs->trouver($justificatif);
        abort_unless($j, 404, 'Justificatif introuvable.');
        $this->assertMaClasse($request, $j->classe_code);

        $d = $request->validate([
            'decision' => ['required', Rule::in(['justifiee', 'non_justifiee'])],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->justificatifs->forme(
            $this->justificatifs->decider($request->user(), $justificatif, $d['decision'] === 'justifiee', $d['commentaire'] ?? null)
        );
    }

    public function fichier(Request $request, int $justificatif)
    {
        $j = $this->justificatifs->trouver($justificatif);
        abort_unless($j, 404, 'Justificatif introuvable.');
        $this->assertMaClasse($request, $j->classe_code);

        return response()->file($this->justificatifs->cheminFichier($j), array_filter([
            'Content-Type' => $j->fichier_type,
            'Cache-Control' => 'private, no-store',
        ]));
    }
}
