<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AbsenceEcrivain;
use App\Services\Appel\AppelService;
use App\Services\Appel\JustificatifService;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Espace Parent : l'assiduité de son enfant (absences, retards) et l'envoi d'un
 * justificatif. Seulement pour les enfants rattachés à son compte.
 */
class PortailAssiduiteController extends Controller
{
    public function __construct(
        private AbsenceEcrivain $absences,
        private JustificatifService $justificatifs,
    ) {}

    private function assertMonEnfant(Request $request, string $matricule): void
    {
        if (! in_array($matricule, $request->user()->enfantsMatricules(), true)) {
            throw new HttpException(403, 'Cet élève ne fait pas partie de vos enfants rattachés.');
        }
    }

    /** Absences (avec l'état de leur justificatif) et retards de l'année de travail. */
    public function assiduite(Request $request, string $matricule)
    {
        $this->assertMonEnfant($request, $matricule);

        $absences = ContexteScolaire::appliquer(
            $this->absences->requete()->where('Matricule', $matricule),
            'AnneeCour'
        )->orderByDesc('Date')->get();

        $justificatifs = DB::connection('ecoprim')->table('absence_justificatifs')
            ->whereIn('absence_code', $absences->pluck('Code')->all() ?: [0])
            ->where('statut', '!=', 'annule')
            ->orderByDesc('id')
            ->get()
            ->unique('absence_code')
            ->keyBy('absence_code');

        $retards = ContexteScolaire::appliquer(
            DB::connection('ecoprim')->table('appel_lignes as l')
                ->join('appels as a', 'a.id', '=', 'l.appel_id')
                ->where('l.matricule', $matricule)
                ->where('l.statut', 'retard'),
            'a.annee'
        )->orderByDesc('a.date')->get(['a.date', 'a.creneau']);

        return [
            'absences' => $absences->map(function ($a) use ($justificatifs) {
                $j = $justificatifs[(int) $a->Code] ?? null;

                return [
                    'code' => (int) $a->Code,
                    'date' => $a->Date ? Carbon::parse($a->Date)->toDateString() : null,
                    'heure' => $a->Heure,
                    'motif' => $a->Cause,
                    'justifiee' => (bool) $a->Justifier,
                    'justificatif' => $j ? $this->justificatifs->forme($j) : null,
                ];
            })->values(),
            'retards' => $retards->map(fn ($r) => [
                'date' => Carbon::parse($r->date)->toDateString(),
                'creneau' => AppelService::CRENEAUX[$r->creneau] ?? $r->creneau,
            ])->values(),
        ];
    }

    public function justifier(Request $request, string $matricule, int $absence)
    {
        $this->assertMonEnfant($request, $matricule);

        $d = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
            'fichier' => ['nullable', 'file', 'max:'.JustificatifService::TAILLE_MAX_KO,
                'extensions:'.implode(',', JustificatifService::EXTENSIONS)],
        ], [
            'fichier.max' => 'Le fichier dépasse '.(JustificatifService::TAILLE_MAX_KO / 1024).' Mo.',
            'fichier.extensions' => 'Envoyez une photo (JPG, PNG, HEIC) ou un PDF.',
        ]);

        $j = $this->justificatifs->deposer(
            $request->user(), $matricule, $absence, $d['message'] ?? null, $request->file('fichier'),
        );

        return response()->json($this->justificatifs->forme($j), 201);
    }

    public function fichier(Request $request, int $justificatif)
    {
        $j = $this->justificatifs->trouver($justificatif);
        abort_unless($j, 404, 'Justificatif introuvable.');
        $this->assertMonEnfant($request, $j->matricule);

        return response()->file($this->justificatifs->cheminFichier($j), array_filter([
            'Content-Type' => $j->fichier_type,
            'Cache-Control' => 'private, no-store',
        ]));
    }
}
