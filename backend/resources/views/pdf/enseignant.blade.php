@extends('pdf.layout')

@section('titre', 'Fiche de l’enseignant')
@section('sousTitre', trim(($enseignant->prenom ?? '').' '.($enseignant->nom ?? '')).' — '.($enseignant->matricule ?: 'sans matricule'))

@section('contenu')
    <div class="section">
        <div class="section-titre">État civil</div>
        <table class="champs">
            <tr><td class="libelle">Matricule</td><td>{!! $v($enseignant->matricule) !!}</td></tr>
            <tr><td class="libelle">Nom et prénom(s)</td><td>{{ $enseignant->nom }} {{ $enseignant->prenom }}</td></tr>
            <tr><td class="libelle">Sexe</td><td>{!! $v($enseignant->sexe === 'F' ? 'Féminin' : ($enseignant->sexe === 'M' ? 'Masculin' : null)) !!}</td></tr>
            <tr><td class="libelle">Né(e) le</td><td>{!! $v($enseignant->date_naissance) !!} @if($enseignant->lieu_naissance) à {{ $enseignant->lieu_naissance }} @endif</td></tr>
            <tr><td class="libelle">Situation matrimoniale</td><td>{!! $v($enseignant->situation_matrimoniale) !!}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Coordonnées</div>
        <table class="champs">
            <tr><td class="libelle">Adresse</td><td>{!! $v($enseignant->adresse) !!}</td></tr>
            <tr><td class="libelle">Ville</td><td>{!! $v($enseignant->ville) !!}</td></tr>
            <tr><td class="libelle">Téléphone</td><td>{!! $v($enseignant->telephone) !!}</td></tr>
            <tr><td class="libelle">Cellulaire</td><td>{!! $v($enseignant->cellulaire) !!}</td></tr>
            <tr><td class="libelle">Email</td><td>{!! $v($enseignant->email) !!}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Carrière</div>
        <table class="champs">
            <tr><td class="libelle">Statut</td><td>{!! $v($enseignant->statut) !!}</td></tr>
            <tr><td class="libelle">Corps / grade / échelon</td><td>{!! $v(implode(' · ', array_filter([$enseignant->corps, $enseignant->grade, $enseignant->echelon]))) !!}</td></tr>
            <tr><td class="libelle">Diplôme</td><td>{!! $v($enseignant->diplome) !!}</td></tr>
            <tr><td class="libelle">Formation professionnelle</td><td>{!! $v($enseignant->formation) !!}</td></tr>
            <tr><td class="libelle">Matière</td><td>{!! $v($enseignant->matiere) !!}</td></tr>
            <tr><td class="libelle">Volume horaire</td><td>{!! $v($enseignant->volume_horaire ? $enseignant->volume_horaire.' h/semaine' : null) !!}</td></tr>
            <tr><td class="libelle">Date d’embauche</td><td>{!! $v($enseignant->date_embauche) !!}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Rattachement administratif</div>
        <table class="champs">
            <tr><td class="libelle">Fonction / emploi</td><td>{!! $v(implode(' · ', array_filter([$enseignant->fonction, $enseignant->emploi]))) !!}</td></tr>
            <tr><td class="libelle">DREN / DDEN</td><td>{!! $v(implode(' · ', array_filter([$enseignant->dren, $enseignant->dden]))) !!}</td></tr>
            <tr><td class="libelle">1re prise de service</td><td>{!! $v($enseignant->date_premiere_prise_service) !!} @if($enseignant->ecole_prise_service) — {{ $enseignant->ecole_prise_service }} @endif</td></tr>
            <tr><td class="libelle">Années de service</td><td>{!! $v($enseignant->annees_service) !!}</td></tr>
            <tr><td class="libelle">Arrivée au poste</td><td>{!! $v($enseignant->date_arrivee_poste) !!}</td></tr>
        </table>
    </div>

    @if($enseignant->date_depart || $enseignant->motif_depart)
        <div class="section">
            <div class="section-titre">Départ</div>
            <table class="champs">
                <tr><td class="libelle">Date</td><td>{!! $v($enseignant->date_depart) !!}</td></tr>
                <tr><td class="libelle">Motif</td><td>{!! $v($enseignant->motif_depart) !!}</td></tr>
                <tr><td class="libelle">Établissement d’accueil</td><td>{!! $v($enseignant->etab_accueil) !!}</td></tr>
            </table>
        </div>
    @endif
@endsection
