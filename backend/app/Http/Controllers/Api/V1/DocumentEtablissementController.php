<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentEtablissement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentEtablissementController extends Controller
{
    public function index()
    {
        return DocumentEtablissement::orderByDesc('created_at')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'categorie' => ['nullable', 'string', 'max:50'],
            'fichier' => ['required', 'file', 'max:10240'],
        ]);

        $fichier = $request->file('fichier');
        $chemin = $fichier->store('documents/etablissement', 'local');

        $document = DocumentEtablissement::create([
            'nom' => $fichier->getClientOriginalName(),
            'chemin' => $chemin,
            'type_mime' => $fichier->getClientMimeType(),
            'taille' => $fichier->getSize(),
            'categorie' => $validated['categorie'] ?? null,
        ]);

        return response()->json($document, 201);
    }

    public function download(DocumentEtablissement $document)
    {
        abort_unless(Storage::disk('local')->exists($document->chemin), 404);

        return Storage::disk('local')->download($document->chemin, $document->nom);
    }

    public function destroy(DocumentEtablissement $document)
    {
        Storage::disk('local')->delete($document->chemin);
        $document->delete();

        return response()->noContent();
    }
}
