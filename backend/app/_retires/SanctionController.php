<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSanctionRequest;
use App\Http\Requests\UpdateSanctionRequest;
use App\Models\Sanction;
use Illuminate\Http\Request;

class SanctionController extends Controller
{
    public function index(Request $request)
    {
        return Sanction::with('eleve')
            ->when($request->filled('eleve_id'), fn ($q) => $q->where('eleve_id', $request->input('eleve_id')))
            ->orderByDesc('date_sanction')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreSanctionRequest $request)
    {
        $sanction = Sanction::create($request->validated());

        return response()->json($sanction->load('eleve'), 201);
    }

    public function show(Sanction $sanction)
    {
        return $sanction->load('eleve');
    }

    public function update(UpdateSanctionRequest $request, Sanction $sanction)
    {
        $sanction->update($request->validated());

        return response()->json($sanction->load('eleve'));
    }

    public function destroy(Sanction $sanction)
    {
        $sanction->delete();

        return response()->noContent();
    }
}
