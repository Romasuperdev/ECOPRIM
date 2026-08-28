<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Les comptes utilisateurs sont créés implicitement à la connexion (synchronisation
 * depuis RH_USER, voir AuthController::syncLocalUser) : ce contrôleur ne fait que
 * lister/consulter les comptes déjà connus localement, pas d'endpoint de création.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('roles')->withCount('affectations');

        $this->applyPerimetre($query, $request);

        if ($request->filled('q')) {
            $terme = $request->string('q');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$terme}%")->orWhere('email', 'like', "%{$terme}%"));
        }

        return $query->orderBy('name')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function show(Request $request, User $user)
    {
        $query = User::whereKey($user->id);
        $this->applyPerimetre($query, $request);

        return $query->firstOrFail()->load('roles', 'affectations.societe', 'affectations.etablissement', 'affectations.role');
    }

    /**
     * Un utilisateur n'a pas de société/établissement propre : sa visibilité pour un
     * Admin Société/Établissement dépend de ses affectations, jamais de tout le référentiel.
     */
    private function applyPerimetre($query, Request $request): void
    {
        $viewer = $request->user();

        if ($viewer->isSuperAdmin()) {
            return;
        }

        $societeIds = $viewer->allowedSocieteIds();
        $etablissementIds = $viewer->allowedEtablissementIds();

        if (! $societeIds && ! $etablissementIds) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('affectations', function ($q) use ($societeIds, $etablissementIds) {
            $q->where(function ($qq) use ($societeIds, $etablissementIds) {
                if ($societeIds) {
                    $qq->orWhereIn('societe_id', $societeIds)
                        ->orWhereHas('etablissement', fn ($eq) => $eq->whereIn('societe_id', $societeIds));
                }
                if ($etablissementIds) {
                    $qq->orWhereIn('etablissement_id', $etablissementIds);
                }
            });
        });
    }
}
