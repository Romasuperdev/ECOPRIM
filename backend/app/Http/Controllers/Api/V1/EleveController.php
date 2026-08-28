<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEleveRequest;
use App\Http\Requests\UpdateEleveRequest;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\ParentEleve;
use App\Support\AnneeScolaireGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EleveController extends Controller
{
    private const CHAMPS_PARENTS = [
        'pere_tuteur_lien', 'pere_nom', 'pere_prenom', 'pere_telephone', 'pere_email', 'pere_profession',
        'mere_nom', 'mere_prenom', 'mere_telephone', 'mere_email', 'mere_profession',
    ];

    public function index(Request $request)
    {
        // TODO: filtres par classe/niveau via spatie/laravel-query-builder
        return Eleve::with('classe')->orderBy('nom')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreEleveRequest $request)
    {
        $validated = $request->validated();
        $parents = collect($validated)->only(self::CHAMPS_PARENTS)->all();
        $donneesEleve = collect($validated)->except(self::CHAMPS_PARENTS)->all();

        if (! empty($donneesEleve['classe_id'])) {
            $this->assertCapaciteDisponible($donneesEleve['classe_id'], $request);
        }

        $eleve = DB::transaction(function () use ($donneesEleve, $parents) {
            $eleve = Eleve::create($donneesEleve);

            if (! empty($parents['pere_nom'])) {
                $pere = ParentEleve::create([
                    'nom' => $parents['pere_nom'],
                    'prenom' => $parents['pere_prenom'],
                    'telephone' => $parents['pere_telephone'] ?? null,
                    'email' => $parents['pere_email'] ?? null,
                    'profession' => $parents['pere_profession'] ?? null,
                ]);
                $eleve->parents()->attach($pere->id, ['lien_parente' => $parents['pere_tuteur_lien'] ?? 'pere']);
            }

            if (! empty($parents['mere_nom'])) {
                $mere = ParentEleve::create([
                    'nom' => $parents['mere_nom'],
                    'prenom' => $parents['mere_prenom'],
                    'telephone' => $parents['mere_telephone'] ?? null,
                    'email' => $parents['mere_email'] ?? null,
                    'profession' => $parents['mere_profession'] ?? null,
                ]);
                $eleve->parents()->attach($mere->id, ['lien_parente' => 'mere']);
            }

            return $eleve;
        });

        return response()->json($eleve->load('classe', 'parents'), 201);
    }

    public function show(Eleve $eleve)
    {
        return $eleve->load('classe', 'parents');
    }

    public function update(UpdateEleveRequest $request, Eleve $eleve)
    {
        $validated = $request->validated();

        if (array_key_exists('classe_id', $validated) && $validated['classe_id'] && $validated['classe_id'] != $eleve->classe_id) {
            $this->assertCapaciteDisponible($validated['classe_id'], $request);
            $classe = Classe::find($validated['classe_id']);
            AnneeScolaireGuard::assertModifiable($classe?->annee_scolaire_id, $request);
        }

        $eleve->update($validated);

        return response()->json($eleve);
    }

    public function destroy(Eleve $eleve)
    {
        $eleve->delete(); // soft delete

        return response()->noContent();
    }

    private function assertCapaciteDisponible(int $classeId, Request $request): void
    {
        $classe = Classe::find($classeId);

        if (! $classe || ! $classe->capacite) {
            return;
        }

        $effectif = Eleve::where('classe_id', $classeId)->count();

        if ($effectif >= $classe->capacite) {
            abort(422, "La classe « {$classe->nom} » a atteint sa capacité maximale ({$classe->capacite} élèves).");
        }
    }
}
