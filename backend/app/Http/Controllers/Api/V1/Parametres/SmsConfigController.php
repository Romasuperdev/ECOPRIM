<?php

namespace App\Http\Controllers\Api\V1\Parametres;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Paramétrage de la passerelle SMS — ECONOMAT.dbo.ECO_SMS_CONFIG.
 * (T_SMS n'est PAS une table de configuration : c'est le journal des envois,
 * exploité par CommunicationController.)
 * Une configuration par établissement ; création + modification, jamais de suppression.
 */
class SmsConfigController extends Controller
{
    private function t(): EconomatTable
    {
        return EconomatTable::pour('ECO_SMS_CONFIG', 'id');
    }

    /** Configuration de l'établissement courant (contexte de travail), sinon la valeur par défaut. */
    public function show(Request $request)
    {
        $etab = $request->session()->get('etablissement_code');

        try {
            $l = $this->t()->requete()
                ->when($etab, fn ($q) => $q->where('CODEETABLISSEMENT', $etab))
                ->orderByDesc('IS_DEFAULT')->orderByDesc('id')->first();
        } catch (Throwable $e) {
            $l = null;
        }

        return $l ? $this->ligne($l) : $this->vide($etab);
    }

    private function vide(?string $etab): array
    {
        return [
            'id' => null, 'actif' => false, 'nom' => null, 'fournisseur' => null,
            'environnement' => 'TEST', 'api_url' => null, 'api_key' => null,
            'expediteur' => null, 'accuses_reception' => false, 'sms_long' => false,
            'notif_auto' => false, 'pays' => null, 'description' => null,
            'etablissement_code' => $etab, 'par_defaut' => false,
        ];
    }

    private function ligne(object $l): array
    {
        return [
            'id' => $l->id,
            'actif' => (bool) $l->ENABLED,
            'nom' => $l->NAME,
            'fournisseur' => $l->PROVIDER,
            'environnement' => $l->ENVIRONMENT,
            'api_url' => $l->API_URL,
            // La clé n'est jamais renvoyée en clair : on indique seulement si elle est posée.
            'api_key' => $l->API_KEY ? '••••••••' : null,
            'api_key_definie' => ! empty($l->API_KEY),
            'expediteur' => $l->SENDER_ID,
            'accuses_reception' => (bool) $l->DELIVERY_REPORTS,
            'sms_long' => (bool) $l->LONG_SMS,
            'notif_auto' => (bool) $l->AUTO_NOTIF,
            'pays' => $l->COUNTRY,
            'description' => $l->DESCRIPTION,
            'etablissement_code' => $l->CODEETABLISSEMENT,
            'societe_code' => $l->CODESOCIETE,
            'par_defaut' => (bool) $l->IS_DEFAULT,
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'actif' => ['nullable', 'boolean'],
            'nom' => ['nullable', 'string', 'max:150'],
            'fournisseur' => ['nullable', 'string', 'max:100'],
            'environnement' => ['required', 'string', 'max:20'],
            'api_url' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'expediteur' => ['nullable', 'string', 'max:50'],
            'accuses_reception' => ['nullable', 'boolean'],
            'sms_long' => ['nullable', 'boolean'],
            'notif_auto' => ['nullable', 'boolean'],
            'pays' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $etab = $request->session()->get('etablissement_code');

        $ligne = [
            'ENABLED' => ! empty($d['actif']) ? 1 : 0,
            'NAME' => $d['nom'] ?? null,
            'PROVIDER' => $d['fournisseur'] ?? null,
            'ENVIRONMENT' => $d['environnement'],
            'API_URL' => $d['api_url'] ?? null,
            'SENDER_ID' => $d['expediteur'] ?? null,
            'DELIVERY_REPORTS' => ! empty($d['accuses_reception']) ? 1 : 0,
            'LONG_SMS' => ! empty($d['sms_long']) ? 1 : 0,
            'AUTO_NOTIF' => ! empty($d['notif_auto']) ? 1 : 0,
            'COUNTRY' => $d['pays'] ?? null,
            'DESCRIPTION' => $d['description'] ?? null,
            'CODEETABLISSEMENT' => $etab,
            'UPDATED_AT' => now(),
        ];

        // Secrets : seulement s'ils sont fournis, pour ne pas écraser l'existant par du vide.
        if (! empty($d['api_key'])) {
            $ligne['API_KEY'] = $d['api_key'];
        }
        if (! empty($d['api_secret'])) {
            $ligne['API_SECRET'] = $d['api_secret'];
        }

        $existante = $this->t()->requete()
            ->when($etab, fn ($q) => $q->where('CODEETABLISSEMENT', $etab))
            ->orderByDesc('id')->first();

        if ($existante) {
            $this->t()->modifier($existante->id, $ligne);
            $id = $existante->id;
        } else {
            $id = $this->t()->inserer($ligne);
        }

        return response()->json($this->ligne($this->t()->trouver($id)));
    }
}
