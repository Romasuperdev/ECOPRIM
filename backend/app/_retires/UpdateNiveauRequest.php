<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNiveauRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('niveaux', 'code')->ignore($this->route('niveau'))],
            'libelle' => ['sometimes', 'required', 'string', 'max:100'],
            'ordre' => ['nullable', 'integer', 'min:0'],
            'cycle_id' => ['nullable', 'exists:cycles,id'],
        ];
    }
}
