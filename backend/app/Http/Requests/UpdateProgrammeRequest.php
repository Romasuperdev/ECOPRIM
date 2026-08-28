<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgrammeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'niveau_id' => ['sometimes', 'required', 'exists:niveaux,id'],
            'matiere_id' => ['sometimes', 'required', 'exists:matieres,id'],
            'annee_scolaire_id' => ['sometimes', 'required', 'exists:annees_scolaires,id'],
            'titre' => ['sometimes', 'required', 'string', 'max:150'],
            'contenu' => ['nullable', 'string'],
        ];
    }
}
