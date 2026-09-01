<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\RhUser;
use App\Services\RhUserEcrivain;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Utilisateurs — identité dans dbmasterbacou.RH_USER.
 * Création et modification autorisées ; JAMAIS de suppression : un compte retiré est
 * désactivé (Supprimer = 1). Les affectations (société/établissements/rôles) restent
 * gérées dans la base propre ECOPRIM.
 */
class UserController extends Controller
{
    public function __construct(private RhUserEcrivain $ecrivain) {}

    public function index(Request $request)
    {
        $users = RhUser::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->input('q');
                $query->where(fn ($w) => $w->where('Nom', 'like', "%{$q}%")
                    ->orWhere('Prenom', 'like', "%{$q}%")
                    ->orWhere('Login', 'like', "%{$q}%")
                    ->orWhere('Email', 'like', "%{$q}%"));
            })
            ->orderBy('Nom')
            ->paginate(min($request->integer('per_page', 20), 200));

        $users->getCollection()->transform(fn (RhUser $u) => $this->ligne($u));

        return $users;
    }

    public function show(string $user)
    {
        $rh = RhUser::findOrFail($user);
        $affectations = Affectation::with(['etablissement', 'role'])
            ->where('rh_user_id', $rh->Id)->orderBy('etablissement_code')->get();

        return array_merge($this->ligne($rh), [
            'societe_code' => optional($affectations->first())->societe_code,
            'affectations' => $affectations,
        ]);
    }

    private function regles(bool $creation, ?int $id = null): array
    {
        $l = RhUserEcrivain::LARGEURS;

        return [
            'login' => ['required', 'string', 'max:'.$l['login']],
            'mot_de_passe' => $creation
                ? ['required', 'string', 'min:6', 'max:100']
                : ['nullable', 'string', 'min:6', 'max:100'],
            'nom' => ['required', 'string', 'max:'.$l['nom']],
            'prenom' => ['nullable', 'string', 'max:'.$l['prenom']],
            'email' => ['nullable', 'email', 'max:'.$l['email']],
            'matricule' => ['nullable', 'string', 'max:'.$l['matricule']],
            'etab' => ['nullable', 'string', 'max:'.$l['etab']],
            'contact' => ['nullable', 'string', 'max:'.$l['contact']],
            'profil' => ['nullable', 'string', 'max:'.$l['profil']],
            'code_app' => ['nullable', 'string', 'max:'.$l['code_app']],
            'super_admin' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));

        if ($this->ecrivain->loginExiste($data['login'])) {
            throw ValidationException::withMessages(['login' => ['Ce login est déjà utilisé.']]);
        }

        $id = $this->ecrivain->creer($data);

        return response()->json($this->ligne(RhUser::findOrFail($id)), 201);
    }

    public function update(Request $request, string $user)
    {
        $rh = RhUser::findOrFail($user);
        $data = $request->validate($this->regles(false, (int) $rh->Id));

        if ($this->ecrivain->loginExiste($data['login'], (int) $rh->Id)) {
            throw ValidationException::withMessages(['login' => ['Ce login est déjà utilisé.']]);
        }

        $this->ecrivain->modifier((int) $rh->Id, $data);

        return response()->json($this->ligne(RhUser::findOrFail($rh->Id)));
    }

    public function activer(string $user)
    {
        $rh = RhUser::findOrFail($user);
        $this->ecrivain->definirActif((int) $rh->Id, true);

        return response()->json($this->ligne(RhUser::findOrFail($rh->Id)));
    }

    /** Désactivation logique (Supprimer = 1) : le compte ne peut plus se connecter. */
    public function desactiver(string $user)
    {
        $rh = RhUser::findOrFail($user);
        $this->ecrivain->definirActif((int) $rh->Id, false);

        return response()->json($this->ligne(RhUser::findOrFail($rh->Id)));
    }

    private function ligne(RhUser $u): array
    {
        return [
            'id' => $u->Id,
            'name' => trim("{$u->Prenom} {$u->Nom}") ?: $u->Login,
            'login' => $u->Login,
            'nom' => $u->Nom,
            'prenom' => $u->Prenom,
            'matricule' => $u->Matricule,
            'email' => $u->Email,
            'etab' => $u->Etab,
            'contact' => $u->Contact,
            'profil' => $u->Profil,
            'code_app' => $u->CodeApp,
            'super_admin' => (bool) $u->SuperAdmin,
            'actif' => ! $u->estSupprime(),
        ];
    }
}
