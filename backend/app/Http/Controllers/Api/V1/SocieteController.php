<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Societe;
use App\Services\UsSocieteCreateur;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sociétés — vue fusionnée :
 *   - source de vérité : dbmasterbacou.US_SOCIETE (LECTURE SEULE, jamais modifiée)
 *   - surcouche ECOPRIM : console_societes (écrivable) pour les compléments et
 *     les sociétés créées uniquement dans ECOPRIM.
 * La page affiche donc les vraies sociétés même si la surcouche est vide ou si les
 * tables Console ne sont pas encore migrées.
 */
class SocieteController extends Controller
{
    public function index(Request $request)
    {
        $recherche = trim((string) $request->input('q', ''));

        $surcouche = $this->surcouche();          // code => modèle Console\Societe
        $lignes = collect();
        $vus = [];

        // 1) Les sociétés réelles, lues en direct dans US_SOCIETE.
        foreach ($this->sourceUsSociete() as $l) {
            $code = trim((string) ($l->CODESOCIETE ?? ''));
            if ($code === '') {
                continue;
            }
            $vus[] = $code;
            $lignes->push($this->fusionner($code, $l, $surcouche->get($code)));
        }

        // 2) Les sociétés existant uniquement dans ECOPRIM (créées ici).
        foreach ($surcouche as $code => $s) {
            if (! in_array($code, $vus, true)) {
                $lignes->push($this->fusionner($code, null, $s));
            }
        }

        if ($recherche !== '') {
            $lignes = $lignes->filter(fn ($r) => str_contains(mb_strtolower($r['nom'].' '.$r['code']), mb_strtolower($recherche)));
        }

        $lignes = $lignes->sortBy('nom')->values();

        return $this->paginer($lignes, $request);
    }

    /** Une ligne d'affichage : valeurs ECOPRIM si présentes, sinon valeurs US_SOCIETE. */
    private function fusionner(string $code, ?object $src, ?Societe $eco): array
    {
        $depuisSource = fn (...$cles) => $src ? $this->premier($src, ...$cles) : null;

        return [
            'id' => $eco?->id,                       // null = pas encore repris dans ECOPRIM
            'code' => $code,
            'nom' => $eco->nom ?? ($depuisSource('NOMSOCIETE') ?: $code),
            'ville' => $eco->ville ?? $depuisSource('VILLESOCIETE'),
            'adresse' => $eco->adresse ?? $depuisSource('AD1SOCIETE', 'ADRESSE'),
            'telephone' => $eco->telephone ?? $depuisSource('TELSOCIETE'),
            'email' => $eco->email ?? $depuisSource('EMAILSOCIETE'),
            'representant' => $eco->representant ?? $depuisSource('NOMPRENOMREPRESENTANT', 'REPRESENTANT'),
            'actif' => $eco ? (bool) $eco->actif : true,
            'source' => $eco ? ($src ? 'US_SOCIETE + ECOPRIM' : 'ECOPRIM') : 'US_SOCIETE',
            'repris' => (bool) $eco,
            'etablissements_count' => $eco->etablissements_count ?? null,
            'nb_etab' => $src->NB_ETAB ?? null,
            'nb_user' => $src->NB_USER ?? null,
            'pays' => $depuisSource('PAYSSOCIETE'),
            'activite' => $depuisSource('ACTIVITESOCIETE'),
            'nombase' => $depuisSource('NOMBASE'),
        ];
    }

