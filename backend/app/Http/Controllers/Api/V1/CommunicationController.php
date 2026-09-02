<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 */
class CommunicationController extends Controller
{
    private function sms(): EconomatTable
    {
        return EconomatTable::pour('T_SMS', 'id');
    }

    /** Historique des SMS envoyés, le plus récent d'abord. */
    public function historique(Request $request)
    {
        try {
            $lignes = $this->sms()->requete()
                ->when($request->filled('q'), function ($q) use ($request) {
                    $t = $request->input('q');
                    $q->where(fn ($w) => $w->where('Numero', 'like', "%{$t}%")->orWhere('Message', 'like', "%{$t}%"));
                })
                ->orderByDesc('Date')->orderByDesc('id')
                ->limit(min($request->integer('limit', 100), 500))
                ->get();
        } catch (Throwable $e) {
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
     * Enregistre un SMS par destinataire dans T_SMS. La passerelle configurée prend
     * ensuite le relais : NEXORA ne contacte pas l'opérateur directement.
     */
    private function envoyerSms(Request $request, array $d)
    {
        $config = $this->configSms($request);

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
        $config = $this->configMail($request);

        if (! $config || empty($config->SERVEUR_SMTP) || empty($config->ADRESS_MAIL)) {
            return response()->json([
                'message' => 'La messagerie sortante n’est pas configurée. Renseignez-la dans Paramètres → Messagerie (SMTP).',
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

    private function configSms(Request $request)
    {
        $etab = $request->session()->get('etablissement_code');

        try {
            return DB::connection('economat')->table('ECO_SMS_CONFIG')
                ->when($etab, fn ($q) => $q->where('CODEETABLISSEMENT', $etab))
                ->orderByDesc('IS_DEFAULT')->orderByDesc('id')->first();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function configMail(Request $request)
    {
        $etab = $request->session()->get('etablissement_code');

        try {
            return DB::connection('economat')->table('T_MAIL_DIFFUSION')
                ->when($etab, fn ($q) => $q->where('code_etab', $etab))
                ->orderByDesc('ID_MAIL_DIF')->first();
        } catch (Throwable $e) {
            return null;
        }
    }
}
