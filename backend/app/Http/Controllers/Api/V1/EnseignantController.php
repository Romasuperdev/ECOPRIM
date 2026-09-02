<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Services\ProfesseurEcrivain;
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
            ->orderBy('NomProfesseur')
            ->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Enseignant $enseignant)
    {
        return $enseignant;
    }

    private function regles(): array
    {
        $l = ProfesseurEcrivain::LARGEURS;

        return [
            'matricule' => ['nullable', 'string', 'max:'.$l['matricule']],
            'nom' => ['required', 'string', 'max:'.$l['nom']],
            'prenom' => ['required', 'string', 'max:'.$l['prenom']],
            'sexe' => ['nullable', 'string', 'max:'.$l['sexe']],
            'date_naissance' => ['nullable', 'string', 'max:'.$l['date_naissance']],
            'lieu_naissance' => ['nullable', 'string', 'max:'.$l['lieu_naissance']],
            'situation_matrimoniale' => ['nullable', 'string', 'max:'.$l['situation_matrimoniale']],
            'adresse' => ['nullable', 'string', 'max:'.$l['adresse']],
            'ville' => ['nullable', 'string', 'max:'.$l['ville']],
            'telephone' => ['nullable', 'string', 'max:'.$l['telephone']],
            'cellulaire' => ['nullable', 'string', 'max:'.$l['cellulaire']],
            'email' => ['nullable', 'email', 'max:'.$l['email']],
            'statut' => ['nullable', 'string', 'max:'.$l['statut']],
            'grade' => ['nullable', 'string', 'max:'.$l['grade']],
            'diplome' => ['nullable', 'string', 'max:'.$l['diplome']],
            'matiere' => ['nullable', 'string', 'max:'.$l['matiere']],
            'date_embauche' => ['nullable', 'string', 'max:'.$l['date_embauche']],
            'annee_code' => ['nullable', 'string', 'max:'.$l['annee_code']],
            'date_depart' => ['nullable', 'string', 'max:50'],
            'motif_depart' => ['nullable', 'string', 'max:'.$l['motif_depart']],
            'etab_accueil' => ['nullable', 'string', 'max:'.$l['etab_accueil']],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles());

        if (! empty($data['matricule']) && $this->ecrivain->matriculeExiste($data['matricule'])) {
            throw ValidationException::withMessages(['matricule' => ['Ce matricule est déjà attribué.']]);
        }

        $code = $this->ecrivain->creer($data);

        return response()->json(Enseignant::findOrFail($code), 201);
    }

    public function update(Request $request, Enseignant $enseignant)
    {
        $data = $request->validate($this->regles());
        $code = (int) $enseignant->getKey();

        if (! empty($data['matricule']) && $this->ecrivain->matriculeExiste($data['matricule'], $code)) {
            throw ValidationException::withMessages(['matricule' => ['Ce matricule est déjà attribué.']]);
        }

        $this->ecrivain->modifier($code, $data);

        return response()->json(Enseignant::findOrFail($code));
    }
}
