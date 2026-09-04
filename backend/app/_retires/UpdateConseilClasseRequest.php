<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConseilClasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classe_id' => ['sometimes', 'required', 'exists:classes,id'],
            'periode_id' => ['sometimes', 'required', 'exists:periodes,id'],
            'date_conseil' => ['sometimes', 'required', 'date'],
            'president' => ['nullable', 'string', 'max:150'],
            'secretaire' => ['nullable', 'string', 'max:150'],
            'observations_generales' => ['nullable', 'string'],
        ];
    }
}
