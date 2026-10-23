<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Societe;
use App\Services\UsSocieteEcrivain;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sociétés de la plateforme (dbmasterbacou.US_SOCIETE) : créer, modifier, désactiver —
 * comme la console parcauto. Super Admin seul (groupe `console:generale`).
 *
 * La liste reste servie par SocieteController::index, qui fusionne US_SOCIETE et la copie
 * ECOPRIM. Ici, l'écriture : US_SOCIETE d'abord, puis la copie ECOPRIM (console_societes),
 * dont dépend le reste de l'application. « Supprimer » une société = la désactiver dans
 * ECOPRIM ; la ligne partagée n'est jamais effacée (voir UsSocieteEcrivain).
 */
class PlateformeSocieteController extends Controller
{
    public function __construct(private UsSocieteEcrivain $ecrivain) {}

    /** La fiche à modifier : valeurs de US_SOCIETE, état actif de la copie ECOPRIM. */
    public function show(string $code)
    {
        $src = $this->ecrivain->trouver($code);
        abort_unless($src, 404, 'Société introuvable.');

        return $this->fiche($src);
    }

    public function store(Request $request)
    {
        $l = UsSocieteEcrivain::LARGEURS;
        $request->merge(['code' => trim((string) $request->input('code'))]);

        $data = $request->validate([
            // Le code sert d'adresse (URL) et de référence partout : lettres, chiffres,
            // tiret et souligné seulement.
            'code' => [
                'required', 'string', 'max:'.$l['code'], 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('master.US_SOCIETE', 'CODESOCIETE'),
            ],
            ...$this->reglesCommunes(),
        ], [
            'code.regex' => 'Le sigle ne peut contenir que des lettres, des chiffres, des tirets et des soulignés.',
            'code.unique' => 'Une société porte déjà ce sigle.',
        ]);

        return response()->json($this->fiche($this->ecrivain->creer($data)), 201);
    }

    public function update(Request $request, string $code)
    {
        abort_unless($this->ecrivain->trouver($code), 404, 'Société introuvable.');

        $data = $request->validate($this->reglesCommunes());

        return response()->json($this->fiche($this->ecrivain->modifier($code, $data)));
    }

    public function activer(string $code)
    {
        $this->ecrivain->definirActif($code, true);

        return response()->json($this->fiche($this->ecrivain->trouver($code)));
    }

    /** Remplace la suppression : la société disparaît des choix d'ECOPRIM, rien n'est effacé. */
    public function desactiver(string $code)
    {
        $this->ecrivain->definirActif($code, false);

        return response()->json($this->fiche($this->ecrivain->trouver($code)));
    }

    private function reglesCommunes(): array
    {
        $l = UsSocieteEcrivain::LARGEURS;

        return [
            'nom' => ['required', 'string', 'max:'.$l['nom']],
            'email' => ['nullable', 'email', 'max:'.$l['email']],
            'codification' => ['sometimes', 'boolean'],
            'capital' => ['nullable', 'integer', 'min:0', 'max:'.UsSocieteEcrivain::CAPITAL_MAX],
        ];
    }

    private function fiche(object $src): array
    {
        $code = trim((string) $src->CODESOCIETE);
        $copie = Societe::where('code', $code)->first();

        return [
            'code' => $code,
            'nom' => trim((string) ($src->NOMSOCIETE ?? '')),
            'email' => trim((string) ($src->EMAILSOCIETE ?? '')) ?: null,
            'codification' => (bool) ($src->CODIFICATION ?? false),
            'capital' => isset($src->CAPITAL) && $src->CAPITAL !== null ? (int) $src->CAPITAL : null,
            'actif' => $copie ? (bool) $copie->actif : true,
        ];
    }
}
