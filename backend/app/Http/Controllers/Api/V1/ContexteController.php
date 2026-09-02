<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\AnneeScolaire;
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
        $disponibles = $this->disponibles($request)->values();
        $effectif = $this->etablissementEffectif($request, $disponibles);
        $annees = $this->annees();

        return [
            // Établissement de travail : celui choisi en session, à défaut celui auquel
            // le compte est rattaché dans RH_USER — l'utilisateur n'a rien à faire pour
            // voir son établissement s'affichera.
            'etablissement_code' => $effectif['code'],
            'etablissement_nom' => $effectif['nom'],
            'etablissement_par_defaut' => $effectif['par_defaut'],
            'disponibles' => $disponibles,

            // Année de consultation : celle choisie en session, à défaut l'année active.
            'annee' => $this->anneeEffective($request, $annees),
            'annees' => $annees,
        ];
    }

    /** Choix explicite en session, sinon rattachement RH_USER, sinon rien. */
    private function etablissementEffectif(Request $request, $disponibles): array
    {
        $code = $request->session()->get('etablissement_code');
        if ($code) {
            return [
                'code' => $code,
                'nom' => $request->session()->get('etablissement_nom'),
                'par_defaut' => false,
            ];
        }

        $rattachement = trim((string) ($request->user()->Etab ?? ''));
        if ($rattachement !== '') {
            $connu = $disponibles->firstWhere('code', $rattachement);

            return [
                'code' => $rattachement,
                'nom' => $connu['intitule'] ?? $rattachement,
                'par_defaut' => true,
            ];
        }

        return ['code' => null, 'nom' => null, 'par_defaut' => false];
    }

    /** Années scolaires, la plus récente d'abord. */
    private function annees(): array
    {
        try {
            return AnneeScolaire::orderByDesc('DEBUT')->get()
                ->map(fn ($a) => [
                    'libelle' => $a->libelle,
                    'code_annee' => $a->code_annee,
                    'active' => $a->active,
                    'cloturee' => $a->cloturee,
                ])
                ->filter(fn ($a) => ! empty($a['libelle']))
                ->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function anneeEffective(Request $request, array $annees): ?string
    {
        $choisie = $request->session()->get('annee_travail');
        if ($choisie && collect($annees)->firstWhere('libelle', $choisie)) {
            return $choisie;
        }

        return collect($annees)->firstWhere('active', true)['libelle']
            ?? ($annees[0]['libelle'] ?? null);
    }

    /** Change l'année de consultation. Une année clôturée est acceptée : on la consulte. */
    public function definirAnnee(Request $request)
    {
        $data = $request->validate(['annee' => ['required', 'string', 'max:50']]);
        $annees = $this->annees();

        $choix = collect($annees)->firstWhere('libelle', $data['annee']);
        if (! $choix) {
            throw ValidationException::withMessages([
                'annee' => ['Cette année scolaire est inconnue du référentiel.'],
            ]);
        }

        $request->session()->put('annee_travail', $choix['libelle']);

        return response()->json([
            'annee' => $choix['libelle'],
            'cloturee' => $choix['cloturee'],
            'message' => $choix['cloturee']
                ? "Vous consultez l'année {$choix['libelle']}, clôturée : consultation seule."
                : "Année de travail : {$choix['libelle']}.",
        ]);
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
