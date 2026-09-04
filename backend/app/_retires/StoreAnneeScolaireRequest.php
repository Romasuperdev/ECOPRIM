<?php

namespace App\Http\Requests;

use App\Models\AnneeScolaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAnneeScolaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:20', 'unique:annees_scolaires,libelle'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after:date_debut'],
            'active' => ['nullable', 'boolean'],
            'cloturee' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->date_debut || ! $this->date_fin) {
                return;
            }

            $chevauche = AnneeScolaire::where('date_debut', '<=', $this->date_fin)
                ->where('date_fin', '>=', $this->date_debut)
                ->exists();

            if ($chevauche) {
                $validator->errors()->add('date_debut', 'Les dates chevauchent une année scolaire existante.');
            }
        });
    }
}
