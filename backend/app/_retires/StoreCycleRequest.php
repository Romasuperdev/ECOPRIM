<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'unique:cycles,code'],
            'libelle' => ['required', 'string', 'max:100'],
            'ordre' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
