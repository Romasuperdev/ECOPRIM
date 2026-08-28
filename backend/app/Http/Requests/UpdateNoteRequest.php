<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eleve_id' => ['sometimes', 'required', 'exists:eleves,id'],
            'matiere_id' => ['sometimes', 'required', 'exists:matieres,id'],
            'periode_id' => ['nullable', 'exists:periodes,id'],
            'enseignant_id' => ['nullable', 'exists:enseignants,id'],
            'valeur' => ['sometimes', 'required', 'numeric', 'min:0', 'max:20'],
            'coefficient' => ['nullable', 'integer', 'min:1'],
            'type_evaluation' => ['nullable', 'string', 'in:devoir,composition,interrogation'],
            'date_evaluation' => ['sometimes', 'required', 'date'],
            'appreciation' => ['nullable', 'string', 'max:255'],
        ];
    }
}
