<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnseignantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricule' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('enseignants', 'matricule')->ignore($this->route('enseignant'))],
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'prenom' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'statut' => ['nullable', 'string', 'in:titulaire,vacataire'],
            'actif' => ['nullable', 'boolean'],
        ];
    }
}
