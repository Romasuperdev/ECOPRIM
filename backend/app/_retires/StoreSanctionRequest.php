<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSanctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eleve_id' => ['required', 'exists:eleves,id'],
            'date_sanction' => ['required', 'date'],
            'faute' => ['required', 'string', 'max:255'],
            'sanction' => ['required', 'string', 'max:255'],
            'observation' => ['nullable', 'string', 'max:500'],
        ];
    }
}
