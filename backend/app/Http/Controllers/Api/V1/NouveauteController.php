<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\NouveauteElement;
use App\Models\Console\NouveauteLue;
use App\Models\Console\NouveauteVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Nouveautés — notes de version (base propre `ecoprim`).
 *
 * Le Super Admin publie une version et ses éléments ; chaque compte la voit, et la marque
 * lue quand il en a pris connaissance. « Lue » se rattache à RH_USER.Id : c'est l'identité
 * du compte sur toute la suite, pas celle d'une session.
 *
 * Une version inactive n'existe pas pour le lecteur : ni dans la liste, ni dans les non
 * lues, et on ne peut pas la marquer lue.
 */
class NouveauteController extends Controller
{
    // --- Lecture --------------------------------------------------------------------

    /**
     * Les tables de cette rubrique existent-elles ? Sur une installation où la migration
     * de la base `ecoprim` n'a pas encore été lancée, la page doit rester utilisable —
     * vide — au lieu d'afficher une erreur SQL. Le manque est consigné dans le journal, et
     * `php artisan nexora:verifier-deploiement` le signale à l'administrateur.
     */
    private function tablesPresentes(string ...$tables): bool
    {
        try {
            foreach ($tables as $table) {
                if (! Schema::connection('ecoprim')->hasTable($table)) {
                    Log::warning("Table ecoprim.{$table} absente : lancez php artisan migrate --database=ecoprim --path=database/migrations/console");

                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Base ecoprim injoignable : '.$e->getMessage());

            return false;
        }
    }

    /** Versions actives, la plus récente d'abord, avec leurs éléments. */
    public function index()
    {
        if (! $this->tablesPresentes('nouveautes_versions', 'nouveautes_elements')) {
            return [];
        }

        return $this->actives()->get()->map(fn ($v) => $this->version($v))->values();
    }

    /** Versions actives que ce compte n'a pas encore marquées lues, la plus récente d'abord. */
    public function nonLues(Request $request)
    {
        if (! $this->tablesPresentes('nouveautes_versions', 'nouveautes_elements', 'nouveautes_lues')) {
            return [];
        }
        $userId = (int) $request->user()->Id;

        return $this->actives()
            ->whereDoesntHave('lectures', fn ($q) => $q->where('rh_user_id', $userId))
            ->get()
            ->map(fn ($v) => $this->version($v))
            ->values();
    }

    /**
     * Marque une version lue pour ce compte. Idempotent : marquer deux fois ne crée pas
     * de seconde ligne et ne déplace pas la date de première lecture. `createOrFirst`
     * s'appuie sur la contrainte d'unicité, ce qui couvre aussi deux onglets qui
     * valideraient au même instant.
     */
    public function marquerLue(Request $request, int $version)
    {
        abort_unless($this->tablesPresentes('nouveautes_versions', 'nouveautes_lues'), 404, 'Cette version est introuvable.');
        $existe = NouveauteVersion::query()->where('active', true)->whereKey($version)->exists();
        abort_unless($existe, 404, 'Cette version est introuvable.');

        $lecture = NouveauteLue::createOrFirst(
            ['rh_user_id' => (int) $request->user()->Id, 'version_id' => $version],
            ['lu_le' => now()],
        );

        return response()->json([
            'version_id' => $version,
            'lu_le' => $lecture->lu_le?->toIso8601String(),
        ]);
    }

    // --- Gestion (Super Admin) ------------------------------------------------------

    /** Toutes les versions, actives ou non, avec le nombre de comptes qui les ont lues. */
    public function gestion()
    {
        return NouveauteVersion::query()
            ->with('elements')
            ->withCount('lectures')
            ->orderByDesc('date_sortie')->orderByDesc('id')
            ->get()
            ->map(fn ($v) => [...$this->version($v), 'lectures' => (int) $v->lectures_count])
            ->values();
    }

    public function store(Request $request)
    {
        $d = $this->valider($request);

        $version = DB::connection('ecoprim')->transaction(function () use ($d) {
            $version = NouveauteVersion::create($this->champsVersion($d));
            $this->synchroniserElements($version, $d['elements'] ?? []);

            return $version;
        });

        return response()->json($this->version($version->load('elements')), 201);
    }

    public function update(Request $request, NouveauteVersion $version)
    {
        $d = $this->valider($request);

        DB::connection('ecoprim')->transaction(function () use ($version, $d) {
            $version->update($this->champsVersion($d));
            $this->synchroniserElements($version, $d['elements'] ?? []);
        });

        return response()->json($this->version($version->refresh()->load('elements')));
    }

    /** Supprime la version, ses éléments et ses lectures (clés étrangères en cascade). */
    public function destroy(NouveauteVersion $version)
    {
        $version->delete();

        return response()->noContent();
    }

    // --- Outils ---------------------------------------------------------------------

    private function actives()
    {
        return NouveauteVersion::query()
            ->where('active', true)
            ->with('elements')
            ->orderByDesc('date_sortie')->orderByDesc('id');
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'numero' => ['required', 'string', 'max:50'],
            'titre' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'date_sortie' => ['required', 'date'],
            'active' => ['nullable', 'boolean'],
            'elements' => ['nullable', 'array', 'max:200'],
            'elements.*.id' => ['nullable', 'integer'],
            'elements.*.titre' => ['required', 'string', 'max:200'],
            'elements.*.description' => ['nullable', 'string', 'max:5000'],
            'elements.*.type' => ['required', 'string', Rule::in(NouveauteElement::TYPES)],
        ]);
    }

    private function champsVersion(array $d): array
    {
        return [
            'numero' => trim($d['numero']),
            'titre' => trim($d['titre']),
            'description' => $d['description'] ?? null,
            'date_sortie' => $d['date_sortie'],
            'active' => $d['active'] ?? true,
        ];
    }

    /**
     * Aligne les éléments de la version sur la liste reçue, dans son ordre : un élément
     * qui porte l'id d'un élément DE CETTE VERSION est mis à jour, les autres sont créés,
     * et ceux qui ne figurent plus dans la liste sont supprimés. Un id étranger à la
     * version est ignoré (traité comme un nouvel élément) — il ne peut pas servir à
     * modifier l'élément d'une autre version.
     */
    private function synchroniserElements(NouveauteVersion $version, array $elements): void
    {
        $existants = $version->elements()->get()->keyBy('id');
        $conserves = [];

        foreach (array_values($elements) as $ordre => $e) {
            $champs = [
                'titre' => trim($e['titre']),
                'description' => $e['description'] ?? null,
                'type' => $e['type'],
                'ordre' => $ordre,
            ];

            $id = isset($e['id']) ? (int) $e['id'] : null;
            if ($id && $existants->has($id)) {
                $existants[$id]->update($champs);
                $conserves[] = $id;
            } else {
                $conserves[] = $version->elements()->create($champs)->id;
            }
        }

        NouveauteElement::query()
            ->where('version_id', $version->id)
            ->whereNotIn('id', $conserves ?: [0])
            ->delete();
    }

    private function version(NouveauteVersion $v): array
    {
        return [
            'id' => $v->id,
            'numero' => $v->numero,
            'titre' => $v->titre,
            'description' => $v->description,
            'date_sortie' => optional($v->date_sortie)->format('Y-m-d'),
            'active' => (bool) $v->active,
            'elements' => $v->elements->map(fn (NouveauteElement $e) => [
                'id' => $e->id,
                'titre' => $e->titre,
                'description' => $e->description,
                'type' => $e->type,
                'ordre' => (int) $e->ordre,
            ])->values(),
        ];
    }
}
