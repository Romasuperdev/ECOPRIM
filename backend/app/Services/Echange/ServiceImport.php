<?php

namespace App\Services\Echange;

use App\Services\AbsenceEcrivain;
use App\Services\EtudiantEcrivain;
use App\Services\GrilleCoefficients;
use App\Services\NoteEcrivain;
use App\Services\ProfesseurEcrivain;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        private LecteurClasseur $lecteur,
        private AnneeImport $annees,
    ) {}

    /**
     * « Tout importer » est le mode par défaut : une option absente vaut oui. Seul un
     * `tout_importer` explicitement faux (case décochée) remet le mode strict.
     */
    public static function toutImporter(array $options): bool
    {
        $valeur = $options['tout_importer'] ?? true;

        return is_bool($valeur)
            ? $valeur
            : ! in_array(mb_strtolower(trim((string) $valeur)), ['0', 'false', 'non', 'off', 'no'], true);
    }

    public function importateur(string $jeu, array $options = []): Importateur
    {
        return $this->construire($jeu, $options)->toutImporter(self::toutImporter($options));
    }

    private function construire(string $jeu, array $options): Importateur
    {
        return match ($jeu) {
            'eleves' => new ImportEleves(new EtudiantEcrivain),
            'notes' => (new ImportNotes(new NoteEcrivain, new GrilleCoefficients))->avecBareme(
                isset($options['bareme']) ? (float) $options['bareme'] : null
            ),
            'evaluations' => new ImportEvaluations,
            'niveaux' => new ImportNiveaux,
            'classes' => new ImportClasses,
            'matieres' => new ImportMatieres,
            'enseignants' => new ImportEnseignants(new ProfesseurEcrivain),
            'absences' => new ImportAbsences(new AbsenceEcrivain),
            default => throw new RuntimeException("Le jeu « {$jeu} » ne s'importe pas."),
        };
    }

    /**
     * Analyse le fichier déposé et le met de côté. N'écrit RIEN dans les bases — pas même
     * l'année à créer, qui ne l'est qu'à l'application (voir AnneeImport).
     *
     * @param  bool  $anneeChoisie  une année a été choisie à l'écran : elle prime sur celle
     *                              que le fichier mentionne
     */
    public function analyser(string $jeu, UploadedFile $fichier, int $utilisateur, array $options = [], bool $anneeChoisie = false): array
    {
        $this->purger();

        $jeton = bin2hex(random_bytes(16));
        $extension = strtolower($fichier->getClientOriginalExtension() ?: 'xlsx');
        $chemin = $this->dossier().DIRECTORY_SEPARATOR.$jeton.'.'.$extension;
        $fichier->move($this->dossier(), $jeton.'.'.$extension);

        try {
            $cible = $this->annees->preparer(
                $chemin,
                $fichier->getClientOriginalName(),
                $anneeChoisie,
                $this->apporteClasses($jeu, $chemin),
            );
            $this->exigerDroitsSurAnnee($cible);
            // « Tout importer » : les classes que le fichier cite et qu'un établissement
            // visible a ouvertes une autre année sont reprises dans l'année visée.
            if (self::toutImporter($options)) {
                $cible = $this->annees->completerStructure($cible, $this->classesCitees($jeu, $chemin));
            }
            $rapport = $this->rapport($jeu, $chemin, $options, AnneeImport::attendusDe($cible['structure']));
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
            // L'année visée par l'import est celle de l'ANALYSE : l'appliquer plus tard,
            // après avoir changé d'année dans l'en-tête, ne doit pas l'écrire ailleurs.
            'annee' => ContexteScolaire::annee(),
            'cible' => $cible,
            'depose_le' => CarbonImmutable::now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));

        unset($rapport['_verdicts']);

        return $rapport + ['annee_cible' => $this->resumeCible($cible), 'jeton' => $jeton];
    }

    /**
     * Applique l'import analysé sous ce jeton. Le rapport est recalculé à partir du même
     * fichier : ce qui s'écrit est exactement ce qui avait été annoncé.
     *
     * L'ordre des feuilles est celui de CatalogueDonnees::importables(), et il n'est pas
     * indifférent : les niveaux avant les classes, les classes avant les élèves, les élèves
     * avant leurs notes et leurs absences — sans quoi une ligne porterait sur ce qui
     * n'existe pas encore.
     */
    public function appliquer(string $jeton, int $utilisateur): array
    {
        $meta = $this->meta($jeton, $utilisateur);
        $cible = $meta['cible'] ?? null;

        // Le rapport se recalcule dans le MÊME contexte que l'analyse — y compris pour une
        // année encore à créer : c'est la seule façon d'écrire ce qui avait été annoncé.
        if (! empty($cible['creation'])) {
            ContexteScolaire::forcerNouvelle($cible['creation']['libelle'], $cible['creation']['code']);
        } elseif (! empty($meta['annee'])) {
            ContexteScolaire::forcer((string) $meta['annee']);
        }
        PerimetreEtablissement::oublier();

        if ($cible) {
            $this->exigerDroitsSurAnnee($cible);
        }
        $rapport = $this->rapport($meta['jeu'], $meta['fichier'], $meta['options'] ?? [], AnneeImport::attendusDe($cible['structure'] ?? null));

        if (! empty($cible['creation'])) {
            $this->annees->creer($cible);
        } elseif (! empty($cible['structure'])) {
            $this->annees->ouvrirStructure($cible);
        }
        if ($cible && ($cible['cloturee'] || ! empty($cible['creation']))) {
            Log::info('Import dans une année clôturée ou créée par l’import.', [
                'utilisateur' => $utilisateur,
                'annee' => $cible['libelle'],
                'creee' => ! empty($cible['creation']),
                'cloturee' => $cible['cloturee'],
                'fichier' => $meta['nom_origine'] ?? null,
            ]);
        }

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

            // Les classes tout juste ouvertes entrent dans le périmètre : la feuille
            // suivante doit les voir comme l'écran les verra.
            PerimetreEtablissement::oublier();
        }

        $this->oublier($jeton, $meta);

        unset($rapport['_verdicts']);

        return $rapport + [
            'annee_cible' => $cible ? $this->resumeCible($cible) : null,
            'bilan' => $total,
            'bilan_par_jeu' => $parJeu,
            'nom_origine' => $meta['nom_origine'] ?? null,
        ];
    }

    /** Le rapport d'un fichier : ce qui serait créé, modifié, écarté — et pourquoi. */
    private function rapport(string $jeu, string $chemin, array $options, array $attendus = []): array
    {
        $feuilles = $jeu === self::JEU_ANNEE
            ? $this->feuillesAnnee($chemin, $options, $attendus)
            : $this->feuilleUnique($jeu, $chemin, $options, $attendus);

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
            // Lignes qui passent grâce à un ajustement (« tout importer ») : leur motif dit
            // ce qui a été rattrapé.
            'ajustees' => $somme('ajustees'),
            'tout_importer' => self::toutImporter($options),
            'feuilles' => array_map(fn ($f) => Arr::except($f, ['_verdicts']), $feuilles['lues']),
            'feuilles_absentes' => $feuilles['absentes'],
            '_verdicts' => array_column($feuilles['lues'], '_verdicts', 'jeu'),
        ];
    }

    /** Un seul jeu : la première feuille du fichier, quel que soit son nom. */
    private function feuilleUnique(string $jeu, string $chemin, array $options, array $attendus = []): array
    {
        $this->exigerImportable($jeu);

        $importateur = $this->importateur($jeu, $options)->avecAttendus($attendus);
        $this->exigerDisponible($importateur);

        return ['lues' => [$this->analyse($jeu, $importateur, $this->lecteur->lire($chemin), null, $attendus['_alias_classes'] ?? [])], 'absentes' => []];
    }

    /**
     * L'année entière : chaque jeu importable dont le classeur porte la feuille, dans
     * l'ordre d'import. Chaque feuille est jugée sur ce que les précédentes vont créer
     * (voir Importateur::avecAttendus) : un classeur d'année neuve ouvre ses niveaux, puis
     * ses classes, puis y inscrit ses élèves — rien de tout cela n'est encore en base au
     * moment de l'analyse.
     */
    private function feuillesAnnee(string $chemin, array $options, array $attendus = []): array
    {
        $feuilles = $this->lecteur->lireToutes($chemin);
        $lues = [];
        $absentes = [];

        foreach (CatalogueDonnees::importables() as $code) {
            $attendue = CatalogueDonnees::jeu($code)['feuille'];
            $nom = $this->lecteur->feuillePour($feuilles, $attendue);

            if ($nom === null) {
                $absentes[] = $attendue;

                continue;
            }

            $importateur = $this->importateur($code, $options)->avecAttendus($attendus);
            $this->exigerDisponible($importateur);

            $analyse = $this->analyse($code, $importateur, $feuilles[$nom], $nom, $attendus['_alias_classes'] ?? []);
            $lues[] = $analyse;

            // Ce que la feuille écrit s'ajoute à ce qui était déjà attendu (la structure
            // reprise par une année neuve), sans l'effacer.
            $attendus[$code] = ($code === 'eleves'
                ? $this->elevesDe($analyse['_verdicts'])
                : $this->codesDe($analyse['_verdicts'])) + ($attendus[$code] ?? []);

            // « Tout importer » : les classes qu'une feuille crée en passant (classe
            // inconnue d'un élève…) existent pour les feuilles suivantes ; une classe que la
            // feuille Classes a dû ouvrir sous un autre code reçoit les lignes de l'ancien.
            $attendus['classes'] = Importateur::classesCreeesPar($analyse['_verdicts']) + ($attendus['classes'] ?? []);
            if ($code === 'classes') {
                foreach ($analyse['_verdicts'] as $v) {
                    if ($v['action'] !== Importateur::REJET && isset($v['donnees']['_alias_de'], $v['donnees']['code'])) {
                        $attendus['_alias_classes'][$v['donnees']['_alias_de']] = $v['donnees']['code'];
                    }
                }
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
    private function analyse(string $jeu, Importateur $importateur, array $lu, ?string $nomFeuille, array $alias = []): array
    {
        $attendus = CatalogueDonnees::entetes($jeu);
        $rapproche = $this->lecteur->rapprocher($lu['entetes'], $lu['lignes'], $attendus);

        // Une année neuve range « CE1 A » dans sa copie « CE1 A-2025 » (voir AnneeImport) :
        // le fichier parle des classes qu'il connaît, la traduction est exacte, pas devinée.
        if ($alias !== [] && $jeu !== 'classes') {
            foreach ($rapproche['lignes'] as &$ligne) {
                $classe = trim((string) ($ligne['Classe'] ?? ''));
                if (isset($alias[$classe])) {
                    $ligne['Classe'] = $alias[$classe];
                }
            }
            unset($ligne);
        }

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
            'ajustees' => count(array_filter(
                $verdicts,
                fn ($v) => $v['action'] !== Importateur::REJET && ! empty($v['motif']),
            )),
            'colonnes_reconnues' => $rapproche['reconnues'],
            'colonnes_inconnues' => $rapproche['inconnues'],
            'colonnes_absentes' => $rapproche['absentes'],
            // Les rejets d'abord, puis les lignes ajustées : c'est ce qu'on vient chercher
            // dans un rapport.
            'apercu' => array_slice(
                array_merge(
                    array_values(array_filter($verdicts, fn ($v) => $v['action'] === Importateur::REJET)),
                    array_values(array_filter($verdicts, fn ($v) => $v['action'] !== Importateur::REJET && ! empty($v['motif']))),
                    array_values(array_filter($verdicts, fn ($v) => $v['action'] !== Importateur::REJET && empty($v['motif']))),
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

    /**
     * Les codes qu'une feuille de référentiel va écrire (niveaux, classes, matières).
     *
     * @return array<string, true>
     */
    private function codesDe(array $verdicts): array
    {
        $codes = [];

        foreach ($verdicts as $v) {
            if ($v['action'] !== Importateur::REJET && isset($v['donnees']['code'])) {
                $codes[$v['donnees']['code']] = true;
            }
        }

        return $codes;
    }

    /** Le classeur apporte-t-il ses propres classes ? Sinon une année neuve reprend celles de l'année de travail. */
    /**
     * Les codes de classe que le fichier cite, dans les feuilles qui en portent une
     * (élèves, notes, absences, évaluations).
     *
     * @return array<int, string>
     */
    private function classesCitees(string $jeu, string $chemin): array
    {
        $jeux = ['eleves', 'notes', 'absences', 'evaluations'];

        try {
            if ($jeu === self::JEU_ANNEE) {
                $toutes = $this->lecteur->lireToutes($chemin);
                $feuilles = [];
                foreach ($jeux as $code) {
                    $nom = $this->lecteur->feuillePour($toutes, CatalogueDonnees::jeu($code)['feuille']);
                    if ($nom !== null) {
                        $feuilles[] = $toutes[$nom];
                    }
                }
            } else {
                $feuilles = in_array($jeu, $jeux, true) ? [$this->lecteur->lire($chemin)] : [];
            }
        } catch (Throwable $e) {
            return [];
        }

        $codes = [];
        foreach ($feuilles as $feuille) {
            $colonne = collect($feuille['entetes'])->first(fn ($e) => mb_strtolower(trim($e)) === 'classe');
            if ($colonne === null) {
                continue;
            }
            foreach ($feuille['lignes'] as $ligne) {
                $valeur = trim((string) ($ligne[$colonne] ?? ''));
                if ($valeur !== '') {
                    $codes[$valeur] = true;
                }
            }
        }

        return array_keys($codes);
    }

    private function apporteClasses(string $jeu, string $chemin): bool
    {
        if ($jeu === 'classes') {
            return true;
        }
        if ($jeu !== self::JEU_ANNEE) {
            return false;
        }

        try {
            $feuilles = $this->lecteur->lireToutes($chemin);
            $nom = $this->lecteur->feuillePour($feuilles, CatalogueDonnees::jeu('classes')['feuille']);

            return $nom !== null && $feuilles[$nom]['lignes'] !== [];
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Écrire dans une année clôturée, ou en créer une : AnneeImport::PERMISSION. Vérifié
     * à l'analyse ET à l'application — un droit retiré entre les deux ne s'exerce plus.
     */
    private function exigerDroitsSurAnnee(array $cible): void
    {
        if (AnneeImport::autorise() || (! $cible['cloturee'] && empty($cible['creation']))) {
            return;
        }

        $quoi = ! empty($cible['creation'])
            ? "L'année « {$cible['libelle']} », mentionnée par le fichier, n'existe pas : la créer"
                .($cible['cloturee'] ? ' (clôturée, car antérieure à l’année en cours)' : '')
            : "L'année « {$cible['libelle']} » est clôturée : y importer";

        throw new RuntimeException(
            $quoi.' est réservé aux administrateurs, ou à un rôle ayant la permission « Importer dans une année clôturée ou à créer ».'
        );
    }

    /** Ce que l'écran affiche de l'année visée. */
    private function resumeCible(array $cible): array
    {
        return [
            'libelle' => $cible['libelle'],
            'source' => $cible['source'],
            'existe' => $cible['existe'],
            'cloturee' => $cible['cloturee'],
            'reference' => $cible['reference'] ?? null,
            'classes_reprises' => array_values(array_map(
                fn ($c) => ($c['origine'] === $c['code'] ? $c['code'] : "{$c['origine']} → {$c['code']}")
                    .(isset($c['depuis']) ? " (de {$c['depuis']})" : ''),
                $cible['structure']['classes'] ?? [],
            )),
        ];
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
