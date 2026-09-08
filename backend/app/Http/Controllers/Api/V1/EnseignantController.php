<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Services\ProfesseurEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Enseignants — ECONOMAT.dbo.T_PROFESSEUR.
 * Création et modification ; jamais de suppression : un départ se renseigne par
 * DateDepart / Motif. Le salaire et le mot de passe ne sont jamais écrits ici.
 */
class EnseignantController extends Controller
{
    public function __construct(private ProfesseurEcrivain $ecrivain) {}

    public function index(Request $request)
    {
        return Enseignant::when($request->filled('q'), function ($query) use ($request) {
            $q = $request->input('q');
            $query->where(fn ($w) => $w->where('NomProfesseur', 'like', "%{$q}%")
                ->orWhere('PrenomProfesseur', 'like', "%{$q}%")
                ->orWhere('MatriculeProfesseur', 'like', "%{$q}%"));
        })
            ->when($request->filled('matiere'), fn ($q) => $q->where('Matiere', $request->input('matiere')))
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'CodeAnnee'))
            ->orderBy('NomProfesseur')
            ->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Enseignant $enseignant)
    {
        return $enseignant;
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

    public function store(Request $request)
    {
        $data = $request->validate($this->regles());
        AnneeScolaireGuard::assertModifiable($data['annee_code'] ?? null, "L'enregistrement d'un enseignant");

        if (! empty($data['matricule']) && $this->ecrivain->matriculeExiste($data['matricule'])) {
            throw ValidationException::withMessages(['matricule' => ['Ce matricule est déjà attribué.']]);
        }

        $code = $this->ecrivain->creer($data);

        return response()->json(Enseignant::findOrFail($code), 201);
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

        return response()->json(Enseignant::findOrFail($code));
    }
}
