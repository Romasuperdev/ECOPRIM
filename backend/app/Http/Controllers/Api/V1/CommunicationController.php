<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Parametres\MailConfigController;
use App\Http\Controllers\Api\V1\Parametres\SmsConfigController;
use App\Http\Controllers\Controller;
use App\Models\RhUser;
use App\Services\EconomatTable;
use App\Support\EtablissementDeTravail;
use App\Support\PerimetreEtablissement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Communication : transmission d'informations par SMS ou par mail.
 *
 * SMS  : chaque message est enregistré dans ECONOMAT.dbo.T_SMS (journal / file
 *        d'envoi exploitée par la passerelle configurée dans ECO_SMS_CONFIG).
 * Mail : envoi SMTP réel avec les paramètres de T_MAIL_DIFFUSION.
 *
 * Aucune suppression : l'historique des envois n'est jamais effacé.
 *
 * Permission `envoyer_communications` (envoi ET historique) et débit limité (route). Les
 * paramètres d'envoi sont TOUJOURS ceux de l'établissement de travail, choisi et dans le
 * périmètre — jamais « la dernière configuration enregistrée », d'où qu'elle vienne.
 */
class CommunicationController extends Controller
{
    private function sms(): EconomatTable
    {
        return EconomatTable::pour('T_SMS', 'id');
    }

    /**
     * Historique des SMS envoyés, le plus récent d'abord, borné au périmètre.
     *
     * T_SMS ne porte ni société ni établissement : un envoi n'est rattaché qu'à son auteur
     * (colonne Users = login). Le périmètre passe donc par les comptes : on ne montre que
     * les envois des comptes rattachés aux établissements visibles. Sans périmètre (vue
     * générale du Super Admin), tout ; un autre compte sans établissement choisi, les siens.
     */
    public function historique(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $auteurs = $this->auteursVisibles($request->user());
        if ($auteurs === []) {
            return ['data' => []];
        }

        try {
            $lignes = $this->sms()->requete()
                ->when($auteurs !== null, fn ($q) => $q->whereIn('Users', $auteurs))
                ->when($request->filled('q'), function ($q) use ($request) {
                    $t = $request->input('q');
                    $q->where(fn ($w) => $w->where('Numero', 'like', "%{$t}%")->orWhere('Message', 'like', "%{$t}%"));
                })
                ->orderByDesc('Date')->orderByDesc('id')
                ->limit($request->integer('limit', 100))
                ->get();
        } catch (Throwable $e) {
            Log::warning('Historique des SMS illisible.', ['exception' => $e]);
            $lignes = collect();
        }

        return ['data' => $lignes->map(fn ($l) => [
            'id' => $l->id,
            'date' => $l->Date,
            'heure' => $l->Heure,
            'numero' => $l->Numero,
            'message' => $l->Message,
            'type' => $l->Type,
            'utilisateur' => $l->Users,
        ])->values()];
    }

    public function envoyer(Request $request)
    {
        $d = $request->validate([
            'canal' => ['required', 'in:sms,mail'],
            'destinataires' => ['required', 'array', 'min:1', 'max:500'],
            'destinataires.*' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:250'],
            'objet' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        return $d['canal'] === 'sms'
            ? $this->envoyerSms($request, $d)
            : $this->envoyerMail($request, $d);
    }

    /**
     * Logins dont on peut montrer les envois, ou null s'il n'y a rien à restreindre.
     *
     * Comptes rattachés : par RH_USER.Etab (établissement de rattachement) ou par une
     * affectation de la console sur l'un des établissements visibles — ou sur la société
     * de travail elle-même. Le compte connecté voit toujours ses propres envois.
     *
     * @return array<int, string>|null
     */
    private function auteursVisibles(?RhUser $user): ?array
    {
        $etablissements = PerimetreEtablissement::etablissementsVisibles();

        if ($etablissements === null) {
            // Rien en session : seul le Super Admin a une vue générale. Un autre compte
            // doit d'abord choisir son établissement — sinon il lirait toutes les écoles.
            if ($user?->isSuperAdmin()) {
                return null;
            }
            $etablissements = [];
        }

        $logins = [trim((string) ($user->Login ?? ''))];

        if ($etablissements !== []) {
            try {
                $logins = array_merge($logins, DB::connection('master')->table('RH_USER')
                    ->whereIn('Etab', $etablissements)->pluck('Login')->all());
            } catch (Throwable $e) {
                Log::warning('Comptes des établissements illisibles (historique SMS).', ['exception' => $e]);
            }

            try {
                $societe = PerimetreEtablissement::societe();
                $ids = DB::connection('ecoprim')->table('console_affectations')
                    ->where(fn ($q) => $q->whereIn('etablissement_code', $etablissements)
                        ->when($societe !== null, fn ($q) => $q->orWhere(fn ($w) => $w
                            ->where('societe_code', $societe)->whereNull('etablissement_code'))))
                    ->pluck('rh_user_id')->unique()->all();

                if ($ids !== []) {
                    $logins = array_merge($logins, DB::connection('master')->table('RH_USER')
                        ->whereIn('Id', $ids)->pluck('Login')->all());
                }
            } catch (Throwable $e) {
                Log::warning('Affectations illisibles (historique SMS).', ['exception' => $e]);
            }
        }

        return array_values(array_unique(array_filter(array_map(fn ($l) => trim((string) $l), $logins))));
    }

    /**
     * Enregistre un SMS par destinataire dans T_SMS. La passerelle configurée prend
     * ensuite le relais : NEXORA ne contacte pas l'opérateur directement.
     */
    private function envoyerSms(Request $request, array $d)
    {
        $config = $this->configSms(EtablissementDeTravail::exiger());

        if (! $config || ! $config->ENABLED) {
            return response()->json([
                'message' => 'La passerelle SMS n’est pas activée. Configurez-la dans Paramètres → Passerelle SMS.',
            ], 422);
        }

        $utilisateur = (string) ($request->user()->Login ?? 'NEXORA');
        $envoyes = 0;

        foreach ($d['destinataires'] as $numero) {
            $this->sms()->inserer([
                'Date' => now()->toDateString(),
                'Heure' => now()->format('H:i'),
                'Numero' => $numero,
                'Message' => $d['message'],
                'Users' => $utilisateur,
                'Type' => $d['type'] ?? 'INFO',
            ]);
            $envoyes++;
        }

        return response()->json([
            'canal' => 'sms',
            'envoyes' => $envoyes,
            'message' => "{$envoyes} SMS déposé(s) dans la file d’envoi.",
        ], 201);
    }

    /** Envoi SMTP réel avec les paramètres enregistrés dans T_MAIL_DIFFUSION. */
    private function envoyerMail(Request $request, array $d)
    {
        $config = $this->configMail(EtablissementDeTravail::exiger());

        if (! $config || empty($config->SERVEUR_SMTP) || empty($config->ADRESS_MAIL)) {
            return response()->json([
                'message' => 'La messagerie sortante n’est pas configurée. Renseignez-la dans Paramètres → Messagerie (SMTP).',
            ], 422);
        }

        // Une configuration antérieure aux contrôles de l'écran peut viser n'importe quel
        // hôte ou port : on ne s'en sert pas tant qu'elle n'a pas été corrigée.
        if (! preg_match(MailConfigController::MOTIF_HOTE, trim((string) $config->SERVEUR_SMTP))
            || ! in_array((int) ($config->PORT_SMTP ?: 587), MailConfigController::PORTS_SMTP, true)) {
            return response()->json([
                'message' => 'Le serveur ou le port de la messagerie n’est pas admis. Corrigez-le dans Paramètres → Messagerie (SMTP).',
            ], 422);
        }

        config([
            'mail.mailers.nexora' => [
                'transport' => 'smtp',
                'host' => $config->SERVEUR_SMTP,
                'port' => (int) ($config->PORT_SMTP ?: 587),
                'username' => $config->ADRESS_MAIL,
                'password' => $config->MOT_PASS,
                'encryption' => 'tls',
                'timeout' => 15,
            ],
        ]);

        $objet = $d['objet'] ?? 'Information';
        $corps = $d['message'];
        $envoyes = 0;
        $echecs = [];

        foreach ($d['destinataires'] as $adresse) {
            if (! filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
                $echecs[] = $adresse;

                continue;
            }
            try {
                Mail::mailer('nexora')->raw($corps, function ($m) use ($adresse, $objet, $config) {
                    $m->to($adresse)->subject($objet)->from($config->ADRESS_MAIL);
                });
                $envoyes++;
            } catch (Throwable $e) {
                Log::warning('Envoi de mail en échec.', ['destinataire' => $adresse, 'exception' => $e]);
                $echecs[] = $adresse;
            }
        }

        return response()->json([
            'canal' => 'mail',
            'envoyes' => $envoyes,
            'echecs' => $echecs,
            'message' => "{$envoyes} mail(s) envoyé(s)".(count($echecs) ? ', '.count($echecs).' en échec.' : '.'),
        ], count($echecs) && ! $envoyes ? 422 : 201);
    }

    private function configSms(string $etab): ?object
    {
        try {
            return SmsConfigController::configuration($etab);
        } catch (Throwable $e) {
            Log::warning('Paramètres SMS illisibles.', ['etablissement' => $etab, 'exception' => $e]);

            return null;
        }
    }

    private function configMail(string $etab): ?object
    {
        try {
            return MailConfigController::configuration($etab);
        } catch (Throwable $e) {
            Log::warning('Paramètres SMTP illisibles.', ['etablissement' => $etab, 'exception' => $e]);

            return null;
        }
    }
}
