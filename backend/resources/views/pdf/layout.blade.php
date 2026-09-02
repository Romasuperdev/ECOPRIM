{{--
  Mise en page commune des documents imprimables NEXORA.
  Sections attendues : `titre` (obligatoire) et `contenu`.
  Variables : $etablissement, $annee, $sousTitre (facultatives).
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 22mm 16mm 18mm 16mm; }
    body { font-family: sans-serif; font-size: 11.5px; color: #1e293b; }

    .entete { border-bottom: 2px solid #c08a45; padding-bottom: 8px; margin-bottom: 16px; }
    .entete .marque { font-size: 15px; font-weight: bold; color: #5a4c43; }
    .entete .etab { font-size: 11px; color: #64748b; }
    .entete .annee { float: right; font-size: 11px; color: #64748b; }

    h1 { font-size: 16px; margin: 0 0 2px; }
    .sous-titre { font-size: 11px; color: #64748b; margin: 0 0 14px; }

    .section { margin-top: 14px; }
    .section-titre {
        font-size: 9.5px; font-weight: bold; text-transform: uppercase;
        letter-spacing: .07em; color: #c08a45;
        border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; margin-bottom: 6px;
    }

    table.champs { width: 100%; border-collapse: collapse; }
    table.champs td { padding: 3px 8px 3px 0; vertical-align: top; }
    table.champs td.libelle { color: #64748b; width: 30%; }
    .vide { color: #cbd5e1; font-style: italic; }

    table.grille { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.grille th, table.grille td {
        border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; font-size: 10.5px;
    }
    table.grille th { background: #f1f5f9; }
    table.grille td.vide-case { background: #fafbfc; }

    .photo { width: 90px; height: 115px; object-fit: cover; border: 1px solid #cbd5e1; }

    .pied {
        position: fixed; bottom: -12mm; left: 0; right: 0;
        font-size: 8.5px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 4px;
    }
</style>
</head>
<body>
    <div class="entete">
        <span class="annee">{{ $annee ?? '' }}</span>
        <div class="marque">NEXORA École Primaire</div>
        <div class="etab">{{ $etablissement ?? '' }}</div>
    </div>

    <h1>@yield('titre')</h1>
    @hasSection('sousTitre')
        <p class="sous-titre">@yield('sousTitre')</p>
    @endif

    @yield('contenu')

    <div class="pied">
        Document édité le {{ now()->format('d/m/Y à H:i') }} — NEXORA École Primaire
    </div>
</body>
</html>
