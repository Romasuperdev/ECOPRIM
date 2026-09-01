<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Societe;
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

    private function regles(?int $id = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:ecoprim.console_societes,code'.($id ? ','.$id : '')],
            'nom' => ['required', 'string', 'max:150'],
            'ville' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'representant' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Crée la surcouche ECOPRIM d'une société — soit une société entièrement nouvelle,
     * soit la « reprise » d'une société de US_SOCIETE (même code) pour pouvoir la compléter.
     */
    public function store(Request $request)
    {
        return response()->json(Societe::create($request->validate($this->regles())), 201);
    }

    public function update(Request $request, Societe $societe)
    {
        $societe->update($request->validate($this->regles($societe->id)));

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
