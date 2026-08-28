<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classe_id' => ['sometimes', 'required', 'exists:classes,id'],
            'matiere_id' => ['sometimes', 'required', 'exists:matieres,id'],
            'enseignant_id' => ['nullable', 'exists:enseignants,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'date_seance' => ['sometimes', 'required', 'date'],
            'contenu' => ['sometimes', 'required', 'string'],
            'devoirs' => ['nullable', 'string'],
        ];
    }
}
