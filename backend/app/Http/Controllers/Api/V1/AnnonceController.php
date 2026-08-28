<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnonceRequest;
use App\Models\Annonce;
use Illuminate\Http\Request;

class AnnonceController extends Controller
{
    public function index(Request $request)
    {
        return Annonce::with('auteur', 'classe')
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreAnnonceRequest $request)
    {
        $annonce = Annonce::create([
            ...$request->validated(),
            'auteur_id' => $request->user()->id,
        ]);

        return response()->json($annonce->load('auteur', 'classe'), 201);
    }

    public function destroy(Annonce $annonce)
    {
        $annonce->delete();

        return response()->noContent();
    }
}
