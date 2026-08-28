<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSocieteRequest;
use App\Http\Requests\UpdateSocieteRequest;
use App\Models\Societe;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

class SocieteController extends Controller
{
    public function index(Request $request)
    {
        return Societe::withCount('etablissements')
            ->orderBy('nom')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreSocieteRequest $request)
    {
        $societe = Societe::create($request->validated());

        ActivityLogger::log($request, 'create', 'societes', Societe::class, $societe->id, null, $societe->toArray());

        return response()->json($societe, 201);
    }

    public function show(Societe $societe)
    {
        return $societe->loadCount('etablissements')->load('etablissements');
    }

    public function update(UpdateSocieteRequest $request, Societe $societe)
    {
        $avant = $societe->toArray();
        $societe->update($request->validated());

        ActivityLogger::log($request, 'update', 'societes', Societe::class, $societe->id, $avant, $societe->toArray());

        return response()->json($societe);
    }

    public function destroy(Request $request, Societe $societe)
    {
        if ($societe->etablissements()->where('statut', '!=', 'inactif')->exists()) {
            abort(422, 'Cette société a des établissements actifs : désactivez-les avant de la supprimer.');
        }

        $avant = $societe->toArray();
        $societe->delete();

        ActivityLogger::log($request, 'delete', 'societes', Societe::class, $societe->id, $avant, null);

        return response()->noContent();
    }

    public function activer(Request $request, Societe $societe)
    {
        $avant = $societe->toArray();
        $societe->update(['statut' => 'actif']);

        ActivityLogger::log($request, 'activate', 'societes', Societe::class, $societe->id, $avant, $societe->toArray());

        return response()->json($societe);
    }

    public function desactiver(Request $request, Societe $societe)
    {
        $avant = $societe->toArray();
        $societe->update(['statut' => 'inactif']);

        ActivityLogger::log($request, 'deactivate', 'societes', Societe::class, $societe->id, $avant, $societe->toArray());

        return response()->json($societe);
    }
}
