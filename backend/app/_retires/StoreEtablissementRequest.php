<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEtablissementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || $user->isSuperAdmin()) {
            return true;
        }

        // Un Admin Société ne peut créer un établissement que sous l'une de ses propres sociétés.
        return in_array((int) $this->input('societe_id'), $user->allowedSocieteIds(), true);
    }

    public function rules(): array
    {
        return [
            'societe_id' => ['required', 'exists:societes,id'],
            'code' => ['required', 'string', 'max:30', 'unique:etablissements,code'],
            'nom' => ['required', 'string', 'max:150'],
            'type' => ['nullable', Rule::in(['maternelle', 'primaire', 'college', 'lycee', 'groupe_scolaire', 'secondaire'])],
            'couleur_primaire' => ['nullable', 'string', 'max:10'],
            'couleur_secondaire' => ['nullable', 'string', 'max:10'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'site_web' => ['nullable', 'string', 'max:150'],
            'nom_responsable' => ['nullable', 'string', 'max:150'],
            'prenom_responsable' => ['nullable', 'string', 'max:150'],
            'fonction_responsable' => ['nullable', 'string', 'max:100'],
            'contact_responsable' => ['nullable', 'string', 'max:30'],
            'email_directeur' => ['nullable', 'email', 'max:150'],
            'sous_prefecture' => ['nullable', 'string', 'max:100'],
            'circonscription' => ['nullable', 'string', 'max:100'],
            'code_iep' => ['nullable', 'string', 'max:30'],
            'intitule_iep' => ['nullable', 'string', 'max:150'],
            'code_dren' => ['nullable', 'string', 'max:30'],
            'intitule_dren' => ['nullable', 'string', 'max:150'],
        ];
    }
}
