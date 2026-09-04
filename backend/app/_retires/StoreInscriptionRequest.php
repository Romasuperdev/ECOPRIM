<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eleve_id' => ['required', 'exists:eleves,id'],
            'annee_scolaire_id' => ['required', 'exists:annees_scolaires,id'],
            'classe_id' => ['nullable', 'exists:classes,id'],
            'type' => ['required', 'string', 'in:inscription,reinscription,transfert_entrant,transfert_sortant'],
            'date_mouvement' => ['required', 'date'],
            'etablissement_origine' => ['nullable', 'string', 'max:150'],
            'etablissement_destination' => ['nullable', 'string', 'max:150'],
            'observation' => ['nullable', 'string'],
        ];
    }
}
