<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnnonceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classe_id' => ['nullable', 'exists:classes,id'],
            'titre' => ['required', 'string', 'max:150'],
            'contenu' => ['required', 'string'],
        ];
    }
}
