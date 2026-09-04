<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMatiereRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('matieres', 'code')->ignore($this->route('matiere'))],
            'libelle' => ['sometimes', 'required', 'string', 'max:100'],
            'coefficient_defaut' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
