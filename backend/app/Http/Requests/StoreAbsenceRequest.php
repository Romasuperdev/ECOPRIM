<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eleve_id' => ['required', 'exists:eleves,id'],
            'date_absence' => ['required', 'date'],
            'matiere_id' => ['nullable', 'exists:matieres,id'],
            'motif' => ['nullable', 'string', 'max:255'],
            'justifiee' => ['nullable', 'boolean'],
        ];
    }
}
