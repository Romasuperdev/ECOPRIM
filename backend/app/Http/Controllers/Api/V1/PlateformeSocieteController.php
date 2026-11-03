<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Societe;
use App\Services\SocieteApplicationEcrivain;
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
 *
 * Le formulaire couvre la fiche société de US_SOCIETE (identité, coordonnées, représentant
 * légal). Les champs proposés et leur longueur viennent de la base elle-même : voir
 * `structure()`.
 */
class PlateformeSocieteController extends Controller
{
    public function __construct(private UsSocieteEcrivain $ecrivain) {}

    /** Les champs que cette base accepte, et leur longueur maximale — pour le formulaire. */
    public function structure()
    {
        return response()->json([
            'code' => ['largeur' => UsSocieteEcrivain::LARGEURS['code']],
            'champs' => $this->ecrivain->champsDisponibles(),
        ]);
    }

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
            ...$this->ecrivain->regles(),
            // Une société naît rattachée à au moins une application (US_SOCIETE_APPLICATION) :
            // sans elle, ni ses établissements ni ses comptes n'ouvrent quoi que ce soit.
            'applications' => ['required', 'array', 'min:1'],
            'applications.*' => ['string', 'max:50'],
        ], [
            'applications.required' => 'Choisissez au moins une application pour cette société.',
            'applications.min' => 'Choisissez au moins une application pour cette société.',
            'code.regex' => 'Le sigle ne peut contenir que des lettres, des chiffres, des tirets et des soulignés.',
            'code.unique' => 'Une société porte déjà ce sigle.',
        ]);

        $applications = app(SocieteApplicationEcrivain::class);
        $connues = collect($applications->applications())->pluck('code')->all();
        $inconnues = array_diff($data['applications'], $connues);
        if ($inconnues !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'applications' => ['Application inconnue : '.implode(', ', $inconnues).'.'],
            ]);
        }

        $src = $this->ecrivain->creer(collect($data)->except('applications')->all());
        $applications->synchroniser($data['code'], $data['applications']);

        return response()->json($this->fiche($src), 201);
    }

    public function update(Request $request, string $code)
    {
        abort_unless($this->ecrivain->trouver($code), 404, 'Société introuvable.');

        $data = $request->validate($this->ecrivain->regles());

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

    private function fiche(object $src): array
    {
        $fiche = $this->ecrivain->fiche($src);
        $copie = Societe::where('code', $fiche['code'])->first();

        return $fiche + ['actif' => $copie ? (bool) $copie->actif : true];
    }
}
