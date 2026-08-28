<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMatiereRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'unique:matieres,code'],
            'libelle' => ['required', 'string', 'max:100'],
            'coefficient_defaut' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
