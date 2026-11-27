<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Notifications\WebPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La cloche des espaces Enseignant et Parent, et l'abonnement des téléphones au push.
 *
 * Chaque requête est bornée au compte connecté (`rh_user_id`) : on ne lit, ne marque et
 * ne désabonne jamais que ce qui lui appartient.
 */
class PortailNotificationController extends Controller
{
    private const LIMITE = 50;

    public function __construct(private WebPush $push) {}

    /** Les dernières notifications et le nombre de non lues. */
    public function index(Request $request)
    {
        $miennes = $this->miennes($request);

        return [
            'non_lues' => (clone $miennes)->whereNull('lue_le')->count(),
            'notifications' => (clone $miennes)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(self::LIMITE)
                ->get()
                ->map(fn ($n) => $this->forme($n)),
        ];
    }

    /** Le compteur seul : la pastille de la cloche, interrogée souvent. */
    public function compteur(Request $request)
    {
        return ['non_lues' => $this->miennes($request)->whereNull('lue_le')->count()];
    }

    /**
     * La plus récente non lue. Appelée par le service worker quand le téléphone est réveillé
     * par un push vide (voir WebPush) : c'est elle qu'il affiche.
     */
    public function derniere(Request $request)
    {
        $n = $this->miennes($request)->whereNull('lue_le')
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        return [
            'notification' => $n ? $this->forme($n) : null,
            'non_lues' => $this->miennes($request)->whereNull('lue_le')->count(),
        ];
    }

    public function marquerLue(Request $request, int $notification)
    {
        $maj = $this->miennes($request)->where('id', $notification)->whereNull('lue_le')
            ->update(['lue_le' => now(), 'updated_at' => now()]);

        abort_if($maj === 0 && ! $this->miennes($request)->where('id', $notification)->exists(), 404);

        return $this->compteur($request);
    }

    public function toutMarquerLu(Request $request)
    {
        $this->miennes($request)->whereNull('lue_le')->update(['lue_le' => now(), 'updated_at' => now()]);

        return ['non_lues' => 0];
    }

    /** La clé publique VAPID, sans laquelle le navigateur ne peut pas s'abonner. */
    public function clePush()
    {
        $cle = $this->push->clePublique();

        return ['disponible' => $cle !== null, 'cle_publique' => $cle];
    }

    /** Enregistre (ou rattache au compte connecté) l'abonnement push de cet appareil. */
    public function abonner(Request $request)
    {
        $donnees = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['nullable', 'string', 'max:200'],
            'keys.auth' => ['nullable', 'string', 'max:100'],
        ]);

        $empreinte = hash('sha256', $donnees['endpoint']);
        $table = DB::connection('ecoprim')->table('portail_abonnements_push');
        $valeurs = [
            // Un téléphone partagé qui change de compte suit le dernier compte connecté.
            'rh_user_id' => $request->user()->Id,
            'endpoint' => $donnees['endpoint'],
            'cle_p256dh' => $donnees['keys']['p256dh'] ?? null,
            'cle_auth' => $donnees['keys']['auth'] ?? null,
            'appareil' => mb_substr((string) $request->userAgent(), 0, 255),
            'updated_at' => now(),
        ];

        if ($table->clone()->where('endpoint_empreinte', $empreinte)->exists()) {
            $table->clone()->where('endpoint_empreinte', $empreinte)->update($valeurs);
        } else {
            $table->clone()->insert($valeurs + ['endpoint_empreinte' => $empreinte, 'created_at' => now()]);
        }

        return response()->json(['abonne' => true], 201);
    }

    public function desabonner(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');

        DB::connection('ecoprim')->table('portail_abonnements_push')
            ->where('rh_user_id', $request->user()->Id)
            ->where('endpoint_empreinte', hash('sha256', $endpoint))
            ->delete();

        return ['abonne' => false];
    }

    private function miennes(Request $request)
    {
        return DB::connection('ecoprim')->table('portail_notifications')
            ->where('rh_user_id', $request->user()->Id);
    }

    private function forme(object $n): array
    {
        return [
            'id' => (int) $n->id,
            'type' => $n->type,
            'titre' => $n->titre,
            'corps' => $n->corps,
            'lien' => $n->lien,
            'donnees' => $n->donnees ? json_decode($n->donnees, true) : null,
            'lue' => $n->lue_le !== null,
            'date' => $n->created_at,
        ];
    }
}
