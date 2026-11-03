<?php

namespace App\Services;

use App\Models\Console\Societe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Écriture contrôlée dans dbmasterbacou.US_SOCIETE, et reprise automatique dans la copie
 * ECOPRIM (console_societes) sur laquelle s'appuie le reste de l'application : barre de
 * société, contexte de la console, établissements.
 *
 * Règles :
 *   - CRÉATION et MODIFICATION uniquement — jamais de DELETE. US_SOCIETE est partagée avec
 *     toute la suite (licences, sauvegardes, bases des sociétés y sont rattachées) : une
 *     société « supprimée » depuis la console est DÉSACTIVÉE dans ECOPRIM, et réactivable.
 *   - Le CODE ne se modifie pas : il est repris tel quel par les établissements, les
 *     affectations et les rattachements, qu'un renommage laisserait orphelins.
 *   - Colonnes TECHNIQUES jamais écrites : NOMBASE (calculé par un déclencheur), STATUT_BASE,
 *     NB_ETAB / NB_APPART / NB_USER, dates et motifs de provisionnement ou de suppression,
 *     CODEEXPERT, LOGO. La mise en service de la base d'une société appartient à la
 *     plateforme, pas à ECOPRIM.
 *   - NUMAUTO est une identité côté SQL Server ; `id` (uniqueidentifier) est posé ici, ce
 *     qui permet de le connaître sans relire la ligne (US_SOCIETE_APPLICATION.societe_id).
 *
 * STRUCTURE RÉELLE. La largeur de chaque colonne est lue dans la base (et non supposée) :
 * une saisie trop longue est refusée par la validation au lieu de faire échouer l'INSERT.
 * Une colonne absente de la base du serveur (version différente de dbmasterbacou) n'est
 * simplement ni proposée ni écrite.
 */
class UsSocieteEcrivain
{
    /** Largeurs connues, utilisées quand la base ne donne pas la sienne (SQLite des tests). */
    public const LARGEURS = [
        'code' => 17,   // CODESOCIETE varchar(17)
        'nom' => 50,    // NOMSOCIETE varchar(50)
        'email' => 150, // EMAILSOCIETE varchar(255), mais console_societes.email varchar(150)
    ];

    /** CAPITAL est un numeric(18,0). */
    public const CAPITAL_MAX = 999999999999999999;

    /**
     * Les champs du formulaire : clé API => [colonne US_SOCIETE, nature, largeur par défaut].
     * La largeur par défaut ne sert que si la base n'en donne pas ; `plafond` borne en plus
     * ce que la copie ECOPRIM peut recevoir.
     */
    public const CHAMPS = [
        // Identité
        'nom' => ['colonne' => 'NOMSOCIETE', 'nature' => 'texte', 'largeur' => 50],
        'forme_juridique' => ['colonne' => 'FORMJURIDQIUE', 'nature' => 'texte', 'largeur' => 50],
        'regime_fiscal' => ['colonne' => 'REGIMEFISCAL', 'nature' => 'texte', 'largeur' => 50],
        'activite' => ['colonne' => 'ACTIVITESOCIETE', 'nature' => 'texte', 'largeur' => 100],
        'activite_secondaire' => ['colonne' => 'ACTIVITESECONDAIRE', 'nature' => 'texte', 'largeur' => 100],
        'capital' => ['colonne' => 'CAPITAL', 'nature' => 'entier'],
        'codification' => ['colonne' => 'CODIFICATION', 'nature' => 'booleen'],
        // Coordonnées
        'adresse' => ['colonne' => 'AD1SOCIETE', 'nature' => 'texte', 'largeur' => 50, 'plafond' => 200],
        'adresse2' => ['colonne' => 'AD2SOCIETE', 'nature' => 'texte', 'largeur' => 50],
        'complement' => ['colonne' => 'COMPLEMENTSOCIETE', 'nature' => 'texte', 'largeur' => 50],
        'code_postal' => ['colonne' => 'CPSOCIETE', 'nature' => 'texte', 'largeur' => 20],
        'ville' => ['colonne' => 'VILLESOCIETE', 'nature' => 'texte', 'largeur' => 50, 'plafond' => 100],
        'lieu' => ['colonne' => 'LIEU', 'nature' => 'texte', 'largeur' => 50],
        'pays' => ['colonne' => 'PAYSSOCIETE', 'nature' => 'texte', 'largeur' => 50],
        'telephone' => ['colonne' => 'TELSOCIETE', 'nature' => 'texte', 'largeur' => 20, 'plafond' => 30],
        'fax' => ['colonne' => 'FAXSOCIETE', 'nature' => 'texte', 'largeur' => 20],
        'email' => ['colonne' => 'EMAILSOCIETE', 'nature' => 'email', 'largeur' => 150, 'plafond' => 150],
        'site' => ['colonne' => 'SITESOCIETE', 'nature' => 'texte', 'largeur' => 100],
        // Représentant légal
        'civilite_representant' => ['colonne' => 'CIVILITEREPRESENTANT', 'nature' => 'texte', 'largeur' => 20],
        'representant' => ['colonne' => 'NOMPRENOMREPRESENTANT', 'nature' => 'texte', 'largeur' => 150, 'plafond' => 150],
        'fonction_representant' => ['colonne' => 'FONCTIONREPRESENTANT', 'nature' => 'texte', 'largeur' => 50],
        'tel_representant' => ['colonne' => 'TELREPRESENTANT', 'nature' => 'texte', 'largeur' => 20],
        'cel_representant' => ['colonne' => 'CELREPRESENTANT', 'nature' => 'texte', 'largeur' => 20],
    ];

