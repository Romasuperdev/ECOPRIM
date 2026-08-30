<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RhUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Le champ « email » du formulaire accepte en réalité un identifiant : Email,
        // Login ou Matricule (dbmasterbacou.RH_USER gère ainsi les différents logins).
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifiant = trim($credentials['email']);

        $query = RhUser::where(function ($q) use ($identifiant) {
            $q->where('Email', $identifiant)
                ->orWhere('Login', $identifiant)
                ->orWhere('Matricule', $identifiant);
        });

        // RH_USER héberge les logins de plusieurs applications : on peut restreindre la
        // connexion aux comptes d'ECOPRIM via CodeApp. Désactivé tant que ECOPRIM_CODE_APP
        // n'est pas renseigné (sinon on bloquerait tout le monde).
        if ($codeApp = config('ecoprim.code_app')) {
            $query->where('CodeApp', $codeApp);
        }

        $rhUser = $query->first();

        if (! $rhUser || ! Hash::check($credentials['password'], $rhUser->MotDePasse)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides.'],
            ]);
        }

        // Compte marqué supprimé / désactivé dans RH_USER : accès refusé même si le mot de
        // passe est correct.
        if ($this->estSupprime($rhUser)) {
            throw ValidationException::withMessages([
                'email' => ['Ce compte est désactivé.'],
            ]);
        }

        $user = $this->syncLocalUser($rhUser);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json($this->userPayload($user));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * Un compte RH_USER est considéré supprimé/désactivé si la colonne `Supprimer`
     * vaut une valeur vraie (1, "1", true). Null/0/"" = actif.
     */
    private function estSupprime(RhUser $rhUser): bool
    {
        return filter_var($rhUser->Supprimer, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * RH_USER (dbmasterbacou) est la source de vérité des identifiants.
     * On maintient une copie locale minimale dans ecoprim.users pour que les
     * rôles/permissions (spatie) et les FK internes (enseignants.user_id, ...)
     * restent sur une connexion unique.
     */
    private function syncLocalUser(RhUser $rhUser): User
    {
        // Clé locale : l'email s'il existe, sinon le login (certains comptes RH_USER
        // n'ont pas d'email mais se connectent par identifiant).
        $emailLocal = $rhUser->Email ?: $rhUser->Login;

        $isNew = ! User::where('email', $emailLocal)->exists();

        $user = User::updateOrCreate(
            ['email' => $emailLocal],
            [
                'name' => trim("{$rhUser->Prenom} {$rhUser->Nom}") ?: $rhUser->Login,
                'password' => $rhUser->MotDePasse, // déjà un hash bcrypt valide
            ]
        );

        if ($isNew && $rhUser->SuperAdmin) {
            $user->assignRole('Super Admin');
        }

        return $user;
    }

    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ];
    }
}
