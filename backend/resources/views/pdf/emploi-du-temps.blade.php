@extends('pdf.layout')

@section('titre', 'Emploi du temps')
@section('sousTitre', 'Classe '.$classeLibelle)

@section('contenu')
    @if(empty($jours) || empty($heures))
        <p class="vide">La trame horaire n’est pas renseignée : aucune grille à imprimer.</p>
    @else
        <table class="grille">
            <thead>
                <tr>
                    <th style="width: 78px;">Horaire</th>
                    @foreach($jours as $j)
                        <th>{{ $j['libelle'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($heures as $h)
                    <tr>
                        <th>{{ $h['libelle'] ?: $h['code'] }}</th>
                        @foreach($jours as $j)
                            @php $c = $creneau($j['code'], $h['code']); @endphp
                            <td class="{{ $c ? '' : 'vide-case' }}">
                                @if($c)
                                    <strong>{{ $c['matiere_libelle'] }}</strong>
                                    @if($c['enseignant'])<br><span style="color:#64748b;">{{ $c['enseignant'] }}</span>@endif
                                    @if($c['salle_libelle'])<br><span style="color:#94a3b8;">{{ $c['salle_libelle'] }}</span>@endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
