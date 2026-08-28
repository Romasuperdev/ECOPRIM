<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRetardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eleve_id' => ['sometimes', 'required', 'exists:eleves,id'],
            'date_retard' => ['sometimes', 'required', 'date'],
            'heure_arrivee' => ['nullable', 'date_format:H:i'],
            'duree_minutes' => ['nullable', 'integer', 'min:0'],
            'motif' => ['nullable', 'string', 'max:255'],
            'justifie' => ['nullable', 'boolean'],
        ];
    }
}
