<?php

namespace App\Services\Echange;

use App\Services\EtudiantEcrivain;
use App\Services\NoteEcrivain;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Conduit un import : lire, analyser, puis — seulement si on le demande — appliquer.
 *
 * ON ANALYSE CE QU'ON APPLIQUERA, ET ON APPLIQUE CE QU'ON A ANALYSÉ. Le fichier déposé est
 * conservé et repéré par un jeton ; l'application relit CE fichier-là. Redemander le
 * fichier à l'écran aurait laissé la place à un second fichier, voisin mais différent, et
 * le rapport qu'on vient de lire n'aurait plus décrit ce qui s'écrit.
 *
 * Le fichier est effacé sitôt appliqué, et de toute façon au bout de deux heures : il
 * contient des noms d'élèves et des coordonnées de parents, il n'a rien à faire sur le
 * disque du serveur une fois son office rempli.
 */
class ServiceImport
{
    private const DOSSIER = 'echanges';

    private const EXPIRATION_HEURES = 2;

    /** Au-delà, l'aperçu cesse d'être lisible et devient un second fichier à dépouiller. */
    private const APERCU_MAX = 25;

    public function __construct(private LecteurClasseur $lecteur) {}

    public function importateur(string $jeu, array $options = []): Importateur
    {
        return match ($jeu) {
            'eleves' => new ImportEleves(new EtudiantEcrivain),
            'notes' => (new ImportNotes(new NoteEcrivain))->avecBareme(
                isset($options['bareme']) ? (float) $options['bareme'] : null
            ),
            'evaluations' => new ImportEvaluations,
            default => throw new RuntimeException("Le jeu « {$jeu} » ne s'importe pas."),
        };
    }

    /**
     * Analyse le fichier déposé et le met de côté. N'écrit RIEN dans les bases.
     */
    public function analyser(string $jeu, UploadedFile $fichier, int $utilisateur, array $options = []): array
    {
        $this->purger();

        $jeton = bin2hex(random_bytes(16));
        $extension = strtolower($fichier->getClientOriginalExtension() ?: 'xlsx');
        $chemin = $this->dossier().DIRECTORY_SEPARATOR.$jeton.'.'.$extension;
        $fichier->move($this->dossier(), $jeton.'.'.$extension);

        try {
            $rapport = $this->rapport($jeu, $chemin, $options);
        } catch (Throwable $e) {
            @unlink($chemin);

            throw $e;
        }

        file_put_contents($this->dossier().DIRECTORY_SEPARATOR.$jeton.'.json', json_encode([
            'jeu' => $jeu,
            'fichier' => $chemin,
            'nom_origine' => $fichier->getClientOriginalName(),
            'utilisateur' => $utilisateur,
            'options' => $options,
            'depose_le' => CarbonImmutable::now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));

        return $rapport + ['jeton' => $jeton];
    }

    /**
     * Applique l'import analysé sous ce jeton. Le rapport est recalculé à partir du même
     * fichier : ce qui s'écrit est exactement ce qui avait été annoncé.
     */
    public function appliquer(string $jeton, int $utilisateur): array
    {
        $meta = $this->meta($jeton, $utilisateur);
        $rapport = $this->rapport($meta['jeu'], $meta['fichier'], $meta['options'] ?? []);

        $importateur = $this->importateur($meta['jeu'], $meta['options'] ?? []);
        $bilan = $importateur->appliquer($rapport['_verdicts']);

        $this->oublier($jeton, $meta);

        unset($rapport['_verdicts']);

        return $rapport + ['bilan' => $bilan, 'nom_origine' => $meta['nom_origine'] ?? null];
    }

    /** Le rapport d'un fichier : ce qui serait créé, modifié, écarté — et pourquoi. */
    private function rapport(string $jeu, string $chemin, array $options): array
    {
        if (! CatalogueDonnees::existe($jeu) || ! CatalogueDonnees::jeu($jeu)['importable']) {
            throw new RuntimeException("Le jeu « {$jeu} » ne s'importe pas.");
        }

        $importateur = $this->importateur($jeu, $options);

        if (($raison = $importateur->indisponible()) !== null) {
            throw new RuntimeException($raison);
        }

        $lu = $this->lecteur->lire($chemin);
        $attendus = CatalogueDonnees::entetes($jeu);
        $rapproche = $this->lecteur->rapprocher($lu['entetes'], $lu['lignes'], $attendus);

        if ($rapproche['reconnues'] === []) {
            throw new RuntimeException(
                'Aucune colonne reconnue. Attendues : '.implode(', ', $attendus)
                .'. Téléchargez le modèle vierge pour partir des bons en-têtes.'
            );
        }

        $verdicts = $importateur->verdicts($rapproche['lignes']);
        $compte = fn (string $action) => count(array_filter($verdicts, fn ($v) => $v['action'] === $action));

        return [
            'jeu' => $jeu,
            'jeu_libelle' => CatalogueDonnees::jeu($jeu)['libelle'],
            'lignes' => count($verdicts),
            'creations' => $compte(Importateur::CREATION),
            'modifications' => $compte(Importateur::MODIFICATION),
            'rejets' => $compte(Importateur::REJET),
            'colonnes_reconnues' => $rapproche['reconnues'],
            'colonnes_inconnues' => $rapproche['inconnues'],
            'colonnes_absentes' => $rapproche['absentes'],
            // Les rejets d'abord : c'est ce qu'on vient chercher dans un rapport.
            'apercu' => array_slice(
                array_merge(
                    array_values(array_filter($verdicts, fn ($v) => $v['action'] === Importateur::REJET)),
                    array_values(array_filter($verdicts, fn ($v) => $v['action'] !== Importateur::REJET)),
                ),
                0,
                self::APERCU_MAX,
            ),
            '_verdicts' => $verdicts,
        ];
    }

    private function meta(string $jeton, int $utilisateur): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $jeton)) {
            throw new RuntimeException('Jeton invalide.');
        }

        $chemin = $this->dossier().DIRECTORY_SEPARATOR.$jeton.'.json';
        $meta = is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;

        if (! is_array($meta) || ! is_file($meta['fichier'] ?? '')) {
            throw new RuntimeException(
                "Ce fichier n'est plus disponible — l'analyse date de plus de deux heures. Déposez-le à nouveau."
            );
        }

        // Le jeton ne circule que dans la session de celui qui a déposé le fichier ; on le
        // vérifie quand même, un identifiant deviné ne doit pas suffire à écrire en base.
        if ((int) ($meta['utilisateur'] ?? 0) !== $utilisateur) {
            throw new RuntimeException("Ce fichier a été déposé par quelqu'un d'autre.");
        }

        return $meta;
    }

    private function oublier(string $jeton, array $meta): void
    {
        @unlink($meta['fichier']);
        @unlink($this->dossier().DIRECTORY_SEPARATOR.$jeton.'.json');
    }

    /** Efface les dépôts abandonnés : une analyse sans suite ne laisse rien derrière elle. */
    private function purger(): void
    {
        $limite = CarbonImmutable::now()->subHours(self::EXPIRATION_HEURES)->getTimestamp();

        foreach (glob($this->dossier().DIRECTORY_SEPARATOR.'*') ?: [] as $fichier) {
            if (is_file($fichier) && filemtime($fichier) < $limite) {
                @unlink($fichier);
            }
        }
    }

    private function dossier(): string
    {
        $dossier = storage_path('app'.DIRECTORY_SEPARATOR.self::DOSSIER);

        if (! is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }

        return $dossier;
    }
}
