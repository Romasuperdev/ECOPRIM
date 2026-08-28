<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEtablissementRequest;
use App\Http\Requests\UpdateEtablissementRequest;
use App\Models\Etablissement;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

class EtablissementController extends Controller
{
    public function index(Request $request)
    {
        $query = Etablissement::with('societe');

        if ($request->filled('societe_id')) {
            $query->where('societe_id', $request->integer('societe_id'));
        }

        return $query->orderBy('nom')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreEtablissementRequest $request)
    {
        $etablissement = Etablissement::create($request->validated());

        ActivityLogger::log($request, 'create', 'etablissements', Etablissement::class, $etablissement->id, null, $etablissement->toArray());

        return response()->json($etablissement, 201);
    }

    public function show(Etablissement $etablissement)
    {
        return $etablissement->load('societe', 'affectations.user', 'affectations.role');
    }

    public function update(UpdateEtablissementRequest $request, Etablissement $etablissement)
    {
        $avant = $etablissement->toArray();
        $etablissement->update($request->validated());

        ActivityLogger::log($request, 'update', 'etablissements', Etablissement::class, $etablissement->id, $avant, $etablissement->toArray());

        return response()->json($etablissement);
    }

    public function destroy(Request $request, Etablissement $etablissement)
    {
        $avant = $etablissement->toArray();
        $etablissement->delete();

        ActivityLogger::log($request, 'delete', 'etablissements', Etablissement::class, $etablissement->id, $avant, null);

        return response()->noContent();
    }

    public function activer(Request $request, Etablissement $etablissement)
    {
        $this->assertActivable($etablissement);

        $avant = $etablissement->toArray();
        $etablissement->update(['statut' => 'actif']);

        ActivityLogger::log($request, 'activate', 'etablissements', Etablissement::class, $etablissement->id, $avant, $etablissement->toArray());

        return response()->json($etablissement);
    }

    public function desactiver(Request $request, Etablissement $etablissement)
    {
        $avant = $etablissement->toArray();
        $etablissement->update(['statut' => 'inactif']);

        ActivityLogger::log($request, 'deactivate', 'etablissements', Etablissement::class, $etablissement->id, $avant, $etablissement->toArray());

        return response()->json($etablissement);
    }

    /**
     * Règle de gestion : un établissement ne peut passer au statut « actif » que s'il a
     * au moins un utilisateur Directeur/Admin Établissement affecté. La condition
     * « année scolaire configurée » n'est pas encore vérifiable : les années scolaires
     * ne sont pas encore rattachées à un établissement dans le schéma actuel (module
     * pédagogique construit avant la Console Administrative, mono-établissement).
     */
    private function assertActivable(Etablissement $etablissement): void
    {
        $aUnResponsable = $etablissement->affectations()
            ->where('actif', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['Direction', 'Admin Établissement']))
            ->exists();

        if (! $aUnResponsable) {
            abort(422, "Cet établissement doit avoir au moins un Directeur ou Admin Établissement affecté avant d'être activé.");
        }
    }
}
