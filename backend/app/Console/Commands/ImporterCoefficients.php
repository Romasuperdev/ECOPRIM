<?php

namespace App\Console\Commands;

use App\Models\CoefficientMatiere;
use App\Services\ReferentielEcrivain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pose la grille commune des barèmes et coefficients depuis un CSV.
 *
 * `matiere;niveau;coefficient;note_max` — point-virgule, comme les tableurs francophones
 * l'écrivent par défaut. Idempotente : relancer le même fichier met à jour, ne duplique pas.
 *
 * ELLE ÉCRIT LA GRILLE COMMUNE (etablissement_code et annee à null), celle que chaque école
 * pourra ensuite surcharger depuis l'écran. C'est le geste d'installation, fait une fois ;
 * les ajustements d'une école ne passent pas par là.
 *
 * ELLE CRÉE LE RÉFÉRENTIEL MANQUANT. Le référentiel des matières est souvent vide au
 * démarrage : rejeter trente lignes parce que « CONJUGAISON » n'existe pas encore
 * obligerait à saisir trente matières à la main avant de pouvoir rien importer. Les
 * matières et les niveaux absents sont donc créés, et la commande les ÉNUMÈRE — car ils
 * partent dans ECONOMAT, table partagée. `--sans-creation` s'en abstient et se contente de
 * rejeter, pour qui veut voir d'abord.
 */
class ImporterCoefficients extends Command
{
    protected $signature = 'coefficients:import
        {fichier : chemin du CSV (matiere;niveau;coefficient;note_max)}
        {--sans-creation : ne crée aucune matière ni aucun niveau absent, rejette les lignes}
        {--simulation : analyse le fichier et affiche le rapport sans rien écrire}';

    protected $description = 'Importe la grille commune des barèmes et coefficients depuis un CSV.';

    private const COLONNES = ['matiere', 'niveau', 'coefficient', 'note_max'];

    public function handle(): int
    {
        $chemin = $this->argument('fichier');

        if (! is_file($chemin)) {
            $this->error("Fichier introuvable : {$chemin}");

            return self::FAILURE;
        }

        $lignes = $this->lire($chemin);
        if ($lignes === null) {
            return self::FAILURE;
        }

        $matieres = $this->referentiel('T_MATIERE', 'CodeMatiere');
        $niveaux = $this->referentiel('T_NIVEAU', 'CodeNiveau');

        $bilan = ['crees' => 0, 'maj' => 0, 'rejets' => 0];
        $nouvellesMatieres = [];
        $nouveauxNiveaux = [];
        $rejets = [];

        foreach ($lignes as $n => $ligne) {
            $matiere = trim((string) ($ligne['matiere'] ?? ''));
            $niveau = trim((string) ($ligne['niveau'] ?? ''));
            $coefficient = $this->nombre($ligne['coefficient'] ?? null);
            $noteMax = $this->nombre($ligne['note_max'] ?? null);

            if ($matiere === '' || $niveau === '') {
                $rejets[] = [$n, 'matière ou niveau vide'];
                $bilan['rejets']++;

                continue;
            }

            if ($coefficient === null || $coefficient <= 0) {
                $rejets[] = [$n, 'coefficient absent ou nul'];
                $bilan['rejets']++;

                continue;
            }

            if (! isset($matieres[mb_strtolower($matiere)])) {
                if ($this->option('sans-creation')) {
                    $rejets[] = [$n, "matière « {$matiere} » inconnue"];
                    $bilan['rejets']++;

                    continue;
                }
                $nouvellesMatieres[$matiere] = true;
                $matieres[mb_strtolower($matiere)] = $matiere;
            }

            if (! isset($niveaux[mb_strtolower($niveau)])) {
                if ($this->option('sans-creation')) {
                    $rejets[] = [$n, "niveau « {$niveau} » inconnu"];
                    $bilan['rejets']++;

                    continue;
                }
                $nouveauxNiveaux[$niveau] = true;
                $niveaux[mb_strtolower($niveau)] = $niveau;
            }

            if ($this->option('simulation')) {
                $bilan['crees']++;

                continue;
            }

            $existante = CoefficientMatiere::where([
                'etablissement_code' => null, 'annee' => null,
                'niveau_code' => $niveau, 'classe_code' => null, 'matiere_code' => $matiere,
            ])->first();

            CoefficientMatiere::updateOrCreate(
                [
                    'etablissement_code' => null, 'annee' => null,
                    'niveau_code' => $niveau, 'classe_code' => null, 'matiere_code' => $matiere,
                ],
                ['coefficient' => $coefficient, 'note_max' => $noteMax, 'actif' => true],
            );

            $existante ? $bilan['maj']++ : $bilan['crees']++;
        }

        if (! $this->option('simulation')) {
            $this->creerReferentiel(array_keys($nouvellesMatieres), array_keys($nouveauxNiveaux));
        }

        $this->rapport($bilan, array_keys($nouvellesMatieres), array_keys($nouveauxNiveaux), $rejets);

        return $bilan['rejets'] > 0 ? self::INVALID : self::SUCCESS;
    }

