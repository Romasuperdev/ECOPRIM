<?php

namespace App\Services;

use App\Models\Console\Societe;
use Illuminate\Support\Facades\DB;
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
 *   - NOMBASE n'est jamais écrit : un déclencheur de la base le calcule à la création, et
 *     STATUT_BASE garde sa valeur par défaut — la mise en service de la base de la société
 *     appartient à la plateforme, pas à ECOPRIM.
 *   - NUMAUTO est une identité côté SQL Server ; `id` (uniqueidentifier) est posé ici, ce
 *     qui permet de le connaître sans relire la ligne (US_SOCIETE_APPLICATION.societe_id).
 */
class UsSocieteEcrivain
{
    /** Largeurs réelles des colonnes US_SOCIETE (et de la copie ECOPRIM, plus étroite pour l'email). */
    public const LARGEURS = [
        'code' => 17,   // CODESOCIETE varchar(17)
        'nom' => 50,    // NOMSOCIETE varchar(50)
        'email' => 150, // EMAILSOCIETE varchar(255), mais console_societes.email varchar(150)
    ];

    /** CAPITAL est un numeric(18,0). */
    public const CAPITAL_MAX = 999999999999999999;

    public function trouver(string $code): ?object
    {
        return $this->table()->where('CODESOCIETE', $code)->first();
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
            $this->reprendre($code, ['actif' => true]);
        });

        return $this->trouver($code);
    }

    public function modifier(string $code, array $d): object
    {
        DB::connection('master')->transaction(function () use ($code, $d) {
            $this->table()->where('CODESOCIETE', $code)->update($this->colonnes($d));
            $this->reprendre($code);
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
     * Aligne la copie ECOPRIM sur US_SOCIETE (nom, email), en la créant au besoin. Les
     * compléments propres à ECOPRIM (ville, adresse…) ne sont pas touchés.
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

    private function colonnes(array $d): array
    {
        $ligne = [];
        if (array_key_exists('nom', $d)) {
            $ligne['NOMSOCIETE'] = $this->ouNull($d['nom']);
        }
        if (array_key_exists('email', $d)) {
            $ligne['EMAILSOCIETE'] = $this->ouNull($d['email']);
        }
        if (array_key_exists('codification', $d)) {
            $ligne['CODIFICATION'] = ! empty($d['codification']) ? 1 : 0;
        }
        if (array_key_exists('capital', $d)) {
            $ligne['CAPITAL'] = $d['capital'] === null || $d['capital'] === '' ? null : (int) $d['capital'];
        }

        return $ligne;
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
