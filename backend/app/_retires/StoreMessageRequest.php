<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destinataire_id' => ['required', 'exists:users,id'],
            'sujet' => ['required', 'string', 'max:150'],
            'contenu' => ['required', 'string'],
        ];
    }
}
