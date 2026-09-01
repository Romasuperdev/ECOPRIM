<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\Console\Etablissement;
use App\Services\BEtablissementEcrivain;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contexte de travail : l'établissement sur lequel l'utilisateur connecté travaille.
 * Repris de la fonctionnalité « Choisir un établissement » de BACOU : le choix est
 * conservé en session (etablissement_code / etablissement_nom) et vaut pour toute
 * la navigation jusqu'à désélection.
 */
class ContexteController extends Controller
{
    public function __construct(private BEtablissementEcrivain $source) {}

    /** Contexte courant + établissements que l'utilisateur a le droit de choisir. */
    public function show(Request $request)
    {
        return [
            'etablissement_code' => $request->session()->get('etablissement_code'),
            'etablissement_nom' => $request->session()->get('etablissement_nom'),
            'disponibles' => $this->disponibles($request)->values(),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $choix = $this->disponibles($request)->firstWhere('code', $data['code']);

        if (! $choix) {
            throw ValidationException::withMessages([
                'code' => ["Cet établissement ne fait pas partie de ceux qui vous sont accessibles."],
            ]);
        }

        $request->session()->put('etablissement_code', $choix['code']);
        $request->session()->put('etablissement_nom', $choix['intitule']);

        return response()->json([
            'etablissement_code' => $choix['code'],
            'etablissement_nom' => $choix['intitule'],
            'message' => "Vous travaillez maintenant sur « {$choix['intitule']} ».",
        ]);
    }

    public function destroy(Request $request)
    {
        $request->session()->forget(['etablissement_code', 'etablissement_nom']);

        return response()->json(['etablissement_code' => null, 'etablissement_nom' => null]);
    }

    /**
     * Un Super Admin voit tous les établissements ; les autres uniquement ceux
     * auxquels ils sont affectés. Les établissements désactivés dans ECOPRIM sont exclus.
     */
    private function disponibles(Request $request)
    {
        $user = $request->user();
        $surcouche = $this->surcouche();

        $codesAutorises = null;
        if (! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            $codesAutorises = Affectation::where('rh_user_id', $user->Id ?? 0)
                ->pluck('etablissement_code')->unique()->all();
        }

        $lignes = collect();
        $vus = [];

        foreach ($this->source->tous() as $l) {
            $code = trim((string) ($l->CodeEtablissement ?? ''));
            if ($code === '') {
                continue;
            }
            $vus[] = $code;
            $lignes->push($this->ligne($code, $l, $surcouche->get($code)));
        }
        foreach ($surcouche as $code => $e) {
            if (! in_array($code, $vus, true)) {
                $lignes->push($this->ligne($code, null, $e));
            }
        }

        return $lignes
            ->filter(fn ($r) => $r['actif'])
            ->filter(fn ($r) => $codesAutorises === null || in_array($r['code'], $codesAutorises, true))
            ->sortBy('intitule');
    }

    private function ligne(string $code, ?object $src, ?Etablissement $eco): array
    {
        $s = fn (string $c) => $src ? (trim((string) ($src->{$c} ?? '')) ?: null) : null;

        return [
            'code' => $code,
            'intitule' => $eco->intitule ?? ($s('Intitule') ?: $code),
            'ville' => $eco->ville ?? $s('Ville'),
            'adresse' => $eco->adresse ?? $s('Adresse1'),
            'telephone' => $eco->telephone ?? $s('Telephone'),
            'societe_code' => $eco->societe_code ?? $s('CodeSociete'),
            'actif' => $eco ? (bool) $eco->actif : true,
        ];
    }

    private function surcouche()
    {
        try {
            return Etablissement::all()->keyBy('code');
        } catch (Throwable $e) {
            return collect();
        }
    }
}
