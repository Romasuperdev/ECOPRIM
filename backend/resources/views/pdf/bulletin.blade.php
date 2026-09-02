<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 12px; color: #1e293b; }
    h1 { font-size: 18px; text-align: center; margin-bottom: 0; }
    h2 { font-size: 13px; text-align: center; color: #64748b; margin-top: 4px; }
    .infos { margin: 16px 0; }
    .infos td { padding: 2px 8px 2px 0; }
    table.notes { width: 100%; border-collapse: collapse; margin-top: 12px; }
    table.notes th, table.notes td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
    table.notes th { background: #f1f5f9; }
    .moyenne-generale { margin-top: 16px; font-size: 14px; font-weight: bold; }
    .appreciation { margin-top: 20px; border-top: 1px solid #cbd5e1; padding-top: 10px; }
</style>
</head>
<body>
    <h1>NEXORA École Primaire</h1>
    <h2>Bulletin scolaire{{ $annee ? ' — '.$annee : '' }}{{ $session ? ' (session '.$session.')' : '' }}</h2>

    <table class="infos">
        <tr><td><strong>Élève :</strong></td><td>{{ $eleve->prenom }} {{ $eleve->nom }}</td></tr>
        <tr><td><strong>Matricule :</strong></td><td>{{ $eleve->matricule }}</td></tr>
        <tr><td><strong>Classe :</strong></td><td>{{ $eleve->classe?->nom ?? '—' }}</td></tr>
        <tr><td><strong>Né(e) le :</strong></td><td>{{ $eleve->date_naissance ?: '—' }}</td></tr>
    </table>

    <table class="notes">
        <thead>
            <tr>
                <th>Matière</th>
                <th>Coef.</th>
                <th>Notes</th>
                <th>Moyenne</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($moyennesParMatiere as $ligne)
                <tr>
                    <td>{{ $ligne['matiere'] }}</td>
                    <td>{{ $ligne['coefficient'] ?? '—' }}</td>
                    <td>{{ $ligne['nombre_notes'] }}</td>
                    <td>{{ $ligne['moyenne'] !== null ? $ligne['moyenne'].'/20' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Aucune note enregistrée pour cette année.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="moyenne-generale">
        Moyenne générale : {{ $moyenneGenerale !== null ? $moyenneGenerale.'/20' : '—' }}
        @if($rang) — Rang : {{ $rang }}@if($effectif) sur {{ $effectif }}@endif @endif
    </p>

</body>
</html>
