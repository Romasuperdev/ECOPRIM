<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Etablissement;
use App\Models\Console\Societe;
use App\Services\BEtablissementEcrivain;
use App\Support\PerimetreConsole;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Établissements — vue fusionnée :
 *   - source de vérité : ECONOMAT.dbo.BEtablissements (création + modification autorisées,
 *     JAMAIS de suppression)
 *   - surcouche ECOPRIM : console_etablissements (activation/désactivation logique).
 * La page affiche les vrais établissements même si la surcouche est vide.
 *
 * Cloisonnement : un Admin Société ne voit et ne touche que les établissements de sa
 * société ; un Super Admin voit tout, ou la seule société qu'il a choisie dans l'en-tête.
 * Un établissement sans société rattachée n'est visible que du Super Admin en vue
 * générale — sinon on ne saurait pas à qui il appartient.
 */
class EtablissementController extends Controller
{
    public function __construct(private BEtablissementEcrivain $source) {}

    public function index(Request $request)
    {
        $recherche = trim((string) $request->input('q', ''));
        $filtreSociete = trim((string) $request->input('societe_code', ''));

        $surcouche = $this->surcouche();
        $lignes = collect();
        $vus = [];

        foreach ($this->source->tous() as $l) {
            $code = trim((string) ($l->CodeEtablissement ?? ''));
            if ($code === '') {
                continue;
            }
            $vus[] = $code;
            $lignes->push($this->fusionner($code, $l, $surcouche->get($code)));
        }

        foreach ($surcouche as $code => $e) {
            if (! in_array($code, $vus, true)) {
                $lignes->push($this->fusionner($code, null, $e));
            }
        }

        $lignes = $this->cloisonner($lignes);

        if ($filtreSociete !== '') {
            $lignes = $lignes->filter(fn ($r) => $r['societe_code'] === $filtreSociete);
        }
        if ($recherche !== '') {
            $lignes = $lignes->filter(fn ($r) => str_contains(
                mb_strtolower($r['intitule'].' '.$r['code'].' '.$r['ville']),
                mb_strtolower($recherche)
            ));
        }

        return $this->paginer($lignes->sortBy('intitule')->values(), $request);
    }

    /** Fiche détaillée, adressée par CODE (fonctionne même sans surcouche ECOPRIM). */
    public function show(string $code)
    {
        $src = $this->source->trouver($code);
        $eco = $this->surcouche()->get($code);

        abort_if(! $src && ! $eco, 404, 'Établissement introuvable.');

        $ligne = $this->fusionner($code, $src, $eco);
        PerimetreConsole::assertAutoriseeEtablissement($code, $ligne['societe_code']);
        $ligne['societe'] = Societe::where('code', $ligne['societe_code'])->first();

        return $ligne;
    }

    private function fusionner(string $code, ?object $src, ?Etablissement $eco): array
    {
        $s = fn (string $c) => $src ? (trim((string) ($src->{$c} ?? '')) ?: null) : null;

        return [
            'id' => $eco?->id,
            'code' => $code,
            'intitule' => $eco->intitule ?? ($s('Intitule') ?: $code),
            'adresse' => $eco->adresse ?? $s('Adresse1'),
            'ville' => $eco->ville ?? $s('Ville'),
            'pays' => $eco->pays ?? $s('Pays'),
            'telephone' => $eco->telephone ?? $s('Telephone'),
            'email' => $eco->email ?? $s('Email'),
            'site_web' => $eco->site_web ?? $s('SiteWeb'),
            'societe_code' => $eco->societe_code ?? $s('CodeSociete'),
            'type' => $eco->type ?? null,
            'actif' => $eco ? (bool) $eco->actif : true,
            'source' => $eco ? ($src ? 'BEtablissements + ECOPRIM' : 'ECOPRIM') : 'BEtablissements',
            'repris' => (bool) $eco,
        ];
    }

