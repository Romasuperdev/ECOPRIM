<?php

namespace App\Http\Requests;

use App\Models\Classe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Classe $classe */
        $classe = $this->route('classe');
        $niveauId = $this->input('niveau_id', $classe->niveau_id);
        $anneeScolaireId = $this->input('annee_scolaire_id', $classe->annee_scolaire_id);

        return [
            'nom' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('classes', 'nom')
                    ->where(fn ($q) => $q->where('niveau_id', $niveauId)->where('annee_scolaire_id', $anneeScolaireId))
                    ->ignore($classe->id),
            ],
            'capacite' => ['nullable', 'integer', 'min:0'],
            'niveau_id' => ['sometimes', 'required', 'exists:niveaux,id'],
            'annee_scolaire_id' => ['sometimes', 'required', 'exists:annees_scolaires,id'],
            'enseignant_principal_id' => ['sometimes', 'required', 'exists:enseignants,id'],
            'archivee' => ['nullable', 'boolean'],
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
