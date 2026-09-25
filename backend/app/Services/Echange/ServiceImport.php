<?php

namespace App\Services\Echange;

use App\Services\EtudiantEcrivain;
use App\Services\NoteEcrivain;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
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
 * UN JEU OU L'ANNÉE ENTIÈRE. Le rapport a la même forme dans les deux cas : des totaux, et
 * une entrée par feuille. Un jeu seul, c'est une feuille ; l'année entière, c'est celles
 * que le classeur porte. Deux formes de rapport auraient donné deux façons de l'afficher,
 * qui auraient divergé.
 *
 * Le fichier est effacé sitôt appliqué, et de toute façon au bout de deux heures : il
 * contient des noms d'élèves et des coordonnées de parents, il n'a rien à faire sur le
 * disque du serveur une fois son office rempli.
 */
class ServiceImport
{
    /** Le jeu « tout le classeur », par opposition à un code du catalogue. */
    public const JEU_ANNEE = 'annee';

    public const LIBELLE_ANNEE = 'Année scolaire entière';

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

    /** Analyse le fichier déposé et le met de côté. N'écrit RIEN dans les bases. */
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

        unset($rapport['_verdicts']);

        return $rapport + ['jeton' => $jeton];
    }

    /**
     * Applique l'import analysé sous ce jeton. Le rapport est recalculé à partir du même
     * fichier : ce qui s'écrit est exactement ce qui avait été annoncé.
     *
     * L'ordre des feuilles est celui du catalogue, et il n'est pas indifférent : les élèves
     * passent avant les notes, sans quoi une note porterait sur un élève qui n'existe pas
     * encore.
     */
    public function appliquer(string $jeton, int $utilisateur): array
    {
        $meta = $this->meta($jeton, $utilisateur);
        $rapport = $this->rapport($meta['jeu'], $meta['fichier'], $meta['options'] ?? []);

        $total = ['creees' => 0, 'modifiees' => 0, 'echecs' => []];
        $parJeu = [];

        foreach ($rapport['_verdicts'] as $code => $verdicts) {
            $bilan = $this->importateur($code, $meta['options'] ?? [])->appliquer($verdicts);

            $total['creees'] += $bilan['creees'];
            $total['modifiees'] += $bilan['modifiees'];
            $total['echecs'] = array_merge($total['echecs'], array_map(
                fn ($e) => $e + ['jeu' => CatalogueDonnees::jeu($code)['libelle']],
                $bilan['echecs'],
            ));

            $parJeu[] = ['jeu' => $code, 'jeu_libelle' => CatalogueDonnees::jeu($code)['libelle']] + $bilan;
        }

        $this->oublier($jeton, $meta);

        unset($rapport['_verdicts']);

        return $rapport + [
            'bilan' => $total,
            'bilan_par_jeu' => $parJeu,
            'nom_origine' => $meta['nom_origine'] ?? null,
        ];
    }

    /** Le rapport d'un fichier : ce qui serait créé, modifié, écarté — et pourquoi. */
    private function rapport(string $jeu, string $chemin, array $options): array
    {
        $feuilles = $jeu === self::JEU_ANNEE
            ? $this->feuillesAnnee($chemin, $options)
            : $this->feuilleUnique($jeu, $chemin, $options);

        $somme = fn (string $cle) => array_sum(array_column($feuilles['lues'], $cle));

        return [
            'jeu' => $jeu,
            'jeu_libelle' => $jeu === self::JEU_ANNEE
                ? self::LIBELLE_ANNEE
                : CatalogueDonnees::jeu($jeu)['libelle'],
            'lignes' => $somme('lignes'),
            'creations' => $somme('creations'),
            'modifications' => $somme('modifications'),
            'rejets' => $somme('rejets'),
            'feuilles' => array_map(fn ($f) => Arr::except($f, ['_verdicts']), $feuilles['lues']),
            'feuilles_absentes' => $feuilles['absentes'],
            '_verdicts' => array_column($feuilles['lues'], '_verdicts', 'jeu'),
        ];
    }

    /** Un seul jeu : la première feuille du fichier, quel que soit son nom. */
    private function feuilleUnique(string $jeu, string $chemin, array $options): array
    {
        $this->exigerImportable($jeu);

        $importateur = $this->importateur($jeu, $options);
        $this->exigerDisponible($importateur);

        return ['lues' => [$this->analyse($jeu, $importateur, $this->lecteur->lire($chemin), null)], 'absentes' => []];
    }

    /**
     * L'année entière : chaque jeu importable dont le classeur porte la feuille, dans
     * l'ordre du catalogue.
     */
    private function feuillesAnnee(string $chemin, array $options): array
    {
        $feuilles = $this->lecteur->lireToutes($chemin);
        $lues = [];
        $absentes = [];
        $elevesAttendus = [];

        foreach (CatalogueDonnees::importables() as $code) {
            $attendue = CatalogueDonnees::jeu($code)['feuille'];
            $nom = $this->lecteur->feuillePour($feuilles, $attendue);

            if ($nom === null) {
                $absentes[] = $attendue;

                continue;
            }

            $importateur = $this->importateur($code, $options);

            // Les notes sont jugées sur les élèves que la feuille précédente va créer, pas
            // seulement sur ceux déjà en base — sinon un classeur d'année neuve verrait
            // toutes ses notes écartées pour « matricule inconnu ».
            if ($importateur instanceof ImportNotes) {
                $importateur->avecEleves($elevesAttendus);
            }

            $this->exigerDisponible($importateur);

            $analyse = $this->analyse($code, $importateur, $feuilles[$nom], $nom);
            $lues[] = $analyse;

            if ($code === 'eleves') {
                $elevesAttendus = $this->elevesDe($analyse['_verdicts']);
            }
        }

        if ($lues === []) {
            throw new RuntimeException(
                'Aucune feuille importable dans ce classeur. Attendues : '
                .implode(', ', array_map(
                    fn ($c) => CatalogueDonnees::jeu($c)['feuille'],
                    CatalogueDonnees::importables(),
                )).'. Téléchargez le modèle vierge pour partir du bon classeur.'
            );
        }

        return ['lues' => $lues, 'absentes' => $absentes];
    }

    /** Le sort de chaque ligne d'une feuille, et ce qu'on a su reconnaître de ses colonnes. */
    private function analyse(string $jeu, Importateur $importateur, array $lu, ?string $nomFeuille): array
    {
        $attendus = CatalogueDonnees::entetes($jeu);
        $rapproche = $this->lecteur->rapprocher($lu['entetes'], $lu['lignes'], $attendus);

        if ($rapproche['reconnues'] === []) {
            throw new RuntimeException(
                ($nomFeuille ? "Feuille « {$nomFeuille} » : a" : 'A').'ucune colonne reconnue. Attendues : '
                .implode(', ', $attendus)
                .'. Téléchargez le modèle vierge pour partir des bons en-têtes.'
            );
        }

        $verdicts = $importateur->verdicts($rapproche['lignes']);
        $compte = fn (string $action) => count(array_filter($verdicts, fn ($v) => $v['action'] === $action));

        return [
            'jeu' => $jeu,
            'jeu_libelle' => CatalogueDonnees::jeu($jeu)['libelle'],
            'feuille' => $nomFeuille,
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

    /**
     * Matricule -> classe pour les élèves que la feuille « Eleves » va écrire.
     *
     * @return array<string, string>
     */
    private function elevesDe(array $verdicts): array
    {
        $index = [];

        foreach ($verdicts as $v) {
            if ($v['action'] === Importateur::REJET) {
                continue;
            }
            $matricule = $v['donnees']['matricule'] ?? null;
            $classe = $v['donnees']['classe_code'] ?? null;
            if ($matricule !== null && $classe !== null) {
                $index[$matricule] = $classe;
            }
        }

        return $index;
    }

    private function exigerImportable(string $jeu): void
    {
        if (! CatalogueDonnees::existe($jeu) || ! CatalogueDonnees::jeu($jeu)['importable']) {
            throw new RuntimeException("Le jeu « {$jeu} » ne s'importe pas.");
        }
    }

    private function exigerDisponible(Importateur $importateur): void
    {
        if (($raison = $importateur->indisponible()) !== null) {
            throw new RuntimeException($raison);
        }
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
