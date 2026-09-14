@extends('pdf.layout')

@section('titre', 'Certificat de scolarité')

@section('contenu')
    <p>
        Le Directeur de l'établissement {{ $etablissement ?? 'NEXORA École Primaire' }}, soussigné, certifie que :
    </p>

    <table class="champs section">
        <tr><td class="libelle">Nom et prénom(s)</td><td>{{ trim(($eleve->nom ?? '').' '.($eleve->prenom ?? '')) }}</td></tr>
        <tr><td class="libelle">Né(e) le</td><td>{!! $v($eleve->date_naissance) !!} @if($eleve->lieu_naissance) à {{ $eleve->lieu_naissance }} @endif</td></tr>
        <tr><td class="libelle">Matricule</td><td>{!! $v($eleve->matricule) !!}</td></tr>
    </table>

    <p class="section">
        est régulièrement inscrit(e) dans notre établissement, en classe de
        <strong>{{ $classe ?? $eleve->classe_code ?: 'non renseignée' }}</strong>,
        au titre de l'année scolaire <strong>{{ $eleve->annee ?? $annee }}</strong>.
    </p>

    @if($motif)
        <p><em>Motif : {{ $motif }}</em></p>
    @endif

    <p class="section">
        En foi de quoi, le présent certificat est délivré à l'intéressé(e) pour servir et valoir ce que de droit.
    </p>

    <table class="champs" style="margin-top: 40px;">
        <tr>
            <td style="width: 60%;"></td>
            <td style="text-align: center;">
                Fait le {{ now()->format('d/m/Y') }}<br><br><br>
                Le Directeur
            </td>
        </tr>
    </table>
@endsection