    private function premier(object $src, string ...$cles): ?string
    {
        foreach ($cles as $c) {
            $v = trim((string) ($src->{$c} ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    /** US_SOCIETE en lecture seule ; tolère une base injoignable pour ne pas casser la page. */
    private function sourceUsSociete()
    {
        try {
            return DB::connection('master')->table('US_SOCIETE')->get();
        } catch (Throwable $e) {
            return collect();
        }
    }

    /** Surcouche ECOPRIM ; vide si les tables Console ne sont pas encore migrées. */
    private function surcouche()
    {
        try {
            return Societe::withCount('etablissements')->get()->keyBy('code');
        } catch (Throwable $e) {
            return collect();
        }
    }

    private function paginer($lignes, Request $request): LengthAwarePaginator
    {
        $parPage = min(max($request->integer('per_page', 20), 1), 200);
        $page = max($request->integer('page', 1), 1);

        return new LengthAwarePaginator(
            $lignes->forPage($page, $parPage)->values(),
            $lignes->count(),
            $parPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    public function show(Societe $societe)
    {
        return $societe->loadCount('etablissements');
    }

    /** Largeurs alignées sur les colonnes réelles de US_SOCIETE (sinon l'INSERT échoue). */
    private function regles(?int $id = null): array
    {
        $l = UsSocieteCreateur::LARGEURS;

        return [
            'code' => ['required', 'string', 'max:'.$l['code'], 'unique:ecoprim.console_societes,code'.($id ? ','.$id : '')],
            'nom' => ['required', 'string', 'max:'.$l['nom']],
            'ville' => ['nullable', 'string', 'max:'.$l['ville']],
            'adresse' => ['nullable', 'string', 'max:'.$l['adresse']],
            'pays' => ['nullable', 'string', 'max:'.$l['pays']],
            'telephone' => ['nullable', 'string', 'max:'.$l['telephone']],
            'email' => ['nullable', 'email', 'max:'.$l['email']],
            'representant' => ['nullable', 'string', 'max:'.$l['representant']],
            'nombase' => ['nullable', 'string', 'max:'.$l['nombase']],
        ];
    }

    /**
     * Enregistre une société côté ECOPRIM.
     *  - Code inconnu de US_SOCIETE  -> nouvelle société : INSERT dans US_SOCIETE puis surcouche.
     *  - Code déjà dans US_SOCIETE   -> simple « reprise » : on crée seulement la surcouche,
     *                                   la ligne partagée n'est jamais modifiée.
     */
    public function store(Request $request, UsSocieteCreateur $maitre)
    {
        $data = $request->validate($this->regles());

        $dejaDansSource = $maitre->existe($data['code']);

        if (! $dejaDansSource) {
            $maitre->creer($data);   // INSERT seul dans la table maîtresse
        }

        $societe = Societe::create(collect($data)->except('nombase', 'pays')->all());

        return response()->json([
            'societe' => $societe,
            'cree_dans_us_societe' => ! $dejaDansSource,
            'message' => $dejaDansSource
                ? "Société {$data['code']} reprise dans ECOPRIM (US_SOCIETE inchangée)."
                : "Société {$data['code']} créée dans US_SOCIETE et dans ECOPRIM.",
        ], 201);
    }

    public function update(Request $request, Societe $societe)
    {
        // nombase/pays appartiennent à US_SOCIETE : jamais réécrits depuis ECOPRIM.
        $societe->update(collect($request->validate($this->regles($societe->id)))->except('nombase', 'pays')->all());

        return response()->json($societe);
    }

    public function activer(Societe $societe)
    {
        $societe->update(['actif' => true]);

        return response()->json($societe);
    }

    public function desactiver(Societe $societe)
    {
        $societe->update(['actif' => false]);

        return response()->json($societe);
    }

    /**
     * Reprend dans console_societes toutes les sociétés de US_SOCIETE encore absentes.
     * N'écrit jamais dans US_SOCIETE et ne réécrit pas ce qui existe déjà côté ECOPRIM.
     */
    public function importer()
    {
        $existants = Societe::pluck('code')->all();
        $crees = 0;
        $ignores = 0;

        foreach ($this->sourceUsSociete() as $l) {
            $code = trim((string) ($l->CODESOCIETE ?? ''));
            if ($code === '') {
                continue;
            }
            if (in_array($code, $existants, true)) {
                $ignores++;

                continue;
            }

            Societe::create([
                'code' => $code,
                'actif' => true,
                'nom' => $this->premier($l, 'NOMSOCIETE') ?: $code,
                'ville' => $this->premier($l, 'VILLESOCIETE'),
                'adresse' => $this->premier($l, 'AD1SOCIETE', 'ADRESSE'),
                'telephone' => $this->premier($l, 'TELSOCIETE'),
                'email' => $this->premier($l, 'EMAILSOCIETE'),
                'representant' => $this->premier($l, 'NOMPRENOMREPRESENTANT', 'REPRESENTANT'),
            ]);
            $existants[] = $code;
            $crees++;
        }

        return response()->json([
            'importes' => $crees,
            'ignores' => $ignores,
            'message' => "{$crees} société(s) reprise(s) dans ECOPRIM, {$ignores} déjà présente(s).",
        ]);
    }
}
