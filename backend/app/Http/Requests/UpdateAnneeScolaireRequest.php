<?php

namespace App\Http\Requests;

use App\Models\AnneeScolaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAnneeScolaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'libelle' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('annees_scolaires', 'libelle')->ignore($this->route('anneeScolaire'))],
            'date_debut' => ['sometimes', 'required', 'date'],
            'date_fin' => ['sometimes', 'required', 'date', 'after:date_debut'],
            'active' => ['nullable', 'boolean'],
            'cloturee' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $annee = $this->route('anneeScolaire');
            $debut = $this->input('date_debut', $annee->date_debut);
            $fin = $this->input('date_fin', $annee->date_fin);

            $chevauche = AnneeScolaire::where('id', '!=', $annee->id)
                ->where('date_debut', '<=', $fin)
                ->where('date_fin', '>=', $debut)
                ->exists();

            if ($chevauche) {
                $validator->errors()->add('date_debut', 'Les dates chevauchent une année scolaire existante.');
            }
        });
    }
}
