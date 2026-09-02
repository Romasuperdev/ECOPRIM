@extends('pdf.layout')

@section('titre', 'Fiche de l’élève')
@section('sousTitre', trim(($eleve->prenom ?? '').' '.($eleve->nom ?? '')).' — '.($eleve->matricule ?: 'sans matricule'))

@section('contenu')
    <table class="champs">
        <tr>
            <td style="width: 105px; padding-right: 14px;">
                @if($photo)
                    <img class="photo" src="{{ $photo }}" alt="">
                @else
                    <div class="photo" style="text-align:center; line-height:115px; color:#cbd5e1;">—</div>
                @endif
            </td>
            <td>
                <table class="champs">
                    <tr><td class="libelle">Matricule</td><td>{!! $v($eleve->matricule) !!}</td></tr>
                    <tr><td class="libelle">Nom et prénom(s)</td><td>{{ $eleve->nom }} {{ $eleve->prenom }}</td></tr>
                    <tr><td class="libelle">Sexe</td><td>{!! $v($eleve->sexe === 'F' ? 'Féminin' : ($eleve->sexe === 'M' ? 'Masculin' : null)) !!}</td></tr>
                    <tr><td class="libelle">Né(e) le</td><td>{!! $v($eleve->date_naissance) !!} @if($eleve->lieu_naissance) à {{ $eleve->lieu_naissance }} @endif</td></tr>
                    <tr><td class="libelle">Nationalité</td><td>{!! $v($eleve->nationalite) !!}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-titre">Scolarité</div>
        <table class="champs">
            <tr><td class="libelle">Année scolaire</td><td>{!! $v($eleve->annee) !!}</td></tr>
            <tr><td class="libelle">Classe</td><td>{!! $v($classe ?? $eleve->classe_code) !!}</td></tr>
            <tr><td class="libelle">Niveau</td><td>{!! $v($eleve->niveau_code) !!}</td></tr>
            <tr><td class="libelle">Cycle</td><td>{!! $v($eleve->cycle_code) !!}</td></tr>
            <tr><td class="libelle">Redoublant</td><td>{!! $v($eleve->redoublant) !!}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Père / Tuteur</div>
        <table class="champs">
            <tr><td class="libelle">Nom et prénom(s)</td><td>{!! $v(trim(($eleve->pere_nom ?? '').' '.($eleve->pere_prenom ?? ''))) !!}</td></tr>
            <tr><td class="libelle">Profession</td><td>{!! $v($eleve->pere_profession) !!}</td></tr>
            <tr><td class="libelle">Téléphone</td><td>{!! $v($eleve->pere_telephone) !!}</td></tr>
            <tr><td class="libelle">Email</td><td>{!! $v($eleve->pere_email) !!}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Mère</div>
        <table class="champs">
            <tr><td class="libelle">Nom et prénom(s)</td><td>{!! $v(trim(($eleve->mere_nom ?? '').' '.($eleve->mere_prenom ?? ''))) !!}</td></tr>
            <tr><td class="libelle">Profession</td><td>{!! $v($eleve->mere_profession) !!}</td></tr>
            <tr><td class="libelle">Téléphone</td><td>{!! $v($eleve->mere_telephone) !!}</td></tr>
            <tr><td class="libelle">Email</td><td>{!! $v($eleve->mere_email) !!}</td></tr>
        </table>
    </div>
@endsection
