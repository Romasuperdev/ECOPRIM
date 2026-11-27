<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Envoi Web Push (norme VAPID, RFC 8292) vers les téléphones et navigateurs abonnés.
 *
 * SANS CONTENU, À DESSEIN. On n'envoie qu'un « réveil » vide : le service worker du
 * téléphone, réveillé, va lui-même chercher la dernière notification non lue auprès de
 * l'API (avec la session de l'utilisateur) et l'affiche. Un contenu chiffré (RFC 8291)
 * demanderait une bibliothèque de plus ; le réveil vide n'a besoin que d'un jeton signé,
 * et rien de personnel ne transite par les serveurs de Google ou d'Apple.
 *
 * Fonctionne pour Chrome / Android, Firefox, Edge, et Safari sur iPhone (iOS 16.4 et plus,
 * application ajoutée à l'écran d'accueil).
 *
 * LES CLÉS. Lues dans .env (NEXORA_PUSH_CLE_PUBLIQUE / NEXORA_PUSH_CLE_PRIVEE) si elles y
 * sont ; sinon générées une fois et gardées dans storage/app/push-vapid.json. Une mise en
 * ligne par copie de fichiers n'a ainsi rien à configurer — mais il ne faut pas effacer ce
 * fichier : de nouvelles clés rendraient caducs tous les abonnements existants.
 */
class WebPush
{
    private ?array $cles = null;

    /** La clé publique (base64url), à transmettre au navigateur pour s'abonner. */
    public function clePublique(): ?string
    {
        return $this->cles()['publique'] ?? null;
    }

    public function disponible(): bool
    {
        return $this->clePublique() !== null;
    }

    /**
     * Réveille un appareil. Renvoie 'ok', 'expire' (abonnement à supprimer : l'appareil
     * s'est désabonné ou l'application a été désinstallée) ou 'echec'.
     */
    public function reveiller(string $endpoint): string
    {
        $cles = $this->cles();
        if ($cles === null) {
            return 'echec';
        }

        try {
            $jwt = $this->jeton($this->audience($endpoint), $cles['privee']);
            $reponse = Http::timeout(8)
                ->withHeaders([
                    'TTL' => '86400',
                    'Urgency' => 'normal',
                    'Authorization' => "vapid t={$jwt}, k={$cles['publique']}",
                ])
                ->withBody('', 'application/octet-stream')
                ->post($endpoint);

            if ($reponse->successful()) {
                return 'ok';
            }
            if (in_array($reponse->status(), [404, 410], true)) {
                return 'expire';
            }
            Log::warning('[NEXORA] Push refusé ('.$reponse->status().') : '.mb_substr($reponse->body(), 0, 200));
        } catch (Throwable $e) {
            Log::warning('[NEXORA] Push impossible : '.$e->getMessage());
        }

        return 'echec';
    }

    /** Jeton VAPID : JWT ES256 signé avec la clé privée, valable 12 h. */
    public function jeton(string $audience, string $clePriveePem): string
    {
        $entete = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $charge = $this->b64(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $this->sujet(),
        ], JSON_UNESCAPED_SLASHES));

        $cle = openssl_pkey_get_private($clePriveePem);
        if ($cle === false || ! openssl_sign("{$entete}.{$charge}", $der, $cle, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature VAPID impossible : clé privée illisible.');
        }

        return "{$entete}.{$charge}.".$this->b64($this->derVersBrut($der));
    }

    /** Génère une paire de clés P-256. @return array{publique: string, privee: string} */
    public function generer(): array
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        if ($conf = $this->configurationOpenssl()) {
            $options['config'] = $conf;
        }

        $cle = openssl_pkey_new($options);
        if ($cle === false || ! openssl_pkey_export($cle, $pem, null, $options)) {
            throw new RuntimeException('Génération des clés push impossible : '.(openssl_error_string() ?: 'OpenSSL indisponible')
                .'. Sous Windows, indiquez le fichier openssl.cnf dans la variable OPENSSL_CONF.');
        }

        $ec = openssl_pkey_get_details($cle)['ec'];
        $point = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return ['publique' => $this->b64($point), 'privee' => $pem];
    }

    public function fichierCles(): string
    {
        return storage_path('app/push-vapid.json');
    }

    /** @return array{publique: string, privee: string}|null */
    private function cles(): ?array
    {
        if ($this->cles !== null) {
            return $this->cles;
        }

        $publique = config('nexora.push.cle_publique');
        $privee = config('nexora.push.cle_privee');
        if ($publique && $privee) {
            // Dans .env, la clé privée PEM est donnée en base64 sur une ligne.
            return $this->cles = ['publique' => $publique, 'privee' => base64_decode($privee)];
        }

        $fichier = $this->fichierCles();
        try {
            if (! File::exists($fichier)) {
                File::ensureDirectoryExists(dirname($fichier));
                File::put($fichier, json_encode($this->generer(), JSON_PRETTY_PRINT));
                Log::info('[NEXORA] Clés push générées dans '.$fichier.'. Ne pas supprimer ce fichier.');
            }
            $lues = json_decode(File::get($fichier), true);

            return $this->cles = (isset($lues['publique'], $lues['privee']) ? $lues : null);
        } catch (Throwable $e) {
            Log::error('[NEXORA] Notifications push indisponibles : '.$e->getMessage());

            return null;
        }
    }

    /**
     * Sous Windows, OpenSSL ne sait pas créer de clé sans son fichier de configuration.
     * WAMP le range à côté de PHP, dans extras/ssl.
     */
    private function configurationOpenssl(): ?string
    {
        $candidats = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
            php_ini_loaded_file() ? dirname(php_ini_loaded_file()).'/extras/ssl/openssl.cnf' : null,
            PHP_OS_FAMILY === 'Windows' ? 'C:/wamp64/bin/php/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION.'/extras/ssl/openssl.cnf' : null,
        ]);

        foreach ($candidats as $chemin) {
            if (is_file($chemin)) {
                return $chemin;
            }
        }

        return null;
    }

    private function audience(string $endpoint): string
    {
        $p = parse_url($endpoint);

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    /** Apple exige un « sub » en mailto: ou https:. */
    private function sujet(): string
    {
        $sujet = (string) config('nexora.push.sujet');
        if (str_starts_with($sujet, 'mailto:') || str_starts_with($sujet, 'https://')) {
            return $sujet;
        }
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://') ? $url : 'mailto:notifications@nexora.invalid';
    }

    /** Signature DER (SEQUENCE de deux INTEGER) vers R||S sur 64 octets, comme le veut JWS. */
    private function derVersBrut(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7F;
        }
        $brut = '';
        for ($i = 0; $i < 2; $i++) {
            $longueur = ord($der[$pos + 1]);
            $entier = substr($der, $pos + 2, $longueur);
            $brut .= str_pad(ltrim($entier, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $longueur;
        }

        return $brut;
    }

    private function b64(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }
}
