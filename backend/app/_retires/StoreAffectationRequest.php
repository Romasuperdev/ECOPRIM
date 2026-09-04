<?php

namespace App\Http\Requests;

use App\Models\Affectation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAffectationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || $user->isSuperAdmin()) {
            return true;
        }

        // Un Admin Société ne peut affecter que dans le périmètre de ses propres sociétés/établissements.
        $societeIds = $user->allowedSocieteIds();
        $etablissementIds = $user->allowedEtablissementIds();

        if ($this->filled('societe_id')) {
            return in_array((int) $this->input('societe_id'), $societeIds, true);
        }

        if ($this->filled('etablissement_id')) {
            $etablissement = \App\Models\Etablissement::withoutPerimetre()->find($this->input('etablissement_id'));

            return $etablissement && in_array($etablissement->societe_id, $societeIds, true);
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'societe_id' => ['nullable', 'exists:societes,id'],
            'etablissement_id' => ['nullable', 'exists:etablissements,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Cohérence société/établissement : une société peut avoir plusieurs
            // établissements, mais un établissement appartient à une seule société — si les
            // deux sont fournis, ils doivent correspondre.
            if ($this->filled('societe_id') && $this->filled('etablissement_id')) {
                $etablissement = \App\Models\Etablissement::withoutPerimetre()->find($this->input('etablissement_id'));

                if ($etablissement && $etablissement->societe_id !== (int) $this->input('societe_id')) {
                    $validator->errors()->add('etablissement_id', "Cet établissement n'appartient pas à la société sélectionnée.");
                }
            }

            $doublon = Affectation::query()
                ->where('user_id', $this->input('user_id'))
                ->where('role_id', $this->input('role_id'))
                ->where('actif', true)
                ->when(
                    $this->input('etablissement_id'),
                    fn ($q) => $q->where('etablissement_id', $this->input('etablissement_id')),
                    fn ($q) => $q->whereNull('etablissement_id')
                )
                ->exists();

            if ($doublon) {
                $validator->errors()->add('role_id', "Cet utilisateur a déjà cette affectation (même établissement, même rôle).");
            }
        });
    }
}
