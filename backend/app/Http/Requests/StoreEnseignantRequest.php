<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnseignantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricule' => ['required', 'string', 'max:30', 'unique:enseignants,matricule'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'statut' => ['nullable', 'string', 'in:titulaire,vacataire'],
        ];
    }
}
