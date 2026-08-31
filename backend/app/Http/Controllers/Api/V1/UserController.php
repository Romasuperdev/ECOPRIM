<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RhUser;
use Illuminate\Http\Request;

/** Utilisateurs & accès — lecture seule (dbmasterbacou.RH_USER + rôles). */
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

        return array_merge($this->ligne($rh), [
            'roles' => $rh->getRoleNames(),
            'societes' => $rh->allowedSocieteIds(),
        ]);
    }

    private function ligne(RhUser $u): array
    {
        return [
            'id' => $u->Id,
            'name' => trim("{$u->Prenom} {$u->Nom}") ?: $u->Login,
            'login' => $u->Login,
            'matricule' => $u->Matricule,
            'email' => $u->Email,
            'etab' => $u->Etab,
            'actif' => ! $u->estSupprime(),
        ];
    }
}
