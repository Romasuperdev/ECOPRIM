<?php

namespace App\Http\Controllers\Api\V1\Parametres;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use App\Support\EtablissementDeTravail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Paramétrage de la messagerie sortante (SMTP) — ECONOMAT.dbo.T_MAIL_DIFFUSION.
 * Une configuration par établissement ; création + modification, jamais de suppression.
 *
 * Réservé aux administrateurs de l'établissement visé (route `console:etablissement`,
 * puis EtablissementDeTravail::exigerAdministre) : ces paramètres portent le mot de passe
 * de la messagerie de l'école, et désignent le serveur à qui NEXORA le présente.
 */
class MailConfigController extends Controller
{
    /**
     * Ports SMTP admis : relais (25), SMTPS (465), soumission (587) et le 2525 que
     * proposent la plupart des fournisseurs. Tout autre port ferait du serveur NEXORA un
     * outil pour sonder des services internes.
     */
    public const PORTS_SMTP = [25, 465, 587, 2525];

    /** Un nom d'hôte ou une adresse IP : ni schéma, ni chemin, ni port, ni espace. */
    public const MOTIF_HOTE = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,78}[A-Za-z0-9])?$/';

    private function t(): EconomatTable
    {
        return EconomatTable::pour('T_MAIL_DIFFUSION', 'ID_MAIL_DIF');
    }

    /** La configuration de cet établissement — jamais celle d'un autre. */
    public static function configuration(string $etab): ?object
    {
        return EconomatTable::pour('T_MAIL_DIFFUSION', 'ID_MAIL_DIF')->requete()
            ->where('code_etab', $etab)
            ->orderByDesc('ID_MAIL_DIF')->first();
    }

    public function show(Request $request)
    {
        $etab = EtablissementDeTravail::exigerAdministre($request->user());

        try {
            $l = self::configuration($etab);
        } catch (Throwable $e) {
            Log::warning('Paramètres SMTP illisibles.', ['etablissement' => $etab, 'exception' => $e]);
            $l = null;
        }

        return $l ? $this->ligne($l) : [
            'id' => null, 'adresse' => null, 'serveur_smtp' => null, 'port_smtp' => 587,
            'mot_de_passe_defini' => false, 'etablissement_code' => $etab, 'societe_code' => null,
        ];
    }

    private function ligne(object $l): array
    {
        return [
            'id' => $l->ID_MAIL_DIF,
            'adresse' => $l->ADRESS_MAIL,
            'serveur_smtp' => $l->SERVEUR_SMTP,
            'port_smtp' => $l->PORT_SMTP,
            // Le mot de passe n'est jamais renvoyé.
            'mot_de_passe_defini' => ! empty($l->MOT_PASS),
            'etablissement_code' => $l->code_etab,
            'societe_code' => $l->CODESOCIETE,
        ];
    }

    public function store(Request $request)
    {
        $etab = EtablissementDeTravail::exigerAdministre($request->user());

        $d = $request->validate([
            'adresse' => ['required', 'email', 'max:80'],
            'serveur_smtp' => ['required', 'string', 'max:80', 'regex:'.self::MOTIF_HOTE],
            'port_smtp' => ['required', 'integer', Rule::in(self::PORTS_SMTP)],
            'mot_de_passe' => ['nullable', 'string', 'max:50'],
        ], [
            'serveur_smtp.regex' => 'Indiquez le seul nom du serveur (ex. smtp.ecole.ci), sans « smtp:// » ni port.',
            'port_smtp.in' => 'Port SMTP non autorisé : choisissez '.implode(', ', self::PORTS_SMTP).'.',
        ]);

        $existante = self::configuration($etab);

        // Le mot de passe enregistré a été confié à UN serveur. Changer de serveur (ou de
        // port) sans le ressaisir l'enverrait au nouveau — qui peut être celui de n'importe
        // qui : on exige de le saisir à nouveau.
        if ($existante && ! empty($existante->MOT_PASS) && empty($d['mot_de_passe'])
            && (mb_strtolower(trim((string) $existante->SERVEUR_SMTP)) !== mb_strtolower($d['serveur_smtp'])
                || (int) $existante->PORT_SMTP !== (int) $d['port_smtp'])) {
            throw ValidationException::withMessages([
                'mot_de_passe' => ['Le serveur ou le port change : ressaisissez le mot de passe de la messagerie.'],
            ]);
        }

        $ligne = [
            'ADRESS_MAIL' => $d['adresse'],
            'SERVEUR_SMTP' => $d['serveur_smtp'],
            'PORT_SMTP' => $d['port_smtp'],
            'code_etab' => $etab,
        ];
        if (! empty($d['mot_de_passe'])) {
            $ligne['MOT_PASS'] = $d['mot_de_passe'];
        }

        if ($existante) {
            $this->t()->modifier($existante->ID_MAIL_DIF, $ligne);
            $id = $existante->ID_MAIL_DIF;
        } else {
            $societe = EtablissementDeTravail::societeDe($etab);
            $id = $this->t()->inserer($ligne + ($societe !== null ? ['CODESOCIETE' => $societe] : []));
        }

        return response()->json($this->ligne($this->t()->trouver($id)));
    }
}
