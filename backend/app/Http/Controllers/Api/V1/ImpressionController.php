<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use App\Models\Enseignant;
use App\Services\PhotoEleveStockage;
use App\Support\ContexteScolaire;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
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
            // Une date en 2015-02-14 se lit mal sur un document français, et SQL Server la
            // rend volontiers avec une heure à zéro qui n'apprend rien.
            'd' => fn ($valeur) => $this->dateLisible($valeur),
        ];
    }

    private function dateLisible($valeur): ?string
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valeur)->format('d/m/Y');
        } catch (Throwable $e) {
            return $valeur;
        }
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
            'details' => $this->detailsEleve($eleve),
        ]);

        return $pdf->download($this->nomFichier('fiche-eleve', $eleve->matricule ?: $eleve->getKey()));
    }

    /**
     * Ce que l'inscription fait saisir mais que le modèle Eleve n'expose pas.
     *
     * Sa liste blanche protège le FINANCIER et le TECHNIQUE ; ces coordonnées-là n'en
     * relèvent pas — c'est le formulaire d'inscription qui les remplit, et une fiche
     * imprimée qui les omettrait ne serait pas la fiche de l'élève. On les lit sur la ligne
     * déjà chargée, sans requête de plus et sans élargir ce que l'API publie.
     */
    private function detailsEleve(Eleve $eleve): array
    {
        $brut = function (string $colonne) use ($eleve) {
            $valeur = trim((string) ($eleve->getRawOriginal($colonne) ?? ''));

            return $valeur !== '' ? $valeur : null;
        };

        return [
            'adresse' => $brut('Adresse'),
            'quartier' => $brut('Quartier'),
            'commune' => $brut('Commune'),
            'ville' => $brut('Ville'),
            'telephone' => $brut('Telephone'),
            'email' => $brut('Email'),
            'date_inscription' => $brut('DateInscription'),
            'etab_origine' => $brut('EtabOrigine'),
            'niveau_origine' => $brut('NiveauOrigine'),
            // Les trois indicateurs d'ECONOMAT reconstituent le mouvement d'entrée.
            'mouvement' => match (true) {
                (bool) $eleve->getRawOriginal('Transfert') => 'Transfert',
                (bool) $eleve->getRawOriginal('Reinscription') => 'Réinscription',
                (bool) $eleve->getRawOriginal('Inscription') => 'Inscription',
                default => null,
            },
            'age' => $this->age($eleve->date_naissance),
        ];
    }

    /** L'âge de l'élève à la date d'édition : une fiche scolaire se lit avec lui. */
    private function age($naissance): ?int
    {
        $naissance = trim((string) $naissance);
        if ($naissance === '') {
            return null;
        }

        try {
            $age = CarbonImmutable::parse($naissance)->age;

            return ($age >= 0 && $age < 130) ? $age : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Certificat de scolarité : atteste l'inscription en cours d'un élève. */
    public function certificatScolarite(Request $request, Eleve $eleve)
    {
        $pdf = Pdf::loadView('pdf.certificat-scolarite', $this->commun($request) + [
            'eleve' => $eleve,
            'classe' => $this->libelleClasse($eleve->classe_code),
            'motif' => $this->motif($request),
        ]);

        return $pdf->download($this->nomFichier('certificat-scolarite', $eleve->matricule ?: $eleve->getKey()));
    }

    /** Attestation de fréquentation : atteste qu'un élève a fréquenté l'établissement. */
    public function attestationFrequentation(Request $request, Eleve $eleve)
    {
        $pdf = Pdf::loadView('pdf.attestation-frequentation', $this->commun($request) + [
            'eleve' => $eleve,
            'classe' => $this->libelleClasse($eleve->classe_code),
            'motif' => $this->motif($request),
        ]);

        return $pdf->download($this->nomFichier('attestation-frequentation', $eleve->matricule ?: $eleve->getKey()));
    }

    /** Motif facultatif (visa, allocation familiale, transfert...), tapé avant impression. */
    private function motif(Request $request): ?string
    {
        $motif = trim((string) $request->query('motif', ''));

        return $motif !== '' ? $motif : null;
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
