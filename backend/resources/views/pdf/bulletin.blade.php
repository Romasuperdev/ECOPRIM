@extends('pdf.layout')

@php
    // Repère de lecture rapide, purement indicatif : NEXORA ne calcule aucune moyenne,
    // ce n'est qu'un habillage du chiffre déjà renvoyé par ECONOMAT.
    $appreciation = function (?float $moyenne): ?string {
        if ($moyenne === null) {
            return null;
        }

        return match (true) {
            $moyenne >= 16 => 'Très bien',
            $moyenne >= 14 => 'Bien',
            $moyenne >= 12 => 'Assez bien',
            $moyenne >= 10 => 'Passable',
            default => 'Insuffisant',
        };
    };
@endphp

@section('titre', 'Bulletin scolaire')
@section('sousTitre', 'Classe '.($eleve->classe?->nom ?? '—').($session ? ' — Session '.$session : ''))

@section('contenu')
    <div class="section">
        <div class="section-titre">Élève</div>
        <table class="champs">
            <tr>
                <td class="libelle">Nom et prénom(s)</td>
                <td>{{ $eleve->prenom }} {{ $eleve->nom }}</td>
                <td class="libelle">Matricule</td>
                <td>{{ $eleve->matricule ?: '—' }}</td>
            </tr>
            <tr>
                <td class="libelle">Classe</td>
                <td>{{ $eleve->classe?->nom ?? '—' }}</td>
                <td class="libelle">Né(e) le</td>
                <td>{{ $eleve->date_naissance ?: '—' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Résultats par matière</div>
        <table class="grille">
            <thead>
                <tr>
                    <th>Matière</th>
                    <th style="width: 55px;">Coef.</th>
                    <th style="width: 55px;">Notes</th>
                    <th style="width: 75px;">Moyenne</th>
                    <th style="width: 100px;">Appréciation</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($moyennesParMatiere as $ligne)
                    <tr>
                        <td>{{ $ligne['matiere'] }}</td>
                        <td>{{ $ligne['coefficient'] !== null ? rtrim(rtrim(number_format($ligne['coefficient'], 1), '0'), '.') : '—' }}</td>
                        <td>{{ $ligne['nombre_notes'] }}</td>
                        <td>{{ $ligne['moyenne'] !== null ? number_format($ligne['moyenne'], 2).'/20' : '—' }}</td>
                        <td>{{ $appreciation($ligne['moyenne']) ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="vide-case vide">Aucune note enregistrée pour cette période.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Synthèse</div>
        <table class="champs">
            <tr>
                <td class="libelle">Moyenne générale</td>
                <td>
                    <strong>{{ $moyenneGenerale !== null ? number_format($moyenneGenerale, 2).'/20' : '—' }}</strong>
                    @if($moyenneGenerale !== null)
                        — {{ $appreciation($moyenneGenerale) }}
                    @endif
                </td>
                <td class="libelle">Rang</td>
                <td>{{ $rang ? $rang.($effectif ? ' sur '.$effectif.' élève(s)' : '') : '—' }}</td>
            </tr>
        </table>
    </div>

    <table class="champs" style="margin-top: 50px;">
        <tr>
            <td style="width: 50%; text-align: center;">
                Le Directeur / La Directrice
                <div style="margin-top: 30px; border-top: 1px solid #cbd5e1; width: 70%; margin-left: auto; margin-right: auto;"></div>
            </td>
            <td style="width: 50%; text-align: center;">
                Le Parent / Tuteur
                <div style="margin-top: 30px; border-top: 1px solid #cbd5e1; width: 70%; margin-left: auto; margin-right: auto;"></div>
            </td>
        </tr>
    </table>
@endsection
