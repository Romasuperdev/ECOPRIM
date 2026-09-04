<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConseilClasseRequest;
use App\Http\Requests\UpdateConseilClasseRequest;
use App\Models\ConseilClasse;
use Illuminate\Http\Request;

class ConseilClasseController extends Controller
{
    public function index(Request $request)
    {
        return ConseilClasse::with('classe', 'periode')
            ->orderByDesc('date_conseil')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreConseilClasseRequest $request)
    {
        $conseil = ConseilClasse::create($request->validated());

        return response()->json($conseil->load('classe', 'periode'), 201);
    }

    public function show(ConseilClasse $conseil)
    {
        return $conseil->load('classe.eleves', 'periode', 'deliberations.eleve');
    }

    public function update(UpdateConseilClasseRequest $request, ConseilClasse $conseil)
    {
        $conseil->update($request->validated());

        return response()->json($conseil->load('classe', 'periode'));
    }

    public function destroy(ConseilClasse $conseil)
    {
        $conseil->delete();

        return response()->noContent();
    }
}
