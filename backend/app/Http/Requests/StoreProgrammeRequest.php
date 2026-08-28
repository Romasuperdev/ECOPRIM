<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProgrammeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'niveau_id' => ['required', 'exists:niveaux,id'],
            'matiere_id' => ['required', 'exists:matieres,id'],
            'annee_scolaire_id' => ['required', 'exists:annees_scolaires,id'],
            'titre' => ['required', 'string', 'max:150'],
            'contenu' => ['nullable', 'string'],
        ];
    }
}
