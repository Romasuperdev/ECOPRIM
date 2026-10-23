<?php

namespace App\Console\Commands;

use App\Services\PhotoEleveStockage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Contrôle d'avant mise en ligne.
 *
 * Une installation rate rarement sur le code : elle rate sur une extension absente, un
 * dossier non inscriptible, une limite de php.ini trop basse ou un mot de passe périmé.
 * Chacun de ces défauts se manifeste plus tard, par un message qui ne dit pas sa cause —
 * « Une erreur est survenue », un 500 nu, une photo refusée sans raison. Cette commande
 * les sort tous d'un coup, AVANT la première connexion d'un utilisateur.
 *
 * Elle ne modifie rien. Chaque contrôle qui échoue dit quoi faire, pas seulement que
 * quelque chose ne va pas.
 */
class VerifierDeploiement extends Command
{
    protected $signature = 'nexora:verifier-deploiement';

    protected $description = "Contrôle l'installation avant mise en ligne : base, extensions, dossiers, limites, interface.";

    private array $anomalies = [];

    private array $avertissements = [];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>Contrôle de l\'installation NEXORA</>');
        $this->newLine();

        $this->bloc('Environnement', fn () => $this->environnement());
        $this->bloc('Extensions PHP', fn () => $this->extensions());
        $this->bloc('Bases de données', fn () => $this->bases());
        $this->bloc('Tables attendues', fn () => $this->tables());
        $this->bloc('Dossiers', fn () => $this->dossiers());
        $this->bloc('Limites de téléversement', fn () => $this->limites());
        $this->bloc('Interface', fn () => $this->interface());

        return $this->conclusion();
    }

    private function bloc(string $titre, callable $controles): void
    {
        $this->line("  <options=bold>{$titre}</>");
        $controles();
        $this->newLine();
    }

    /** Un contrôle : vert si c'est bon, rouge s'il faut agir, jaune si cela peut attendre. */
    private function verifier(string $quoi, bool $ok, string $remede = '', bool $bloquant = true): void
    {
        if ($ok) {
            $this->line("    <fg=green>OK</>    {$quoi}");

            return;
        }

        if ($bloquant) {
            $this->anomalies[] = $quoi.($remede ? ' — '.$remede : '');
            $this->line("    <fg=red>ÉCHEC</> {$quoi}");
        } else {
            $this->avertissements[] = $quoi.($remede ? ' — '.$remede : '');
            $this->line("    <fg=yellow>ATTENTION</> {$quoi}");
        }

        if ($remede !== '') {
            $this->line("          <fg=gray>{$remede}</>");
        }
    }

    private function environnement(): void
    {
        $this->verifier(
            'APP_KEY est définie',
            trim((string) config('app.key')) !== '',
            'Lancez « php artisan key:generate ». Sans elle, aucune session ni aucun cookie chiffré.',
        );

        $this->verifier(
            'APP_ENV vaut « production »',
            config('app.env') === 'production',
            'Dans .env : APP_ENV=production. Hors production, les tolérances de développement '
            .'restent actives (origines du réseau local admises par CORS et par Sanctum).',
        );

        $this->verifier(
            'APP_DEBUG est désactivé',
            ! config('app.debug'),
            'Dans .env : APP_DEBUG=false. Activé, une erreur expose le code, les requêtes SQL et des valeurs de configuration.',
        );

        $this->verifier(
            'APP_URL est renseignée et n\'est pas localhost',
            ! str_contains((string) config('app.url'), 'localhost'),
            'Dans .env : APP_URL=https://votre-domaine. Elle sert aux liens absolus des documents.',
            false,
        );

        $this->verifier(
            'SESSION_DOMAIN est vide',
            trim((string) config('session.domain')) === '',
            'Laissez SESSION_DOMAIN vide dans .env. Renseigné, le cookie porte ce domaine et '
            .'un navigateur ouvert sur un autre hôte le refuse : la connexion ne tient pas.',
        );

        $this->verifier(
            'La locale est le français',
            config('app.locale') === 'fr',
            'Dans .env : APP_LOCALE=fr. Sinon les messages de validation sortent en anglais.',
            false,
        );
    }

    private function extensions(): void
    {
        foreach (['sqlsrv' => 'pilote SQL Server', 'pdo_sqlsrv' => 'PDO SQL Server',
            'gd' => 'images (photos des élèves)', 'zip' => 'classeurs Excel',
            'mbstring' => 'textes accentués', 'openssl' => 'chiffrement'] as $ext => $role) {
            $this->verifier(
                "L'extension {$ext} est chargée ({$role})",
                extension_loaded($ext),
                "Activez extension={$ext} dans php.ini, puis redémarrez Apache.",
            );
        }
    }

    private function bases(): void
    {
        foreach ([
            'master' => 'comptes et sociétés (dbmasterbacou)',
            'economat' => 'données scolaires (ECONOMAT)',
            'ecoprim' => 'tables propres à NEXORA',
        ] as $connexion => $role) {
            $message = '';
            try {
                DB::connection($connexion)->select('SELECT 1');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
                $message = $this->causeLisible($e->getMessage());
            }

            $this->verifier("Connexion « {$connexion} » — {$role}", $ok, $message);
        }

        try {
            $enRetard = DB::connection('ecoprim')->table('migrations')->exists();
            $this->verifier(
                'Les migrations NEXORA sont passées',
                $enRetard,
                'Lancez « php artisan migrate --database=ecoprim --path=database/migrations/console --force ».',
            );
        } catch (Throwable $e) {
            $this->verifier('Les migrations NEXORA sont passées', false,
                'Table « migrations » introuvable : lancez « php artisan migrate --database=ecoprim '
                .'--path=database/migrations/console --force ».');
        }
    }

    /** Les tables sans lesquelles un écran entier reste vide ou refuse d'écrire. */
    private function tables(): void
    {
        $attendues = [
            'economat' => ['T_ANNEEACADEMIQUE', 'T_CLASSE', 'T_NIVEAU', 'T_MATIERE',
                'T_ETUDIANT', 'T_PROFESSEUR', 'V_NOTECLASSE', 'T_NOTEENTETE', 'T_NOTEDETAILS'],
            'master' => ['RH_USER', 'US_SOCIETE', 'US_APPLICATIONS', 'US_SOCIETE_APPLICATION',
                'societe_utilisateur'],
            // Toutes les tables créées par database/migrations/console : ce sont celles que
            // NEXORA possède en propre (coefficients, évaluations, devoirs…), et une seule
            // manquante laisse un écran vide ou refuse d'enregistrer.
            'ecoprim' => ['console_societes', 'console_etablissements', 'console_roles',
                'console_affectations', 'console_affectation_eleves', 'console_role_permissions',
                'evaluations', 'devoirs', 'evenements', 'coefficients_matiere',
                'aide_categories', 'aide_articles',
                'nouveautes_versions', 'nouveautes_elements', 'nouveautes_lues'],
        ];

        foreach ($attendues as $connexion => $tables) {
            $manquantes = [];
            foreach ($tables as $table) {
                if (! $this->existe($connexion, $table)) {
                    $manquantes[] = $table;
                }
            }

            $this->verifier(
                strtoupper($connexion).' : '.(count($tables) - count($manquantes)).'/'.count($tables).' tables',
                $manquantes === [],
                $manquantes === [] ? '' : 'Manquantes : '.implode(', ', $manquantes).($connexion === 'ecoprim'
                    ? ' — lancez : php artisan migrate --database=ecoprim --path=database/migrations/console --force'
                    : ''),
            );
        }
    }

    /**
     * La table OU la vue existe-t-elle et se laisse-t-elle lire ?
     *
     * `hasTable()` ne voit PAS les vues sur SQL Server : V_NOTECLASSE, qui porte toutes les
     * notes, était annoncée manquante alors qu'elle répond. Un faux positif ici enverrait
     * chercher un problème inexistant la veille d'une mise en ligne. On retombe donc sur une
     * lecture à vide, qui tranche pour de bon — et qui vérifie au passage que le compte a
     * bien le droit de lire, ce que l'existence seule ne dit pas.
     */
    private function existe(string $connexion, string $table): bool
    {
        try {
            if (Schema::connection($connexion)->hasTable($table)) {
                return true;
            }
        } catch (Throwable $e) {
            // On tranche par la lecture ci-dessous.
        }

        try {
            DB::connection($connexion)->table($table)->limit(0)->get();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function dossiers(): void
    {
        foreach ([
            storage_path('framework/sessions') => 'sessions',
            storage_path('framework/views') => 'vues compilées',
            storage_path('logs') => 'journaux',
            storage_path('app') => 'fichiers déposés (imports Excel)',
        ] as $chemin => $role) {
            $this->verifier(
                "Inscriptible : {$role}",
                is_dir($chemin) && is_writable($chemin),
                "Donnez les droits d'écriture à Apache sur {$chemin}.",
            );
        }

        $photos = app(PhotoEleveStockage::class)->dossier();
        $partage = $photos !== '' && ! str_contains($photos, 'storage'.DIRECTORY_SEPARATOR.'app');

        $this->verifier(
            'Le dossier des photos est inscriptible',
            $photos !== '' && is_dir($photos) && is_writable($photos),
            "Créez {$photos} et donnez-y les droits à Apache.",
        );

        $this->verifier(
            'Les photos vont dans le dossier partagé lu par ECONOMAT',
            $partage,
            'Renseignez NEXORA_PHOTOS_ELEVES dans .env. Sans lui, les photos restent dans le '
            .'stockage local de NEXORA et ECONOMAT ne les verra pas.',
            false,
        );
    }

    private function limites(): void
    {
        $attendu = (int) config('nexora.photos_eleves.taille_max_ko');
        $upload = $this->enKo(ini_get('upload_max_filesize'));
        $post = $this->enKo(ini_get('post_max_size'));

        $this->verifier(
            "upload_max_filesize (".ini_get('upload_max_filesize').") couvre les {$attendu} Ko annoncés",
            $upload >= $attendu,
            'PHP tranche AVANT Laravel : au-delà de cette limite le fichier n\'atteint même pas '
            ."la validation. Dans php.ini : upload_max_filesize = ".ceil($attendu / 1024)."M.",
        );

        $this->verifier(
            "post_max_size (".ini_get('post_max_size').") dépasse upload_max_filesize",
            $post > $upload,
            'Le corps de la requête porte plus que le fichier seul. Dans php.ini : '
            .'post_max_size au moins 2 Mo au-dessus de upload_max_filesize.',
        );

        $this->verifier(
            'memory_limit vaut au moins 256M',
            $this->enKo(ini_get('memory_limit')) >= 262144 || (int) ini_get('memory_limit') === -1,
            'Dans php.ini : memory_limit = 256M. Les exports Excel d\'une année entière tiennent mal en dessous.',
            false,
        );
    }

    private function interface(): void
    {
        $page = public_path('spa/index.html');

        $this->verifier(
            "L'interface est construite (public/spa)",
            file_exists($page),
            'Dans frontend/ : « npm ci && npm run build ». Sans cela, la racine du site rend du JSON au lieu de l\'application.',
        );

        if (file_exists($page)) {
            $this->verifier(
                'Les fichiers de l\'interface sont présents',
                is_dir(public_path('spa/assets')),
                'Le dossier public/spa/assets manque : relancez « npm run build ».',
            );
        }

        $this->verifier(
            'Le module de réécriture d\'Apache est actif',
            file_exists(public_path('.htaccess')),
            'public/.htaccess est absent : sans lui, Apache ne réécrit rien et toute l\'API répond 404.',
        );
    }

    private function conclusion(): int
    {
        if ($this->anomalies === [] && $this->avertissements === []) {
            $this->line('  <fg=green;options=bold>Tout est en ordre. L\'installation peut être mise en ligne.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($this->avertissements !== []) {
            $this->line('  <fg=yellow;options=bold>À regarder ('.count($this->avertissements).') — n\'empêche pas de démarrer :</>');
            foreach ($this->avertissements as $a) {
                $this->line('    · '.$a);
            }
            $this->newLine();
        }

        if ($this->anomalies !== []) {
            $this->line('  <fg=red;options=bold>À corriger ('.count($this->anomalies).') avant la mise en ligne :</>');
            foreach ($this->anomalies as $a) {
                $this->line('    · '.$a);
            }
            $this->newLine();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Traduire les messages d'erreur les plus courants en cause et en remède. */
    private function causeLisible(string $message): string
    {
        return match (true) {
            str_contains($message, 'password of the account has expired')
                => 'Le mot de passe du compte SQL a expiré. Dans SSMS : ALTER LOGIN <compte> WITH PASSWORD = \'...\' UNLOCK;',
            str_contains($message, 'Login failed')
                => 'Identifiants refusés par SQL Server : vérifiez DB_USERNAME et DB_PASSWORD dans .env.',
            str_contains($message, 'could not find driver')
                => 'Pilote absent : activez extension=sqlsrv et extension=pdo_sqlsrv dans php.ini.',
            str_contains($message, 'Cannot open database')
                => 'La base existe-t-elle, et le compte y a-t-il accès ?',
            default => trim(substr(preg_replace('/\s+/', ' ', $message), 0, 160)),
        };
    }

    private function enKo(string $valeur): int
    {
        $valeur = trim($valeur);
        $unite = strtolower(substr($valeur, -1));
        $nombre = (float) $valeur;

        return (int) match ($unite) {
            'g' => $nombre * 1024 * 1024,
            'm' => $nombre * 1024,
            'k' => $nombre,
            default => $nombre / 1024,
        };
    }
}
