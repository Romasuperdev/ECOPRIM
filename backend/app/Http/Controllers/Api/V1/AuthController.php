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
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $rhUser = RhUser::where('Email', $credentials['email'])->first();

        if (! $rhUser || ! Hash::check($credentials['password'], $rhUser->MotDePasse)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides.'],
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
     * RH_USER (dbmasterbacou) est la source de vérité des identifiants.
     * On maintient une copie locale minimale dans ecoprim.users pour que les
     * rôles/permissions (spatie) et les FK internes (enseignants.user_id, ...)
     * restent sur une connexion unique.
     */
    private function syncLocalUser(RhUser $rhUser): User
    {
        $isNew = ! User::where('email', $rhUser->Email)->exists();

        $user = User::updateOrCreate(
            ['email' => $rhUser->Email],
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