    /** Champs que la copie ECOPRIM (console_societes) reprend, en plus du nom et de l'email. */
    private const COPIE = ['ville', 'adresse', 'telephone', 'representant'];

    /** @var array<string, array{type: string, largeur: ?int}>|null colonne (majuscules) => structure */
    private ?array $structure = null;

    public function trouver(string $code): ?object
    {
        return $this->table()->where('CODESOCIETE', $code)->first();
    }

    /**
     * Les champs réellement disponibles sur cette base, avec leur largeur effective : c'est
     * ce que le formulaire affiche, et ce que la validation applique.
     *
     * @return array<string, array{nature: string, largeur: ?int}>
     */
    public function champsDisponibles(): array
    {
        $structure = $this->structure();
        $champs = [];

        foreach (self::CHAMPS as $cle => $def) {
            $col = $structure[strtoupper($def['colonne'])] ?? null;
            if ($structure !== [] && ! $col) {
                continue; // colonne absente de cette version de dbmasterbacou
            }

            $largeur = null;
            if (in_array($def['nature'], ['texte', 'email'], true)) {
                $largeur = $col['largeur'] ?? $def['largeur'];
                if (isset($def['plafond'])) {
                    $largeur = min($largeur, $def['plafond']);
                }
            }

            $champs[$cle] = ['nature' => $def['nature'], 'largeur' => $largeur];
        }

        return $champs;
    }

    /** Règles de validation des champs disponibles (le code est validé à part). */
    public function regles(): array
    {
        $regles = [];
        foreach ($this->champsDisponibles() as $cle => $c) {
            $regles[$cle] = match ($c['nature']) {
                'entier' => ['nullable', 'integer', 'min:0', 'max:'.self::CAPITAL_MAX],
                'booleen' => ['sometimes', 'boolean'],
                'email' => ['nullable', 'email', 'max:'.$c['largeur']],
                default => ['nullable', 'string', 'max:'.$c['largeur']],
            };
        }
        $regles['nom'][0] = 'required';

        return $regles;
    }

    /** La fiche d'une société, telle que le formulaire la lit. */
    public function fiche(object $src): array
    {
        $fiche = ['code' => trim((string) $src->CODESOCIETE)];

        foreach ($this->champsDisponibles() as $cle => $c) {
            $v = $src->{self::CHAMPS[$cle]['colonne']} ?? null;
            $fiche[$cle] = match ($c['nature']) {
                'entier' => $v === null || $v === '' ? null : (int) $v,
                'booleen' => (bool) $v,
                default => ($t = trim((string) $v)) === '' ? null : $t,
            };
        }

        return $fiche;
    }

    /** Crée la société dans US_SOCIETE puis la reprend dans ECOPRIM — tout ou rien. */
    public function creer(array $d): object
    {
        $code = trim($d['code']);
        $identity = $this->numautoEstIdentity();

        DB::connection('master')->transaction(function () use ($d, $code, $identity) {
            $ligne = $this->colonnes($d) + [
                'CODESOCIETE' => $code,
                'id' => (string) Str::uuid(),
            ];

            if (! $identity) {
                $ligne['NUMAUTO'] = $this->prochainNumauto();
            }

            $this->table()->insert($ligne);

            // La copie ECOPRIM suit dans la même transaction : si elle échoue, la société
            // n'est pas créée à moitié.
            $this->reprendre($code, ['actif' => true] + $this->pourCopie($d));
        });

        return $this->trouver($code);
    }

