<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => [
                'required', 'string', 'max:100',
                Rule::unique('classes', 'nom')->where(fn ($q) => $q
                    ->where('niveau_id', $this->input('niveau_id'))
                    ->where('annee_scolaire_id', $this->input('annee_scolaire_id'))),
            ],
            'capacite' => ['nullable', 'integer', 'min:0'],
            'niveau_id' => ['required', 'exists:niveaux,id'],
            'annee_scolaire_id' => ['required', 'exists:annees_scolaires,id'],
            'enseignant_principal_id' => ['required', 'exists:enseignants,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.unique' => 'Une classe porte déjà ce nom pour ce niveau et cette année scolaire.',
            'enseignant_principal_id.required' => "L'enseignant titulaire est obligatoire pour une classe de primaire.",
        ];
    }
}
