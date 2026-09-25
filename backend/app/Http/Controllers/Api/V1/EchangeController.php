<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ClasseurDonnees;
use App\Http\Controllers\Controller;
use App\Services\Echange\CatalogueDonnees;
use App\Services\Echange\LecteurClasseur;
use App\Services\Echange\ServiceImport;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Import et export Excel.
 *
 * EXPORT — lecture seule, et borné comme les écrans : même année de travail, même
 * établissement. Un export qui rendrait plus que l'écran serait une porte dérobée autour
 * du périmètre, pas une commodité. Une année clôturée s'exporte normalement : c'est
 * justement une fois close qu'on veut l'archiver.
 *
 * IMPORT — en deux temps. On dépose, on LIT le rapport, puis on applique. Jamais en un
 * seul geste : un import qui écrit dès le dépôt ne laisse pas le temps de s'apercevoir
 * que la colonne Classe était décalée d'un cran, et il n'y a pas de retour en arrière —
 * NEXORA ne supprime jamais dans ECONOMAT.
 */
class EchangeController extends Controller
{
    public function __construct(
        private CatalogueDonnees $catalogue,
        private ServiceImport $imports,
    ) {}

    /** Ce qu'il y a à exporter, combien de lignes, et ce qui s'importe en retour. */
    public function catalogue()
    {
        $annee = ContexteScolaire::annee();

        return [
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'etablissement' => PerimetreEtablissement::nom(),
            'lignes_max_import' => LecteurClasseur::LIGNES_MAX,
            // L'année entière n'est pas un jeu de plus : c'est le classeur complet, dont on
            // reprend toutes les feuilles importables, dans l'ordre du catalogue.
            'import_annee' => [
                'code' => ServiceImport::JEU_ANNEE,
                'libelle' => ServiceImport::LIBELLE_ANNEE,
                'feuilles' => array_map(
                    fn (string $code) => CatalogueDonnees::jeu($code)['feuille'],
                    CatalogueDonnees::importables(),
                ),
            ],
            'jeux' => collect(CatalogueDonnees::codes())->map(function (string $code) {
                $jeu = CatalogueDonnees::jeu($code);

                return [
                    'code' => $code,
                    'libelle' => $jeu['libelle'],
                    'lignes' => $this->catalogue->compter($code),
                    'colonnes' => CatalogueDonnees::entetes($code),
                    // Colonnes qu'ECONOMAT ne porte pas : elles partent vides plutôt que de
                    // faire échouer la feuille entière, et l'écran le dit.
                    'colonnes_introuvables' => $this->catalogue->colonnesIntrouvables($code),
                    'importable' => $jeu['importable'],
                    'pourquoi_pas' => $jeu['pourquoi_pas'] ?? null,
                ];
            })->values(),
        ];
    }

    /** Un classeur, une feuille par jeu demandé. Sans `jeux`, toute l'année. */
    public function exporter(Request $request)
    {
        $jeux = $this->jeuxDemandes($request);

        return Excel::download(
            new ClasseurDonnees($this->catalogue, $jeux),
            $this->nomFichier($jeux, 'export'),
        );
    }

    /**
     * Le modèle vierge : les en-têtes attendus, et rien d'autre. Sans lui, remplir un
     * fichier d'import revient à deviner l'orthographe exacte de vingt colonnes.
     */
    public function modele(Request $request)
    {
        // « annee » n'est pas un jeu du catalogue mais le classeur entier : le modèle porte
        // alors une feuille par jeu importable, et l'import les reprend toutes.
        $jeux = $request->input('jeux') === ServiceImport::JEU_ANNEE
            ? CatalogueDonnees::importables()
            : array_values(array_intersect($this->jeuxDemandes($request), CatalogueDonnees::importables()));

        if ($jeux === []) {
            throw ValidationException::withMessages([
                'jeux' => 'Aucun des jeux demandés ne peut être importé.',
            ]);
        }

        return Excel::download(
            new ClasseurDonnees($this->catalogue, $jeux, vierge: true),
            $this->nomFichier($jeux, 'modele'),
        );
    }

    /** Premier temps : le rapport. Rien n'est écrit. */
    public function analyser(Request $request, string $jeu)
    {
        $this->validerJeuImportable($jeu);

        $data = $request->validate([
            // 20 Mo : un classeur d'année entière porte plusieurs feuilles, là où un jeu
            // seul en portait une.
            'fichier' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'bareme' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ]);

        try {
            return $this->imports->analyser(
                $jeu,
                $data['fichier'],
                (int) $request->user()->getKey(),
                array_filter(['bareme' => $data['bareme'] ?? null], fn ($v) => $v !== null),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['fichier' => $e->getMessage()]);
        }
    }

    /** Second temps : on applique le rapport qui vient d'être lu. */
    public function appliquer(Request $request)
    {
        $data = $request->validate(['jeton' => ['required', 'string', 'size:32']]);

        try {
            return $this->imports->appliquer($data['jeton'], (int) $request->user()->getKey());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['jeton' => $e->getMessage()]);
        }
    }

    /**
     * Les jeux demandés, dans l'ordre du catalogue — pas dans celui de l'URL : les onglets
     * d'un classeur exporté doivent se suivre toujours pareil, sinon deux archives du même
     * établissement ne se comparent plus.
     */
    private function jeuxDemandes(Request $request): array
    {
        $demandes = $request->input('jeux');
        $demandes = is_array($demandes)
            ? $demandes
            : array_filter(explode(',', (string) $demandes), fn ($j) => trim($j) !== '');

        $demandes = array_map(fn ($j) => trim((string) $j), $demandes);

        if ($demandes === []) {
            return CatalogueDonnees::codes();
        }

        $inconnus = array_diff($demandes, CatalogueDonnees::codes());
        if ($inconnus !== []) {
            throw ValidationException::withMessages([
                'jeux' => 'Jeu de données inconnu : '.implode(', ', $inconnus).'.',
            ]);
        }

        return array_values(array_intersect(CatalogueDonnees::codes(), $demandes));
    }

    private function validerJeuImportable(string $jeu): void
    {
        if ($jeu === ServiceImport::JEU_ANNEE) {
            return;
        }

        if (! CatalogueDonnees::existe($jeu) || ! CatalogueDonnees::jeu($jeu)['importable']) {
            throw ValidationException::withMessages([
                'jeu' => "Le jeu « {$jeu} » ne s'importe pas.",
            ]);
        }
    }

    /** nexora-export-2026-2027.xlsx, ou nexora-export-eleves-2026-2027.xlsx. */
    private function nomFichier(array $jeux, string $prefixe): string
    {
        $annee = preg_replace('/[^A-Za-z0-9-]/', '-', (string) ContexteScolaire::annee());
        $quoi = count($jeux) === count(CatalogueDonnees::codes()) ? '' : '-'.implode('-', $jeux);

        return trim("nexora-{$prefixe}{$quoi}-{$annee}", '-').'.xlsx';
    }
}
