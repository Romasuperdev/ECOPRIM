<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRessourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matiere_id' => ['nullable', 'exists:matieres,id'],
            'niveau_id' => ['nullable', 'exists:niveaux,id'],
            'enseignant_id' => ['nullable', 'exists:enseignants,id'],
            'titre' => ['required', 'string', 'max:150'],
            'url' => ['required', 'url', 'max:500'],
            'description' => ['nullable', 'string'],
        ];
    }
}
