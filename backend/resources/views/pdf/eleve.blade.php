{{--
  Fiche de l'élève.

  Elle porte TOUT ce que l'inscription fait saisir — état civil, scolarité, coordonnées,
  filiation. Une fiche qui n'en montrerait qu'une partie obligerait à rouvrir l'écran pour
  le reste, et c'est précisément ce qu'on imprime pour éviter.

  Elle ne porte en revanche AUCUN résultat : moyennes, rangs et appréciations sont l'objet
  du bulletin. Les mettre aux deux endroits, c'est prendre le risque qu'ils divergent.
--}}
@extends('pdf.layout')

@section('titre', 'Fiche de l’élève')

@section('contenu')
    {{-- Bandeau d'identité : la photo, le nom, et ce qu'on cherche des yeux en premier. --}}
    <div class="identite">
        <table class="champs">
            <tr>
                <td style="width: 104px; padding-right: 14px;">
                    @if($photo)
                        <img class="photo" src="{{ $photo }}" alt="">
                    @else
                        <div class="photo" style="background:#ffffff; text-align:center; line-height:115px; color:#cbd5e1;">
                            Photo
                        </div>
                    @endif
                </td>
                <td style="vertical-align: top;">
                    <div class="nom">{{ $eleve->nom }} {{ $eleve->prenom }}</div>
                    <div style="margin: 6px 0 8px;">
                        <span class="chip">{{ $eleve->matricule ?: 'Sans matricule' }}</span>
                        @if($classe ?? $eleve->classe_code)
                            <span class="chip chip-clair">{{ $classe ?? $eleve->classe_code }}</span>
                        @endif
                        @if($details['mouvement'])
                            <span class="chip chip-clair">{{ $details['mouvement'] }}</span>
                        @endif
                    </div>
                    <table class="champs">
                        <tr>
                            <td class="libelle" style="width: 26%;">Né(e) le</td>
                            <td>
                                {!! $v($d($eleve->date_naissance)) !!}
                                @if($details['age'] !== null)
                                    <span style="color:#64748b;">({{ $details['age'] }} ans)</span>
                                @endif
                                @if($eleve->lieu_naissance) à {{ $eleve->lieu_naissance }} @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="libelle">Sexe</td>
                            <td>{!! $v($eleve->sexe === 'F' ? 'Féminin' : ($eleve->sexe === 'M' ? 'Masculin' : null)) !!}</td>
                        </tr>
                        <tr>
                            <td class="libelle">Nationalité</td>
                            <td>{!! $v($eleve->nationalite) !!}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-titre">Scolarité</div>
        <div class="bloc bloc-accent">
            <table class="col2">
                <tr>
                    <td class="col">
                        <table class="champs">
                            <tr><td class="libelle" style="width:46%;">Année scolaire</td><td>{!! $v($eleve->annee) !!}</td></tr>
                            <tr><td class="libelle">Classe</td><td>{!! $v($classe ?? $eleve->classe_code) !!}</td></tr>
                            <tr><td class="libelle">Niveau</td><td>{!! $v($eleve->niveau_code) !!}</td></tr>
                        </table>
                    </td>
                    <td class="col">
                        <table class="champs">
                            <tr><td class="libelle" style="width:46%;">Inscrit(e) le</td><td>{!! $v($d($details['date_inscription'])) !!}</td></tr>
                            <tr><td class="libelle">Mouvement</td><td>{!! $v($details['mouvement']) !!}</td></tr>
                            <tr><td class="libelle">Redoublant(e)</td><td>{!! $v($eleve->redoublant) !!}</td></tr>
                        </table>
                    </td>
                </tr>
            </table>

            @if($details['etab_origine'] || $details['niveau_origine'])
                <table class="champs" style="margin-top: 4px; border-top: 1px solid #bfdbfe; padding-top: 4px;">
                    <tr>
                        <td class="libelle" style="width: 23%;">Établissement d’origine</td>
                        <td>{!! $v($details['etab_origine']) !!}</td>
                        <td class="libelle" style="width: 17%;">Niveau d’origine</td>
                        <td>{!! $v($details['niveau_origine']) !!}</td>
                    </tr>
                </table>
            @endif
        </div>
    </div>

    <div class="section">
        <div class="section-titre">Coordonnées de l’élève</div>
        <table class="col2">
            <tr>
                <td class="col">
                    <table class="champs">
                        <tr><td class="libelle" style="width:46%;">Adresse</td><td>{!! $v($details['adresse']) !!}</td></tr>
                        <tr><td class="libelle">Quartier</td><td>{!! $v($details['quartier']) !!}</td></tr>
                        <tr><td class="libelle">Commune</td><td>{!! $v($details['commune']) !!}</td></tr>
                    </table>
                </td>
                <td class="col">
                    <table class="champs">
                        <tr><td class="libelle" style="width:46%;">Ville</td><td>{!! $v($details['ville']) !!}</td></tr>
                        <tr><td class="libelle">Téléphone</td><td>{!! $v($details['telephone']) !!}</td></tr>
                        <tr><td class="libelle">Email</td><td>{!! $v($details['email']) !!}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- Les deux parents côte à côte : c'est ainsi qu'on les compare d'un coup d'œil, et
         cela tient la fiche sur une seule page. --}}
    <div class="section">
        <div class="section-titre">Filiation</div>
        <table class="col2">
            <tr>
                <td class="col">
                    <div class="bloc">
                        <div style="font-weight: bold; color:#1e3a8a; margin-bottom: 5px;">Père / Tuteur</div>
                        <table class="champs">
                            <tr><td class="libelle" style="width:44%;">Nom et prénom(s)</td><td>{!! $v(trim(($eleve->pere_nom ?? '').' '.($eleve->pere_prenom ?? ''))) !!}</td></tr>
                            <tr><td class="libelle">Profession</td><td>{!! $v($eleve->pere_profession) !!}</td></tr>
                            <tr><td class="libelle">Téléphone</td><td>{!! $v($eleve->pere_telephone) !!}</td></tr>
                            <tr><td class="libelle">Email</td><td>{!! $v($eleve->pere_email) !!}</td></tr>
                        </table>
                    </div>
                </td>
                <td class="col">
                    <div class="bloc">
                        <div style="font-weight: bold; color:#1e3a8a; margin-bottom: 5px;">Mère</div>
                        <table class="champs">
                            <tr><td class="libelle" style="width:44%;">Nom et prénom(s)</td><td>{!! $v(trim(($eleve->mere_nom ?? '').' '.($eleve->mere_prenom ?? ''))) !!}</td></tr>
                            <tr><td class="libelle">Profession</td><td>{!! $v($eleve->mere_profession) !!}</td></tr>
                            <tr><td class="libelle">Téléphone</td><td>{!! $v($eleve->mere_telephone) !!}</td></tr>
                            <tr><td class="libelle">Email</td><td>{!! $v($eleve->mere_email) !!}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Une fiche se remet et se signe : sans ces deux lignes, elle reste un écran imprimé. --}}
    <table class="signatures">
        <tr>
            <td><div class="ligne">Le parent / tuteur</div></td>
            <td><div class="ligne">La Direction</div></td>
        </tr>
    </table>
@endsection
