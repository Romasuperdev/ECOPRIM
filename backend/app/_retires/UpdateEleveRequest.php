<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEleveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricule' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('eleves', 'matricule')->ignore($this->route('eleve'))],
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'prenom' => ['sometimes', 'required', 'string', 'max:100'],
            'date_naissance' => ['sometimes', 'required', 'date'],
            'numero_acte_naissance' => ['nullable', 'string', 'max:50'],
            'acte_delivre_par' => ['nullable', 'string', 'max:150'],
            'lieu_naissance' => ['nullable', 'string', 'max:150'],
            'pays_naissance' => ['nullable', 'string', 'max:100'],
            'nationalite' => ['nullable', 'string', 'max:100'],
            'sexe' => ['sometimes', 'required', 'string', 'in:M,F'],
            'photo' => ['nullable', 'string'],
            'classe_id' => ['nullable', 'exists:classes,id'],
            'statut' => ['nullable', 'string', 'max:20'],
            'redoublant' => ['nullable', 'boolean'],
            'type_eleve' => ['nullable', 'string', 'max:50'],
            'lv2' => ['nullable', 'string', 'max:50'],
            'situation_familiale' => ['nullable', 'string', 'max:50'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'ville' => ['nullable', 'string', 'max:100'],
            'commune' => ['nullable', 'string', 'max:100'],
            'quartier' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'telephone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
