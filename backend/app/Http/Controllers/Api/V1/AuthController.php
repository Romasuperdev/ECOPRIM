<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RhUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /**
     * Établissement rattaché à un identifiant, pour l'afficher sur la page de connexion
     * avant la saisie du mot de passe.
     *
     * Endpoint PUBLIC, donc volontairement avare : il ne renvoie QUE le libellé de
     * l'établissement, jamais le nom du titulaire ni la moindre confirmation explicite
     * d'existence. Un identifiant inconnu, un compte désactivé ou un compte sans
     * établissement renvoient tous la même réponse (null). Il est limité en débit par
     * le middleware throttle pour freiner l'énumération d'identifiants.
     */
    public function etablissementDuCompte(Request $request)
    {
        $donnees = $request->validate(['identifiant' => ['required', 'string', 'max:100']]);
        $identifiant = trim($donnees['identifiant']);

        $query = RhUser::where(function ($q) use ($identifiant) {
            $q->where('Email', $identifiant)
                ->orWhere('Login', $identifiant)
                ->orWhere('Matricule', $identifiant);
        });

        if ($codeApp = config('ecoprim.code_app')) {
            $query->where('CodeApp', $codeApp);
        }

        $rhUser = $query->first();

        $code = ($rhUser && ! $rhUser->estSupprime()) ? trim((string) $rhUser->Etab) : '';

        return response()->json([
            'etablissement' => $code !== '' ? $this->libelleEtablissement($code) : null,
        ]);
    }

    /** Intitulé de l'établissement ; à défaut, son code brut. */
    private function libelleEtablissement(string $code): string
    {
        try {
            $intitule = DB::connection('economat')->table('BEtablissements')
                ->where('CodeEtablissement', $code)->value('Intitule');
            if ($intitule) {
                return $intitule;
            }
        } catch (\Throwable $e) {
            // Base indisponible : on retombe sur le code.
        }

        try {
            $intitule = DB::connection('ecoprim')->table('console_etablissements')
                ->where('code', $code)->value('intitule');
            if ($intitule) {
                return $intitule;
            }
        } catch (\Throwable $e) {
            // Surcouche absente : on retombe sur le code.
        }

        return $code;
    }
}
