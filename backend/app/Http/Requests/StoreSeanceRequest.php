<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classe_id' => ['required', 'exists:classes,id'],
            'matiere_id' => ['required', 'exists:matieres,id'],
            'enseignant_id' => ['nullable', 'exists:enseignants,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'date_seance' => ['required', 'date'],
            'contenu' => ['required', 'string'],
            'devoirs' => ['nullable', 'string'],
        ];
    }
}
