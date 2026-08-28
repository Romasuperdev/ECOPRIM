<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnneeScolaireRequest;
use App\Http\Requests\UpdateAnneeScolaireRequest;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\Inscription;

class AnneeScolaireController extends Controller
{
    public function index()
    {
        return AnneeScolaire::orderBy('date_debut', 'desc')->get();
    }

    public function store(StoreAnneeScolaireRequest $request)
    {
        $data = $request->validated();

        if (! empty($data['active'])) {
            AnneeScolaire::query()->update(['active' => false]);
        }

        $anneeScolaire = AnneeScolaire::create($data);

        return response()->json($anneeScolaire, 201);
    }

    public function show(AnneeScolaire $anneeScolaire)
    {
        return $anneeScolaire;
    }

    public function update(UpdateAnneeScolaireRequest $request, AnneeScolaire $anneeScolaire)
    {
        $data = $request->validated();

        if (! empty($data['active'])) {
            AnneeScolaire::query()->where('id', '!=', $anneeScolaire->id)->update(['active' => false]);
        }

        $anneeScolaire->update($data);

        return response()->json($anneeScolaire);
    }

    public function destroy(AnneeScolaire $anneeScolaire)
    {
        $anneeScolaire->delete();

        return response()->noContent();
    }

    /**
     * Proposition de réinscription en masse : pour chaque élève actif,
     * crée un mouvement "réinscription" vers l'année cible, en tentant de
     * l'affecter automatiquement à une classe du niveau supérieur si une
     * seule correspond sans ambiguïté.
     */
    public function proposerReinscriptions(AnneeScolaire $anneeScolaire)
    {
        $dejaTraites = Inscription::where('annee_scolaire_id', $anneeScolaire->id)
            ->where('type', 'reinscription')
            ->pluck('eleve_id');

        $eleves = Eleve::where('statut', 'actif')
            ->whereNotIn('id', $dejaTraites)
            ->with('classe.niveau')
            ->get();

        $resultats = ['traites' => 0, 'avec_classe' => 0, 'sans_classe' => 0];

        foreach ($eleves as $eleve) {
            $niveauActuel = $eleve->classe?->niveau;
            $classeCible = null;

            if ($niveauActuel) {
                $niveauSuivant = \App\Models\Niveau::where('ordre', $niveauActuel->ordre + 1)->first();

                if ($niveauSuivant) {
                    $classesPossibles = Classe::where('niveau_id', $niveauSuivant->id)
                        ->where('annee_scolaire_id', $anneeScolaire->id)
                        ->get();

                    if ($classesPossibles->count() === 1) {
                        $classeCible = $classesPossibles->first();
                    }
                }
            }

            Inscription::create([
                'eleve_id' => $eleve->id,
                'annee_scolaire_id' => $anneeScolaire->id,
                'classe_id' => $classeCible?->id,
                'type' => 'reinscription',
                'date_mouvement' => now()->toDateString(),
                'observation' => 'Proposition de réinscription automatique',
            ]);

            $resultats['traites']++;
            $classeCible ? $resultats['avec_classe']++ : $resultats['sans_classe']++;
        }

        return response()->json($resultats);
    }
}
