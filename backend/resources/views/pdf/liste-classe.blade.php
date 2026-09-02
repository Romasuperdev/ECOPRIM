@extends('pdf.layout')

@section('titre', 'Liste des élèves')
@section('sousTitre', 'Classe '.$classeLibelle.' — '.count($eleves).' élève(s)')

@section('contenu')
    @if(count($eleves) === 0)
        <p class="vide">Aucun élève inscrit dans cette classe pour l’année.</p>
    @else
        <table class="grille">
            <thead>
                <tr>
                    <th style="width: 28px;">N°</th>
                    <th style="width: 90px;">Matricule</th>
                    <th>Nom et prénom(s)</th>
                    <th style="width: 42px;">Sexe</th>
                    <th style="width: 82px;">Né(e) le</th>
                    <th style="width: 110px;">Contact tuteur</th>
                </tr>
            </thead>
            <tbody>
                @foreach($eleves as $i => $e)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $e->matricule ?: '—' }}</td>
                        <td>{{ $e->nom }} {{ $e->prenom }}</td>
                        <td>{{ $e->sexe ?: '—' }}</td>
                        <td>{{ $e->date_naissance ?: '—' }}</td>
                        <td>{{ $e->pere_telephone ?: ($e->mere_telephone ?: '—') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
