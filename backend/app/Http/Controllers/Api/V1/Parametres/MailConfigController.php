<?php

namespace App\Http\Controllers\Api\V1\Parametres;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Paramétrage de la messagerie sortante (SMTP) — ECONOMAT.dbo.T_MAIL_DIFFUSION.
 * Une configuration par établissement ; création + modification, jamais de suppression.
 */
class MailConfigController extends Controller
{
    private function t(): EconomatTable
    {
        return EconomatTable::pour('T_MAIL_DIFFUSION', 'ID_MAIL_DIF');
    }

    public function show(Request $request)
    {
        $etab = $request->session()->get('etablissement_code');

        try {
            $l = $this->t()->requete()
                ->when($etab, fn ($q) => $q->where('code_etab', $etab))
                ->orderByDesc('ID_MAIL_DIF')->first();
        } catch (Throwable $e) {
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
        $d = $request->validate([
            'adresse' => ['required', 'email', 'max:80'],
            'serveur_smtp' => ['required', 'string', 'max:80'],
            'port_smtp' => ['required', 'integer', 'min:1', 'max:65535'],
            'mot_de_passe' => ['nullable', 'string', 'max:50'],
        ]);

        $etab = $request->session()->get('etablissement_code');

        $ligne = [
            'ADRESS_MAIL' => $d['adresse'],
            'SERVEUR_SMTP' => $d['serveur_smtp'],
            'PORT_SMTP' => $d['port_smtp'],
            'code_etab' => $etab,
        ];
        if (! empty($d['mot_de_passe'])) {
            $ligne['MOT_PASS'] = $d['mot_de_passe'];
        }

        $existante = $this->t()->requete()
            ->when($etab, fn ($q) => $q->where('code_etab', $etab))
            ->orderByDesc('ID_MAIL_DIF')->first();

        if ($existante) {
            $this->t()->modifier($existante->ID_MAIL_DIF, $ligne);
            $id = $existante->ID_MAIL_DIF;
        } else {
            $id = $this->t()->inserer($ligne);
        }

        return response()->json($this->ligne($this->t()->trouver($id)));
    }
}
