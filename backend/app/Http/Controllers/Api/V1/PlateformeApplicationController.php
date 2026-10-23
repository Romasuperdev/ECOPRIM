<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UsApplicationEcrivain;
use Illuminate\Http\Request;

/**
 * Applications de la suite (dbmasterbacou.US_APPLICATIONS) : créer, modifier, supprimer —
 * comme la console parcauto. Super Admin seul (groupe `console:generale`).
 *
 * La liste, avec le nombre de comptes par application, reste servie par
 * PlateformeController::applications. Une application utilisée ne se supprime pas
 * (voir UsApplicationEcrivain::supprimer).
 */
class PlateformeApplicationController extends Controller
{
    public function __construct(private UsApplicationEcrivain $ecrivain) {}

    public function show(string $code)
    {
        $app = $this->ecrivain->trouver($code);
        abort_unless($app, 404, 'Application introuvable.');

        return $this->ligne($app);
    }

    public function store(Request $request)
    {
        $l = UsApplicationEcrivain::LARGEURS;
        $request->merge(['code' => trim((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:'.$l['code'], 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                // La table n'a pas de contrainte d'unicité : on la tient ici.
                function ($attribut, $valeur, $echec) {
                    if ($this->ecrivain->existe($valeur)) {
                        $echec('Une application porte déjà ce code.');
                    }
                },
            ],
            'intitule' => ['required', 'string', 'max:'.$l['intitule']],
        ], [
            'code.regex' => 'Le code ne peut contenir que des lettres, des chiffres, des tirets et des soulignés.',
        ]);

        $this->ecrivain->creer($data['code'], $data['intitule']);

        return response()->json($this->ligne($this->ecrivain->trouver($data['code'])), 201);
    }

    public function update(Request $request, string $code)
    {
        abort_unless($this->ecrivain->trouver($code), 404, 'Application introuvable.');

        $data = $request->validate([
            'intitule' => ['required', 'string', 'max:'.UsApplicationEcrivain::LARGEURS['intitule']],
        ]);

        $this->ecrivain->modifier($code, $data['intitule']);

        return response()->json($this->ligne($this->ecrivain->trouver($code)));
    }

    public function destroy(string $code)
    {
        abort_unless($this->ecrivain->trouver($code), 404, 'Application introuvable.');

        $this->ecrivain->supprimer($code);

        return response()->noContent();
    }

    private function ligne(object $app): array
    {
        return [
            'code' => trim((string) $app->CodeApp),
            'libelle' => trim((string) $app->Intitule),
        ];
    }
}