    public function modifier(string $code, array $d): object
    {
        DB::connection('master')->transaction(function () use ($code, $d) {
            $colonnes = $this->colonnes($d);
            if ($colonnes !== []) {
                $this->table()->where('CODESOCIETE', $code)->update($colonnes);
            }
            $this->reprendre($code, $this->pourCopie($d));
        });

        return $this->trouver($code);
    }

    /**
     * Désactivation logique (dans ECOPRIM) — remplace la suppression. Une société pas
     * encore reprise l'est au passage : c'est la copie qui porte l'état actif / inactif.
     */
    public function definirActif(string $code, bool $actif): Societe
    {
        return $this->reprendre($code, ['actif' => $actif]);
    }

    /**
     * Aligne la copie ECOPRIM sur US_SOCIETE (nom, email, et les coordonnées saisies), en la
     * créant au besoin. Un complément propre à ECOPRIM qui n'a pas été saisi ici reste tel quel.
     */
    private function reprendre(string $code, array $extra = []): Societe
    {
        $src = $this->trouver($code);
        abort_unless($src, 404, 'Société introuvable.');

        $nom = trim((string) ($src->NOMSOCIETE ?? '')) ?: $code;
        $email = trim((string) ($src->EMAILSOCIETE ?? '')) ?: null;

        $societe = Societe::firstOrNew(['code' => $code]);
        if (! $societe->exists) {
            $societe->actif = true;
        }
        $societe->fill(['nom' => $nom, 'email' => $email] + $extra)->save();

        return $societe;
    }

    /** Les coordonnées saisies que la copie ECOPRIM doit suivre. */
    private function pourCopie(array $d): array
    {
        $copie = [];
        foreach (self::COPIE as $cle) {
            if (array_key_exists($cle, $d)) {
                $copie[$cle] = $this->ouNull($d[$cle]);
            }
        }

        return $copie;
    }

    /** Valeurs saisies => colonnes US_SOCIETE, pour les seuls champs disponibles sur cette base. */
    private function colonnes(array $d): array
    {
        $ligne = [];
        foreach ($this->champsDisponibles() as $cle => $c) {
            if (! array_key_exists($cle, $d)) {
                continue;
            }
            $v = $d[$cle];
            $ligne[self::CHAMPS[$cle]['colonne']] = match ($c['nature']) {
                'entier' => $v === null || $v === '' ? null : (int) $v,
                'booleen' => ! empty($v) ? 1 : 0,
                default => $this->ouNull($v),
            };
        }

        return $ligne;
    }

    /**
     * Colonnes réelles de US_SOCIETE et leur largeur. Vide si la structure ne peut pas être
     * lue : on se rabat alors sur les largeurs connues, sans rien retirer.
     */
    private function structure(): array
    {
        if ($this->structure !== null) {
            return $this->structure;
        }

        try {
            $colonnes = Schema::connection('master')->getColumns('US_SOCIETE');
        } catch (Throwable $e) {
            return $this->structure = [];
        }

        $sqlServer = DB::connection('master')->getDriverName() === 'sqlsrv';
        $structure = [];
        foreach ($colonnes as $c) {
            // « nvarchar(50) », « varchar(max) », « numeric(18,0) », « varchar »…
            $largeur = null;
            if (preg_match('/char\((\d+)\)/i', (string) ($c['type'] ?? ''), $m) && (int) $m[1] > 0) {
                $largeur = (int) $m[1];
                // SQL Server donne la largeur en OCTETS : 2 par caractère en nchar / nvarchar.
                if ($sqlServer && in_array(strtolower((string) ($c['type_name'] ?? '')), ['nchar', 'nvarchar'], true)) {
                    $largeur = intdiv($largeur, 2);
                }
            }
            $structure[strtoupper($c['name'])] = ['type' => (string) ($c['type_name'] ?? ''), 'largeur' => $largeur];
        }

        return $this->structure = $structure;
    }

    private function ouNull($v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function numautoEstIdentity(): bool
    {
        try {
            $v = DB::connection('master')->selectOne(
                "SELECT COLUMNPROPERTY(OBJECT_ID('dbo.US_SOCIETE'),'NUMAUTO','IsIdentity') AS est_identity"
            );

            return (bool) ($v->est_identity ?? false);
        } catch (Throwable $e) {
            return false; // SQLite des tests : compteur manuel
        }
    }

    private function prochainNumauto(): int
    {
        return ((int) $this->table()->max('NUMAUTO')) + 1;
    }

    private function table()
    {
        return DB::connection('master')->table('US_SOCIETE');
    }
}
