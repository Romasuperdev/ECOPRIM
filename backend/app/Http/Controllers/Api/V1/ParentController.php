<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Parents et tuteurs — annuaire DÉRIVÉ de ECONOMAT.dbo.T_ETUDIANT.
 *
 * Il n'y a pas de table de parents, et c'est volontaire : le père/tuteur et la mère sont
 * déjà des colonnes de la fiche élève (`NomPereTuteur`, `TelephoneMere`…), saisies par le
 * formulaire d'inscription. Une table séparée dupliquerait ces données et il faudrait les
 * tenir synchronisées — pour un résultat moins fiable que la source.
 *
 * Cette page les regroupe donc : une ligne par parent, avec ses enfants inscrits. Elle est
 * en lecture seule ; la modification se fait là où la donnée vit, dans Inscriptions. C'est
 * la même règle que pour la page Élèves : une seule porte d'écriture sur T_ETUDIANT.
 *
 * Le regroupement se fait sur le nom complet + le téléphone : deux parents homonymes sans
 * téléphone restent distincts si leurs enfants ne partagent rien d'autre — on préfère
 * scinder à tort que fusionner deux familles.
 */
class ParentController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'classe' => ['nullable', 'string', 'max:50'],
            'lien' => ['nullable', 'string', 'in:pere,mere'],
        ]);

        $eleves = Eleve::query()
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->when($data['classe'] ?? null, fn ($q, $c) => $q->where('CodeClasse', $c))
            ->orderBy('Nom')->orderBy('Prenom')
            ->get();

        $parents = $this->regrouper($eleves, $data['lien'] ?? null);

        if (! empty($data['q'])) {
            $recherche = mb_strtolower(trim($data['q']));
            $parents = $parents->filter(fn ($p) => str_contains(
                mb_strtolower($p['nom'].' '.$p['prenom'].' '.$p['telephone'].' '.$p['email']),
                $recherche
            ));
        }

        $parents = $parents->sortBy(fn ($p) => mb_strtolower($p['nom'].' '.$p['prenom']))->values();

        return [
            'annee' => ContexteScolaire::annee(),
            'total' => $parents->count(),
            'data' => $parents,
        ];
    }

    /** Un parent, ses coordonnées et ses enfants inscrits. */
    private function regrouper(Collection $eleves, ?string $lienVoulu): Collection
    {
        $parents = [];

        foreach ($eleves as $eleve) {
            foreach ([['pere', 'Père / Tuteur'], ['mere', 'Mère']] as [$prefixe, $libelle]) {
                if ($lienVoulu && $lienVoulu !== $prefixe) {
                    continue;
                }

                $nom = trim((string) $eleve->{$prefixe.'_nom'});
                $prenom = trim((string) $eleve->{$prefixe.'_prenom'});
                if ($nom === '' && $prenom === '') {
                    continue; // Parent non renseigné : on ne crée pas une ligne vide.
                }

                $telephone = trim((string) $eleve->{$prefixe.'_telephone'});
                $cle = mb_strtolower($nom.'|'.$prenom.'|'.$telephone);

                $parents[$cle] ??= [
                    'cle' => md5($cle),
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'lien' => $libelle,
                    'telephone' => $telephone,
                    'email' => trim((string) $eleve->{$prefixe.'_email'}),
                    'profession' => trim((string) $eleve->{$prefixe.'_profession'}),
                    'enfants' => [],
                ];

                // Un même parent peut être père d'un élève et tuteur d'un autre : on garde
                // les deux libellés plutôt que d'en écraser un.
                if (! str_contains($parents[$cle]['lien'], $libelle)) {
                    $parents[$cle]['lien'] .= ' · '.$libelle;
                }

                // Une coordonnée manquante sur une fiche peut être renseignée sur une autre.
                foreach (['email' => $prefixe.'_email', 'profession' => $prefixe.'_profession'] as $champ => $source) {
                    if ($parents[$cle][$champ] === '') {
                        $parents[$cle][$champ] = trim((string) $eleve->{$source});
                    }
                }

                $parents[$cle]['enfants'][] = [
                    'id' => $eleve->getRawOriginal('Code'),
                    'matricule' => $eleve->matricule,
                    'nom' => trim($eleve->nom.' '.$eleve->prenom),
                    'classe' => $eleve->classe_code,
                ];
            }
        }

        return collect(array_values($parents))->map(function ($p) {
            $p['nb_enfants'] = count($p['enfants']);

            return $p;
        });
    }
}
