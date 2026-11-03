<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RhUser;
use App\Support\PerimetreEtablissement;
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
        // Le champ « email » accepte indifféremment le nom d'utilisateur, l'email ou le
        // matricule : l'utilisateur saisit ce dont il se souvient.
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $rhUser = $this->trouverParIdentifiant($credentials['email']);

        if (! $rhUser || ! Hash::check($credentials['password'], $rhUser->MotDePasse)) {
            throw ValidationException::withMessages(['email' => ['Identifiants invalides.']]);
        }

        if ($rhUser->estSupprime()) {
            throw ValidationException::withMessages(['email' => ['Ce compte est désactivé.']]);
        }

        // Rôles illisibles (base console injoignable) : on ne sait pas si ce compte a
        // droit à l'application complète ou seulement à un portail restreint. Dans le
        // doute, pas de session — plutôt qu'une session où tout serait refusé.
        if ($rhUser->typePortail() === RhUser::PORTAIL_INDISPONIBLE) {
            abort(503, "Vos droits d'accès n'ont pas pu être vérifiés pour l'instant. Réessayez dans quelques minutes.");
        }

        // Un compte sans établissement n'entre ni dans l'application ni dans la console,
        // sauf le Super Admin.
        if (! $rhUser->estAffecteAUnEtablissement()) {
            abort(403, RhUser::MESSAGE_SANS_ETABLISSEMENT);
        }

        Auth::login($rhUser);
        $request->session()->regenerate();
        // Le contexte de travail appartient au compte, pas au navigateur : un compte qui se
        // connecte après un autre, sans déconnexion, ne doit pas hériter de l'établissement,
        // de la société ni de l'année que le précédent avait choisis.
        $request->session()->forget(['etablissement_code', 'etablissement_nom', 'console_societe', 'annee_travail']);
        PerimetreEtablissement::oublier();

        return response()->json($this->userPayload($rhUser));
    }

    /**
     * Retrouve un compte RH_USER à partir de ce que l'utilisateur a tapé : son nom
     * d'utilisateur (Login), son email ou son matricule.
     *
     * La comparaison est explicitement insensible à la casse et aux espaces de bord :
     * un email recopié depuis un mail arrive souvent avec une majuscule ou une espace
     * traînante. SQL Server l'ignorerait de lui-même avec une collation CI, mais on ne
     * dépend pas de la collation du serveur pour que la connexion fonctionne.
     *
     * Le nom de famille (Nom) n'est volontairement PAS un identifiant : il n'est pas
     * unique dans RH_USER, deux homonymes se connecteraient l'un sur le compte de l'autre.
     */
    private function trouverParIdentifiant(string $saisie): ?RhUser
    {
        $identifiant = mb_strtolower(trim($saisie));
        if ($identifiant === '') {
            return null;
        }

        $query = RhUser::where(function ($q) use ($identifiant) {
            foreach (['Email', 'Login', 'Matricule'] as $colonne) {
                $q->orWhereRaw("LOWER(LTRIM(RTRIM({$colonne}))) = ?", [$identifiant]);
            }
        });

        if ($codeApp = config('ecoprim.code_app')) {
            $query->where('CodeApp', $codeApp);
        }

        return $query->first();
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
            // Affiché dans l'en-tête de la console, comme dans parcauto.
            'login' => $rhUser->Login,
            'email' => $rhUser->Email,
            'roles' => $rhUser->getRoleNames(),
            // Les deux sortes d'administrateur de la console. Le front s'en sert pour
            // router et pour masquer la console générale, mais l'autorisation reste
            // décidée côté serveur : ces indicateurs ne sont qu'un affichage.
            'super_admin' => $rhUser->isSuperAdmin(),
            'admin_societe' => $rhUser->estAdminSociete(),
            'admin_etablissement' => $rhUser->estAdminEtablissement(),
            // La console (sociétés, établissements, comptes de la plateforme) est réservée
            // au Super Admin. Les administrateurs (Admin Société / Établissement) ont la
            // main sur toute l'APPLICATION, y compris Configuration administrative.
            'peut_console' => $rhUser->isSuperAdmin(),
            'administrateur' => $rhUser->peutAccederConsole(),
            // Ce que l'écran a le droit de proposer (App\Support\Permissions) : il masque
            // ce qui finirait en refus — la saisie des notes pour un administrateur, par
            // exemple. Affichage seulement, le serveur revérifie à chaque appel.
            'permissions' => $rhUser->permissions(),
            // 'staff' (application complète) ou 'enseignant'/'parent' (portail restreint) —
            // affichage seulement, l'accès réel est décidé par PortailMiddleware côté serveur.
            'type_portail' => $rhUser->typePortail(),
        ];
    }

    /**
     * Société et établissement rattachés à un identifiant, pour les afficher sur la page
     * de connexion avant la saisie du mot de passe : l'utilisateur voit à quoi il se
     * connecte, et un compte mal rattaché se repère avant même la première tentative.
     *
     * Endpoint PUBLIC, donc volontairement avare : il ne renvoie QUE des libellés de
     * rattachement, jamais le nom du titulaire, son rôle, ni la moindre confirmation
     * explicite d'existence. Un identifiant inconnu, un compte désactivé ou un compte sans
     * rattachement renvoient tous la même réponse (null, null). Il est limité en débit par
     * le middleware throttle pour freiner l'énumération d'identifiants.
     */
    public function etablissementDuCompte(Request $request)
    {
        $donnees = $request->validate(['identifiant' => ['required', 'string', 'max:100']]);

        $rhUser = $this->trouverParIdentifiant($donnees['identifiant']);

        $utilisable = $rhUser && ! $rhUser->estSupprime();

        // Super Admin : il travaille sur tous les établissements.
        if ($utilisable && $rhUser->isSuperAdmin()) {
            return response()->json(['etablissement' => '(Tous les établissements)', 'societe' => null]);
        }

        $code = $utilisable ? trim((string) $rhUser->Etab) : '';
        if ($utilisable && $code === '') {
            $code = $this->premierEtablissementAffecte($rhUser);
        }

        return response()->json([
            'etablissement' => $code !== '' ? $this->libelleEtablissement($code) : null,
            'societe' => $utilisable ? $this->libelleSociete($rhUser) : null,
        ]);
    }

    /**
     * Société de rattachement, lue dans les affectations de la console.
     *
     * Un Super Admin n'est rattaché à aucune société en particulier : null, comme un
     * compte sans rattachement. L'endpoint est public ; y écrire « Super Administrateur »
     * désignait à quiconque tapait un identifiant LE compte qui ouvre toute la suite —
     * la cible à privilégier pour un essai de mots de passe.
     */
    private function libelleSociete(RhUser $rhUser): ?string
    {
        if ($rhUser->isSuperAdmin()) {
            return null;
        }

        try {
            $code = DB::connection('ecoprim')->table('console_affectations')
                ->where('rh_user_id', $rhUser->Id)
                ->where('actif', true)
                ->whereNotNull('societe_code')
                ->orderBy('id')
                ->value('societe_code');
        } catch (\Throwable $e) {
            return null;
        }

        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        try {
            $nom = DB::connection('ecoprim')->table('console_societes')
                ->where('code', $code)->value('nom');
            if ($nom) {
                return $nom;
            }
        } catch (\Throwable $e) {
            // Surcouche absente : on tente la table partagée.
        }

        try {
            $nom = DB::connection('master')->table('US_SOCIETE')
                ->where('CODESOCIETE', $code)->value('NOMSOCIETE');
            if ($nom) {
                return trim((string) $nom);
            }
        } catch (\Throwable $e) {
            // dbmasterbacou injoignable : on retombe sur le code.
        }

        return $code;
    }

    /** Premier établissement des affectations actives (compte sans RH_USER.Etab). */
    private function premierEtablissementAffecte(RhUser $rhUser): string
    {
        try {
            return trim((string) DB::connection('ecoprim')->table('console_affectations')
                ->where('rh_user_id', $rhUser->Id)->where('actif', true)
                ->whereNotNull('etablissement_code')->where('etablissement_code', '<>', '')
                ->orderBy('id')->value('etablissement_code'));
        } catch (\Throwable $e) {
            return '';
        }
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
