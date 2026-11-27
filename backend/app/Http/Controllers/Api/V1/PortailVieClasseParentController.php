<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Devoir;
use App\Models\Eleve;
use App\Services\Classe\VieDeClasse;
use App\Support\ContexteScolaire;
use App\Support\PiecesJointes;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Espace Parent : devoirs (avec leurs photos) et annonces de la classe de son enfant.
 * Lecture seule, et seulement pour une classe où il a un enfant rattaché.
 */
class PortailVieClasseParentController extends Controller
{
    public function __construct(private VieDeClasse $vie) {}

    /** @return string[] classes de mes enfants, année de travail */
    private function classesDeMesEnfants(Request $request): array
    {
        $matricules = $request->user()->enfantsMatricules();
        if ($matricules === []) {
            return [];
        }

        return Eleve::whereIn('Matricule', $matricules)
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->get()
            ->map(fn ($e) => trim((string) $e->getRawOriginal('CodeClasse')))
            ->filter()->unique()->values()->all();
    }

    private function classeDeLEnfant(Request $request, string $matricule): string
    {
        if (! in_array($matricule, $request->user()->enfantsMatricules(), true)) {
            throw new HttpException(403, 'Cet élève ne fait pas partie de vos enfants rattachés.');
        }
        $eleve = Eleve::where('Matricule', $matricule)
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->first();
        abort_unless($eleve, 404, "Cet élève n'est pas inscrit pour l'année en cours.");

        return trim((string) $eleve->getRawOriginal('CodeClasse'));
    }

    private function assertClasseDUnEnfant(Request $request, ?string $classe): void
    {
        if (! $classe || ! in_array($classe, $this->classesDeMesEnfants($request), true)) {
            throw new HttpException(403, "Aucun de vos enfants n'est dans cette classe.");
        }
    }

    /** Devoirs et annonces de la classe de l'enfant. */
    public function vieDeClasse(Request $request, string $matricule)
    {
        $classe = $this->classeDeLEnfant($request, $matricule);

        return [
            'devoirs' => $this->vie->devoirs([$classe]),
            'annonces' => $this->vie->annonces([$classe]),
        ];
    }

    public function piece(Request $request, int $devoir, int $piece)
    {
        $d = Devoir::find($devoir);
        abort_unless($d, 404, 'Devoir introuvable.');
        $this->assertClasseDUnEnfant($request, $d->classe_code);
        $p = $this->vie->piece($devoir, $piece);

        return PiecesJointes::servir(VieDeClasse::DOSSIER_DEVOIRS, $p->fichier, $p->fichier_type);
    }

    public function photoAnnonce(Request $request, int $annonce)
    {
        $a = $this->vie->annonce($annonce);
        $this->assertClasseDUnEnfant($request, $a->classe_code);

        return PiecesJointes::servir(VieDeClasse::DOSSIER_ANNONCES, $a->fichier, $a->fichier_type);
    }
}
