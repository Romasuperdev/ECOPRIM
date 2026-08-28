<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eleve_id' => ['sometimes', 'required', 'exists:eleves,id'],
            'date_absence' => ['sometimes', 'required', 'date'],
            'matiere_id' => ['nullable', 'exists:matieres,id'],
            'motif' => ['nullable', 'string', 'max:255'],
            'justifiee' => ['nullable', 'boolean'],
        ];
    }
}