    /**
     * Restreint la liste fusionnée au périmètre. On filtre ici plutôt qu'en SQL parce que
     * la liste vient de deux bases distinctes (BEtablissements + surcouche).
     */
    private function cloisonner($lignes)
    {
        $user = auth()->user();
        $courant = PerimetreConsole::codeCourant($user);

        if ($user && $user->isSuperAdmin()) {
            return $courant === null
                ? $lignes
                : $lignes->filter(fn ($r) => $r['societe_code'] === $courant);
        }

        // Fail closed : sans société courante on ne montre rien.
        if ($courant === null) {
            return $lignes->take(0);
        }

        // Un Admin Établissement (sans le niveau société) est borné à son ou ses
        // établissements administrés, pas à toute la société.
        if ($user && ! $user->peutAccederNiveauSociete()) {
            $etabs = $user->etablissementsAdministres();

            return $lignes->filter(fn ($r) => in_array($r['code'], $etabs, true));
        }

        return $lignes->filter(fn ($r) => $r['societe_code'] === $courant);
    }

    private function surcouche()
    {
        try {
            return Etablissement::all()->keyBy('code');
        } catch (Throwable $e) {
            return collect();
        }
    }

    private function paginer($lignes, Request $request): LengthAwarePaginator
    {
        $parPage = min(max($request->integer('per_page', 20), 1), 200);
        $page = max($request->integer('page', 1), 1);

        return new LengthAwarePaginator(
            $lignes->forPage($page, $parPage)->values(), $lignes->count(), $parPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /** Largeurs et obligations alignées sur les colonnes réelles de BEtablissements. */
    private function regles(bool $creation): array
    {
        $l = BEtablissementEcrivain::LARGEURS;

        return [
            'code' => $creation
                ? ['required', 'string', 'max:'.$l['code'], Rule::unique('ecoprim.console_etablissements', 'code')]
                : ['sometimes', 'string', 'max:'.$l['code']],
            // NOT NULL côté SQL Server :
            'intitule' => ['required', 'string', 'max:'.$l['intitule']],
            'adresse' => ['required', 'string', 'max:'.$l['adresse']],
            'pays' => ['required', 'string', 'max:'.$l['pays']],
            'societe_code' => ['required', 'string', 'max:'.$l['societe_code']],
            // Facultatives :
            'ville' => ['nullable', 'string', 'max:'.$l['ville']],
            'telephone' => ['nullable', 'string', 'max:'.$l['telephone']],
            'email' => ['nullable', 'email', 'max:'.$l['email']],
            'site_web' => ['nullable', 'string', 'max:'.$l['site_web']],
            'type' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Code inconnu de BEtablissements -> nouvel établissement : INSERT + surcouche.
     * Code déjà présent -> reprise : surcouche seule, la ligne partagée reste intacte.
     */
    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));
        PerimetreConsole::assertAutorisee($data['societe_code']);
        $dejaDansSource = $this->source->existe($data['code']);

        if (! $dejaDansSource) {
            $this->source->creer($data);
        }

        $etab = Etablissement::create($data);

        return response()->json([
            'etablissement' => $etab,
            'cree_dans_source' => ! $dejaDansSource,
            'message' => $dejaDansSource
                ? "Établissement {$data['code']} repris dans ECOPRIM (BEtablissements inchangée)."
                : "Établissement {$data['code']} créé dans BEtablissements et dans ECOPRIM.",
        ], 201);
    }

    /** Modification : répercutée dans BEtablissements ET dans la surcouche. */
    public function update(Request $request, Etablissement $etablissement)
    {
        $data = $request->validate($this->regles(false));
        PerimetreConsole::assertAutorisee($etablissement->societe_code);
        // Un déplacement d'établissement ne doit pas servir à sortir de son périmètre.
        if (isset($data['societe_code'])) {
            PerimetreConsole::assertAutorisee($data['societe_code']);
        }

        if ($this->source->existe($etablissement->code)) {
            $this->source->modifier($etablissement->code, $data);
        }

        $etablissement->update(collect($data)->except('code')->all());

        return response()->json($etablissement->fresh());
    }

    public function activer(Etablissement $etablissement)
    {
        PerimetreConsole::assertAutorisee($etablissement->societe_code);
        $etablissement->update(['actif' => true]);

        return response()->json($etablissement->fresh());
    }

    /** Désactivation logique — remplace la suppression, qui est interdite ici. */
    public function desactiver(Etablissement $etablissement)
    {
        PerimetreConsole::assertAutorisee($etablissement->societe_code);
        $etablissement->update(['actif' => false]);

        return response()->json($etablissement->fresh());
    }
}
