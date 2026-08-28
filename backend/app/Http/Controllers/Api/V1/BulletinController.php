<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deliberation;
use App\Models\Eleve;
use App\Models\Periode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class BulletinController extends Controller
{
    public function __construct(private RapportController $rapports)
    {
    }

    public function show(Request $request, Eleve $eleve)
    {
        abort_unless($eleve->classe_id, 422, "Cet élève n'est rattaché à aucune classe.");

        $periodeId = $request->input('periode_id');
        $periode = $periodeId ? Periode::find($periodeId) : null;

        $rapportClasse = $this->rapports->calculerMoyennes($eleve->classe, $periodeId);
        $ligne = collect($rapportClasse['classement'])->firstWhere('eleve_id', $eleve->id);

        $deliberation = Deliberation::where('eleve_id', $eleve->id)
            ->when($periodeId, fn ($q) => $q->whereHas('conseilClasse', fn ($q) => $q->where('periode_id', $periodeId)))
            ->latest()
            ->first();

        $pdf = Pdf::loadView('pdf.bulletin', [
            'eleve' => $eleve,
            'periode' => $periode,
            'moyennesParMatiere' => $ligne['moyennes_par_matiere'] ?? collect(),
            'moyenneGenerale' => $ligne['moyenne'] ?? null,
            'rang' => $ligne['rang'] ?? null,
            'deliberation' => $deliberation,
        ]);

        return $pdf->download("bulletin-{$eleve->matricule}.pdf");
    }
}
