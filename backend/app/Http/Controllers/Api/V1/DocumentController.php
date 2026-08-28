<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Eleve;
use App\Models\Enseignant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    private const TYPES = [
        'eleve' => Eleve::class,
        'enseignant' => Enseignant::class,
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'documentable_type' => ['required', 'in:eleve,enseignant'],
            'documentable_id' => ['required', 'integer'],
        ]);

        return Document::where('documentable_type', self::TYPES[$validated['documentable_type']])
            ->where('documentable_id', $validated['documentable_id'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'documentable_type' => ['required', 'in:eleve,enseignant'],
            'documentable_id' => ['required', 'integer'],
            'categorie' => ['nullable', 'string', 'max:50'],
            'fichier' => ['required', 'file', 'max:10240'], // 10 Mo
        ]);

        $modelClass = self::TYPES[$validated['documentable_type']];
        $modelClass::findOrFail($validated['documentable_id']);

        $fichier = $request->file('fichier');
        $chemin = $fichier->store("documents/{$validated['documentable_type']}s", 'local');

        $document = Document::create([
            'documentable_type' => $modelClass,
            'documentable_id' => $validated['documentable_id'],
            'nom' => $fichier->getClientOriginalName(),
            'chemin' => $chemin,
            'type_mime' => $fichier->getClientMimeType(),
            'taille' => $fichier->getSize(),
            'categorie' => $validated['categorie'] ?? null,
        ]);

        return response()->json($document, 201);
    }

    public function download(Document $document)
    {
        abort_unless(Storage::disk('local')->exists($document->chemin), 404);

        return Storage::disk('local')->download($document->chemin, $document->nom);
    }

    public function destroy(Document $document)
    {
        Storage::disk('local')->delete($document->chemin);
        $document->delete();

        return response()->noContent();
    }
}
