<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RhUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authentification 100 % lecture seule : les identifiants sont vérifiés contre
 * dbmasterbacou.RH_USER, aucun compte local n'est créé. La session est portée par un
 * cookie (SESSION_DRIVER=cookie), les rôles/périmètre sont lus dans dbmasterbacou.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Le champ « email » accepte un identifiant : Email, Login ou Matricule.
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

        if ($codeApp = config('ecoprim.code_app')) {
            $query->where('CodeApp', $codeApp);
        }

        $rhUser = $query->first();

        if (! $rhUser || ! Hash::check($credentials['password'], $rhUser->MotDePasse)) {
            throw ValidationException::withMessages(['email' => ['Identifiants invalides.']]);
        }

        if ($rhUser->estSupprime()) {
            throw ValidationException::withMessages(['email' => ['Ce compte est désactivé.']]);
        }

        Auth::login($rhUser);
        $request->session()->regenerate();

        return response()->json($this->userPayload($rhUser));
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

    private function userPayload(RhUser $rhUser): array
    {
        return [
            'id' => $rhUser->Id,
            'name' => trim("{$rhUser->Prenom} {$rhUser->Nom}") ?: $rhUser->Login,
            'email' => $rhUser->Email,
            'roles' => $rhUser->getRoleNames(),
        ];
    }
}
