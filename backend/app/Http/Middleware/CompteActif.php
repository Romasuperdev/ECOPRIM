<?php

namespace App\Http\Middleware;

use App\Models\RhUser;
use App\Support\PerimetreEtablissement;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Un compte désactivé perd l'accès à la requête suivante, pas à sa prochaine connexion.
 *
 * La connexion refuse déjà un compte désactivé (AuthController::login), mais une session
 * ouverte AVANT la désactivation restait valable jusqu'à son expiration : désactiver un
 * compte n'avait donc aucun effet immédiat. Ici, à chaque requête authentifiée, on relit
 * l'état du compte (RhUser::estSupprime) ; désactivé, il est déconnecté et reçoit un 401,
 * comme une session expirée — l'écran le renvoie alors à la page de connexion.
 *
 * Au passage, le périmètre établissement est oublié : il est mémorisé le temps d'UNE
 * requête, et ne doit pas survivre à la suivante (même processus PHP en test ou en
 * serveur persistant).
 */
class CompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        PerimetreEtablissement::oublier();

        $user = $request->user();

        if ($user instanceof RhUser && $this->desactive($user)) {
            $this->deconnecter($request);

            return response()->json([
                'message' => 'Ce compte est désactivé. Adressez-vous à votre administrateur.',
            ], 401);
        }

        // Compte dont on a retiré toutes les affectations : déconnecté à la requête suivante.
        if ($user instanceof RhUser && $this->sansEtablissement($user)) {
            $this->deconnecter($request);

            return response()->json(['message' => RhUser::MESSAGE_SANS_ETABLISSEMENT], 401);
        }

        return $next($request);
    }

    /** Lecture impossible : on ne déconnecte pas sur un incident passager. */
    private function sansEtablissement(RhUser $user): bool
    {
        try {
            $actuel = RhUser::query()->whereKey($user->getKey())->first() ?? $user;

            return ! $actuel->estAffecteAUnEtablissement();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * État relu en base, et non sur l'instance en mémoire : c'est la valeur ACTUELLE qui
     * compte. Compte introuvable (supprimé de RH_USER) = désactivé. Relecture impossible :
     * on s'en tient à l'état chargé par la garde au début de cette même requête, plutôt
     * que de déconnecter tout le monde sur un incident passager.
     */
    private function desactive(RhUser $user): bool
    {
        try {
            $actuel = RhUser::query()->whereKey($user->getKey())->first();
        } catch (Throwable $e) {
            return $user->estSupprime();
        }

        return ! $actuel || $actuel->estSupprime();
    }

    private function deconnecter(Request $request): void
    {
        try {
            Auth::guard('web')->logout();
        } catch (Throwable $e) {
            // Pas de session web (appel sans cookie) : rien à fermer.
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
