<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Services\AccesAutomatique;
use App\Services\MatieresEnseignantEcrivain;
use App\Services\ProfesseurEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Enseignants — ECONOMAT.dbo.T_PROFESSEUR.
 * Création et modification ; jamais de suppression : un départ se renseigne par
 * DateDepart / Motif. Le salaire et le mot de passe ne sont jamais écrits ici.
 */
class EnseignantController extends Controller
{
    public function __construct(
        private ProfesseurEcrivain $ecrivain,
        private AccesAutomatique $acces,
        private MatieresEnseignantEcrivain $matieres,
    ) {}

    /**
     * Réponse d'un enregistrement : la fiche, plus l'accès créé (ou retrouvé) au passage.
     * Le mot de passe n'y figure qu'à la création du compte — l'écran l'affiche une fois.
     */
    private function reponse(Enseignant $enseignant, array $data, int $statut = 200)
    {
        return response()->json(array_merge($enseignant->toArray(), [
            'matieres' => $this->matieres->pour($enseignant->matricule, $enseignant->annee_code),
            'acces_enseignant' => $this->acces->pourEnseignant($data, (int) $enseignant->getKey()),
        ]), $statut);
    }

    public function index(Request $request)
    {
        return Enseignant::when($request->filled('q'), function ($query) use ($request) {
            $q = $request->input('q');
            $query->where(fn ($w) => $w->where('NomProfesseur', 'like', "%{$q}%")
                ->orWhere('PrenomProfesseur', 'like', "%{$q}%")
                ->orWhere('MatriculeProfesseur', 'like', "%{$q}%"));
        })
            ->when($request->filled('matiere'), function ($q) use ($request) {
                $matiere = $request->input('matiere');
                // Un enseignant peut désormais avoir plusieurs matières : on cherche dans
                // la liste (T_CORPROFMAT) autant que dans l'ancienne colonne, qui ne
                // retient que la principale.
                $matricules = $this->matieres->matriculesEnseignant($matiere, ContexteScolaire::annee());
                $q->where(fn ($w) => $w->where('Matiere', $matiere)
                    ->when($matricules !== [], fn ($x) => $x->orWhereIn('MatriculeProfesseur', $matricules)));
            })
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'CodeAnnee'))
            ->orderBy('NomProfesseur')
            ->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Enseignant $enseignant)
    {
        return array_merge($enseignant->toArray(), [
            'matieres' => $this->matieres->pour($enseignant->matricule, $enseignant->annee_code),
        ]);
    }

    /**
     * @param  bool  $creation  Le matricule est exigé à la création. En modification, il
     *                          reste facultatif : le rendre obligatoire aurait bloqué la
     *                          modification de toute fiche existante qui n'en avait pas
     *                          encore un (comptes T_PROFESSEUR antérieurs à cette règle).
     */
    private function regles(bool $creation = true): array
    {
        $l = ProfesseurEcrivain::LARGEURS;

        return [
            // État civil
            'matricule' => [$creation ? 'required' : 'nullable', 'string', 'max:'.$l['matricule']],
            'nom' => ['required', 'string', 'max:'.$l['nom']],
            'prenom' => ['required', 'string', 'max:'.$l['prenom']],
            'sexe' => ['nullable', 'in:M,F'],
            'date_naissance' => ['nullable', 'string', 'max:'.$l['date_naissance']],
            'lieu_naissance' => ['nullable', 'string', 'max:'.$l['lieu_naissance']],
            'situation_matrimoniale' => ['nullable', 'string', 'max:'.$l['situation_matrimoniale']],

            // Coordonnées
            'adresse' => ['nullable', 'string', 'max:'.$l['adresse']],
            'ville' => ['nullable', 'string', 'max:'.$l['ville']],
            'telephone' => ['nullable', 'string', 'max:'.$l['telephone']],
            'cellulaire' => ['nullable', 'string', 'max:'.$l['cellulaire']],
            'email' => ['nullable', 'email', 'max:'.$l['email']],

            // Carrière
            'statut' => ['nullable', 'string', 'max:'.$l['statut']],
            'corps' => ['nullable', 'string', 'max:'.$l['corps']],
            'grade' => ['nullable', 'string', 'max:'.$l['grade']],
            'echelon' => ['nullable', 'string', 'max:'.$l['echelon']],
            'diplome' => ['nullable', 'string', 'max:'.$l['diplome']],
            'formation' => ['nullable', 'string', 'max:'.$l['formation']],
            'matiere' => ['nullable', 'string', 'max:'.$l['matiere']],
            'matieres' => ['nullable', 'array'],
            'matieres.*' => ['string', 'max:50', Rule::exists('economat.T_MATIERE', 'CodeMatiere')],
            'volume_horaire' => ['nullable', 'integer', 'min:0', 'max:60'],
            'date_embauche' => ['nullable', 'string', 'max:'.$l['date_embauche']],
            'annee_code' => ['nullable', 'string', 'max:'.$l['annee_code']],

            // Rattachement administratif
            'fonction' => ['nullable', 'string', 'max:'.$l['fonction']],
            'emploi' => ['nullable', 'string', 'max:'.$l['emploi']],
            'dren' => ['nullable', 'string', 'max:'.$l['dren']],
            'dden' => ['nullable', 'string', 'max:'.$l['dden']],
            'service' => ['nullable', 'string', 'max:'.$l['service']],
            'date_premiere_prise_service' => ['nullable', 'date'],
            'ecole_prise_service' => ['nullable', 'string', 'max:'.$l['ecole_prise_service']],
            'annees_service' => ['nullable', 'integer', 'min:0', 'max:60'],
            'date_arrivee_poste' => ['nullable', 'date'],

            // Départ
            'date_depart' => ['nullable', 'string', 'max:50'],
            'motif_depart' => ['nullable', 'string', 'max:'.$l['motif_depart']],
            'etab_accueil' => ['nullable', 'string', 'max:'.$l['etab_accueil']],
        ];
    }

    /**
     * Enregistre la liste des matières, et garde l'ancienne colonne cohérente.
     *
     * T_PROFESSEUR.Matiere continue de recevoir la PREMIÈRE matière de la liste : c'est
     * une colonne partagée avec ECONOMAT, qui l'affiche telle quelle. Y écrire une liste
     * séparée par des virgules l'aurait polluée, et elle ne tient de toute façon que
     * 50 caractères.
     */
    private function enregistrerMatieres(Enseignant $enseignant, array $data): void
    {
        if (! array_key_exists('matieres', $data)) {
            return;
        }

        $codes = collect($data['matieres'] ?? [])->filter()->unique()->values();
        $this->matieres->definir($enseignant->matricule, $enseignant->annee_code, $codes->all());

        $principale = (string) ($codes->first() ?? '');
        if ($principale !== (string) ($enseignant->matiere ?? '')) {
            $this->ecrivain->modifier((int) $enseignant->getKey(), ['matiere' => $principale]);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles());
        AnneeScolaireGuard::assertModifiable($data['annee_code'] ?? null, "L'enregistrement d'un enseignant");

        if (! empty($data['matricule']) && $this->ecrivain->matriculeExiste($data['matricule'])) {
            throw ValidationException::withMessages(['matricule' => ['Ce matricule est déjà attribué.']]);
        }

        $code = $this->ecrivain->creer($data);
        $enseignant = Enseignant::findOrFail($code);
        $this->enregistrerMatieres($enseignant, $data);

        return $this->reponse($enseignant->refresh(), $data, 201);
    }

    public function update(Request $request, Enseignant $enseignant)
    {
        $data = $request->validate($this->regles(creation: false));
        $code = (int) $enseignant->getKey();

        AnneeScolaireGuard::assertModifiable($enseignant->annee_code ?? null, 'La modification de cette fiche');
        AnneeScolaireGuard::assertModifiable($data['annee_code'] ?? null, 'Le rattachement à cette année');

        if (! empty($data['matricule']) && $this->ecrivain->matriculeExiste($data['matricule'], $code)) {
            throw ValidationException::withMessages(['matricule' => ['Ce matricule est déjà attribué.']]);
        }

        $this->ecrivain->modifier($code, $data);
        $enseignant = Enseignant::findOrFail($code);
        $this->enregistrerMatieres($enseignant, $data);

        // Un numéro renseigné après coup ouvre l'accès à ce moment-là.
        return $this->reponse($enseignant->refresh(), $data);
    }
}
