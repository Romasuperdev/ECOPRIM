<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use App\Models\Enseignant;
use App\Services\PhotoEleveStockage;
use App\Support\ContexteScolaire;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Documents imprimables (PDF), tous bâtis sur la mise en page commune
 * resources/views/pdf/layout.blade.php : entête NEXORA, établissement du contexte de
 * travail, année, et pied de page daté.
 *
 * Tout est en LECTURE : imprimer ne modifie jamais ECONOMAT, une année clôturée
 * s'imprime donc normalement.
 */
class ImpressionController extends Controller
{
    public function __construct(private PhotoEleveStockage $photos) {}

    /** Variables communes à toutes les vues. */
    private function commun(Request $request): array
    {
        return [
            'etablissement' => $request->session()->get('etablissement_nom')
                ?: ($request->user()->Etab ?? null),
            'annee' => ContexteScolaire::annee(),
            // Affiche « Non renseigné » plutôt qu'une case vide ambiguë sur papier.
            'v' => fn ($valeur) => (trim((string) $valeur) !== '')
                ? e($valeur)
                : '<span class="vide">Non renseigné</span>',
        ];
    }

    private function nomFichier(string $prefixe, ?string $suffixe): string
    {
        $propre = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $suffixe) ?: 'document';

        return trim($prefixe.'-'.$propre, '-').'.pdf';
    }

    /** Fiche complète d'un élève, photo incluse si elle existe. */
    public function eleve(Request $request, Eleve $eleve)
    {
        $pdf = Pdf::loadView('pdf.eleve', $this->commun($request) + [
            'eleve' => $eleve,
            'classe' => $this->libelleClasse($eleve->classe_code),
            'photo' => $this->photoEnBase64($eleve),
        ]);

        return $pdf->download($this->nomFichier('fiche-eleve', $eleve->matricule ?: $eleve->getKey()));
    }

    /** Fiche complète d'un enseignant : état civil, coordonnées, carrière, administration. */
    public function enseignant(Request $request, Enseignant $enseignant)
    {
        $pdf = Pdf::loadView('pdf.enseignant', $this->commun($request) + [
            'enseignant' => $enseignant,
        ]);

        return $pdf->download($this->nomFichier('fiche-enseignant', $enseignant->matricule ?: $enseignant->getKey()));
    }

    /** Grille d'une classe, en paysage : une colonne par jour. */
    public function emploiDuTemps(Request $request, EmploiDuTempsController $emplois)
    {
        $data = $request->validate(['classe' => ['required', 'string', 'max:50']]);

        $referentiels = $emplois->referentiels();
        $grille = $emplois->index($request);
        $creneaux = collect($grille['creneaux']);

        $pdf = Pdf::loadView('pdf.emploi-du-temps', $this->commun($request) + [
            'classeLibelle' => $this->libelleClasse($data['classe']) ?: $data['classe'],
            'jours' => $referentiels['jours'],
            'heures' => $referentiels['heures'],
            'creneau' => fn ($jour, $heure) => $creneaux
                ->first(fn ($c) => $c['jour'] === $jour && $c['heure'] === $heure),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->nomFichier('emploi-du-temps', $data['classe']));
    }

    /** Liste nominative des élèves d'une classe, pour l'appel ou l'affichage. */
    public function listeClasse(Request $request)
    {
        $data = $request->validate(['classe' => ['required', 'string', 'max:50']]);

        $eleves = Eleve::where('CodeClasse', $data['classe'])
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->orderBy('Nom')->orderBy('Prenom')
            ->get();

        $pdf = Pdf::loadView('pdf.liste-classe', $this->commun($request) + [
            'classeLibelle' => $this->libelleClasse($data['classe']) ?: $data['classe'],
            'eleves' => $eleves,
        ]);

        return $pdf->download($this->nomFichier('liste-classe', $data['classe']));
    }

    private function libelleClasse(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        try {
            return DB::connection('economat')->table('T_CLASSE')
                ->where('CodeClasse', $code)->value('LibelleClasse') ?: $code;
        } catch (Throwable $e) {
            return $code;
        }
    }

    /**
     * La photo vit dans un dossier partagé, hors du serveur web : dompdf ne peut pas
     * la charger par URL. On l'incorpore donc en base64.
     */
    private function photoEnBase64(Eleve $eleve): ?string
    {
        $chemin = $this->photos->chemin($eleve->photo);
        if (! $chemin) {
            return null;
        }

        try {
            $type = match (strtolower(pathinfo($chemin, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };

            return 'data:'.$type.';base64,'.base64_encode(file_get_contents($chemin));
        } catch (Throwable $e) {
            return null;
        }
    }
}
