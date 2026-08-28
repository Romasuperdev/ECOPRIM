<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEleveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricule' => ['required', 'string', 'max:30', 'unique:eleves,matricule'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'date_naissance' => ['required', 'date'],
            'numero_acte_naissance' => ['nullable', 'string', 'max:50'],
            'acte_delivre_par' => ['nullable', 'string', 'max:150'],
            'lieu_naissance' => ['nullable', 'string', 'max:150'],
            'pays_naissance' => ['nullable', 'string', 'max:100'],
            'nationalite' => ['nullable', 'string', 'max:100'],
            'sexe' => ['required', 'string', 'in:M,F'],
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

            // Père/tuteur et mère — création rapide du parent + rattachement à l'élève
            'pere_tuteur_lien' => ['nullable', 'string', 'in:pere,tuteur'],
            'pere_nom' => ['nullable', 'string', 'max:100'],
            'pere_prenom' => ['nullable', 'required_with:pere_nom', 'string', 'max:100'],
            'pere_telephone' => ['nullable', 'string', 'max:30'],
            'pere_email' => ['nullable', 'email', 'max:150'],
            'pere_profession' => ['nullable', 'string', 'max:100'],
            'mere_nom' => ['nullable', 'string', 'max:100'],
            'mere_prenom' => ['nullable', 'required_with:mere_nom', 'string', 'max:100'],
            'mere_telephone' => ['nullable', 'string', 'max:30'],
            'mere_email' => ['nullable', 'email', 'max:150'],
            'mere_profession' => ['nullable', 'string', 'max:100'],
        ];
    }
}
