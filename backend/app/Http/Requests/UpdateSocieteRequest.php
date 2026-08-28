<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocieteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('societes', 'code')->ignore($this->route('societe'))],
            'nom' => ['required', 'string', 'max:150'],
            'sigle' => ['nullable', 'string', 'max:20'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'adresse_ligne2' => ['nullable', 'string', 'max:150'],
            'code_postal' => ['nullable', 'string', 'max:20'],
            'ville' => ['nullable', 'string', 'max:100'],
            'pays' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'fax' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'site_web' => ['nullable', 'string', 'max:255'],
            'activite_principale' => ['nullable', 'string', 'max:150'],
            'activite_secondaire' => ['nullable', 'string', 'max:150'],
            'forme_juridique' => ['nullable', 'string', 'max:100'],
            'regime_fiscal' => ['nullable', 'string', 'max:100'],
            'capital' => ['nullable', 'numeric', 'min:0'],
            'representant_civilite' => ['nullable', 'string', 'max:20'],
            'representant_nom' => ['nullable', 'string', 'max:150'],
            'representant_fonction' => ['nullable', 'string', 'max:100'],
            'representant_telephone' => ['nullable', 'string', 'max:30'],
            'representant_mobile' => ['nullable', 'string', 'max:30'],
        ];
    }
}