    /** @return array<int, array<string, string>>|null */
    private function lire(string $chemin): ?array
    {
        $flux = fopen($chemin, 'r');
        $entetes = fgetcsv($flux, 0, ';');

        if ($entetes === false) {
            $this->error('Le fichier est vide.');
            fclose($flux);

            return null;
        }

        // Un tableur enregistre volontiers un BOM en tête de fichier : sans cela, la
        // première colonne s'appellerait « \u{FEFF}matiere » et ne serait jamais reconnue.
        $entetes = array_map(
            fn ($e) => strtolower(trim(str_replace("\u{FEFF}", '', (string) $e))),
            $entetes,
        );

        $manquantes = array_diff(['matiere', 'niveau', 'coefficient'], $entetes);
        if ($manquantes !== []) {
            $this->error('Colonnes manquantes : '.implode(', ', $manquantes)
                .'. Attendues : '.implode(';', self::COLONNES));
            fclose($flux);

            return null;
        }

        $lignes = [];
        $n = 1;
        while (($brut = fgetcsv($flux, 0, ';')) !== false) {
            $n++;
            if (array_filter($brut, fn ($c) => trim((string) $c) !== '') === []) {
                continue;
            }
            $lignes[$n] = array_combine(
                $entetes,
                array_pad(array_slice($brut, 0, count($entetes)), count($entetes), null),
            );
        }
        fclose($flux);

        return $lignes;
    }

    /** @return array<string, string> code en minuscules -> code réel */
    private function referentiel(string $table, string $colonne): array
    {
        try {
            return DB::connection('economat')->table($table)->pluck($colonne)
                ->filter()
                ->mapWithKeys(fn ($c) => [mb_strtolower(trim((string) $c)) => trim((string) $c)])
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Crée dans ECONOMAT les matières et niveaux que le fichier nomme et qui n'existent
     * pas. Le libellé reprend le code faute de mieux : c'est au référentiel d'être corrigé
     * ensuite depuis son écran, pas à l'import d'inventer un intitulé.
     */
    private function creerReferentiel(array $matieres, array $niveaux): void
    {
        foreach ($matieres as $code) {
            try {
                (new ReferentielEcrivain('T_MATIERE', 'Code', [
                    'code' => 'CodeMatiere', 'libelle' => 'LibelleMatiere',
                ]))->creer(['code' => $code, 'libelle' => $code]);
            } catch (Throwable $e) {
                $this->warn("Matière « {$code} » non créée : ".$e->getMessage());
            }
        }

        foreach ($niveaux as $code) {
            try {
                (new ReferentielEcrivain('T_NIVEAU', 'Num', [
                    'code' => 'CodeNiveau', 'libelle' => 'LibelleNiveau',
                ]))->creer(['code' => $code, 'libelle' => $code]);
            } catch (Throwable $e) {
                $this->warn("Niveau « {$code} » non créé : ".$e->getMessage());
            }
        }
    }

    private function rapport(array $bilan, array $matieres, array $niveaux, array $rejets): void
    {
        if ($this->option('simulation')) {
            $this->info('SIMULATION — rien n’a été écrit.');
        }

        $this->newLine();
        $this->line('  Créés      : '.$bilan['crees']);
        $this->line('  Mis à jour : '.$bilan['maj']);
        $this->line('  Rejetés    : '.$bilan['rejets']);

        if ($matieres !== []) {
            $this->newLine();
            $this->warn('Matières créées dans ECONOMAT.T_MATIERE ('.count($matieres).') — à vérifier depuis l’écran Matières :');
            $this->line('  '.implode(', ', $matieres));
        }

        if ($niveaux !== []) {
            $this->newLine();
            $this->warn('Niveaux créés dans ECONOMAT.T_NIVEAU ('.count($niveaux).') — à vérifier depuis l’écran Niveaux :');
            $this->line('  '.implode(', ', $niveaux));
        }

        if ($rejets !== []) {
            $this->newLine();
            $this->error('Lignes rejetées :');
            foreach ($rejets as [$n, $motif]) {
                $this->line("  ligne {$n} : {$motif}");
            }
        }
    }

    private function nombre($valeur): ?float
    {
        $valeur = str_replace([' ', ','], ['', '.'], trim((string) $valeur));

        return $valeur !== '' && is_numeric($valeur) ? (float) $valeur : null;
    }
}
