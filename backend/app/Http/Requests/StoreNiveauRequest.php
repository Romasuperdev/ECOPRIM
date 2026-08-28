<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNiveauRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'unique:niveaux,code'],
            'libelle' => ['required', 'string', 'max:100'],
            'ordre' => ['nullable', 'integer', 'min:0'],
            'cycle_id' => ['nullable', 'exists:cycles,id'],
        ];
    }
}
