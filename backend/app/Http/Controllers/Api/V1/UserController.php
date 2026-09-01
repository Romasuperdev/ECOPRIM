<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\RhUser;
use Illuminate\Http\Request;

/**
 * Utilisateurs — identité LUE dans RH_USER (dbmasterbacou, non modifiable ici),
 * affectations (société/établissements/rôles) gérées dans la base ECOPRIM.
 */
class UserController extends Controller
{
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

    private function ligne(RhUser $u): array
    {
        return [
            'id' => $u->Id, 'name' => trim("{$u->Prenom} {$u->Nom}") ?: $u->Login,
            'login' => $u->Login, 'matricule' => $u->Matricule, 'email' => $u->Email,
            'etab' => $u->Etab, 'actif' => ! $u->estSupprime(),
        ];
    }
}
