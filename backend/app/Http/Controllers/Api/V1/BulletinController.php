<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use App\Support\ContexteScolaire;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Bulletin PDF d'un élève, à partir des moyennes et notes calculées par ECONOMAT,
 * pour l'année de travail et la session demandée.
 *
 * La « période » d'ECONOMAT est la SESSION (V_NOTECLASSE.CodeSession) : il n'y a pas
 * de table de périodes propre. Les délibérations n'ont pas d'équivalent dans ECONOMAT
 * et ne figurent donc plus au bulletin.
 */
class BulletinController extends Controller
{
    public function __construct(private RapportController $rapports) {}

    public function show(Request $request, Eleve $eleve)
    {
        abort_unless($eleve->classe_code, 422, "Cet élève n'est rattaché à aucune classe.");

        $session = $request->input('session');

        $classement = $this->rapports->moyennes($eleve->classe_code, $session);
        $ligne = $classement->firstWhere('matricule', $eleve->matricule);

        $pdf = Pdf::loadView('pdf.bulletin', [
            'eleve' => $eleve,
            // Même mise en page que les trois autres documents (ImpressionController) :
            // entête NEXORA + établissement du contexte de travail + année.
            'etablissement' => $request->session()->get('etablissement_nom') ?: ($request->user()->Etab ?? null),
            'annee' => ContexteScolaire::annee(),
            'session' => $session,
            'moyennesParMatiere' => $this->rapports->notesParMatiere($eleve->matricule, $session),
            'moyenneGenerale' => $ligne['moyenne'] ?? null,
            'rang' => $ligne['rang'] ?? null,
            'effectif' => $classement->count(),
        ]);

        return $pdf->download('bulletin-'.($eleve->matricule ?: $eleve->getKey()).'.pdf');
    }
}
