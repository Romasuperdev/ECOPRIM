<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSanctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eleve_id' => ['sometimes', 'required', 'exists:eleves,id'],
            'date_sanction' => ['sometimes', 'required', 'date'],
            'faute' => ['sometimes', 'required', 'string', 'max:255'],
            'sanction' => ['sometimes', 'required', 'string', 'max:255'],
            'observation' => ['nullable', 'string', 'max:500'],
        ];
    }
}
