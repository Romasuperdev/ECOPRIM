<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\RhUser;
use App\Models\Console\Etablissement;
use App\Services\RhUserEcrivain;
use App\Support\PerimetreConsole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Utilisateurs — identité dans dbmasterbacou.RH_USER.
 * Création et modification autorisées ; JAMAIS de suppression : un compte retiré est
 * désactivé (Supprimer = 1). Les affectations (société/établissements/rôles) restent
 * gérées dans la base propre ECOPRIM.
 *
 * Cloisonnement : dès qu'une société est courante, la liste se limite aux comptes qui y
 * ont une affectation. Un Admin Société ne voit donc que les utilisateurs de sa société,
 * et ne peut pas en créer un qui lui serait invisible : l'établissement et le rôle sont
 * exigés à la création, l'affectation est posée dans le même geste.
 */
class UserController extends Controller
{
    public function __construct(private RhUserEcrivain $ecrivain) {}

    public function index(Request $request)
    {
        $users = RhUser::query()
            ->tap(fn ($q) => $this->cloisonner($q))
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
        $rh = $this->trouverDansPerimetre($user);
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
            // Affectation posée dans le même geste que la création. Obligatoire pour un
            // Admin Société : sans elle, le compte qu'il crée sortirait de sa vue.
            'etablissement_code' => [
                $creation && ! $this->estSuperAdmin() ? 'required' : 'nullable',
                'string',
                Rule::exists('ecoprim.console_etablissements', 'code'),
            ],
            'role_id' => [
                $creation && ! $this->estSuperAdmin() ? 'required' : 'nullable',
                Rule::exists('ecoprim.console_roles', 'id'),
            ],
        ];
    }

    private function estSuperAdmin(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    /** Restreint la liste aux comptes affectés dans la société courante. */
    private function cloisonner($query): void
    {
        $user = auth()->user();
        $courant = PerimetreConsole::codeCourant($user);

        if ($courant === null) {
            // Vue générale : réservée au Super Admin. Fail closed pour les autres.
            if (! $user || ! $user->isSuperAdmin()) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        // Un Admin Établissement (sans le niveau société) ne voit que les comptes affectés
        // dans son ou ses établissements, pas toute la société.
        if ($user && ! $user->peutAccederNiveauSociete()) {
            $query->whereIn('Id', $this->idsAffectes($courant, $user->etablissementsAdministres()));

            return;
        }

        $query->whereIn('Id', $this->idsAffectes($courant));
    }

    /** Identifiants RH_USER ayant une affectation dans cette société (et cet établissement, le cas échéant). */
    private function idsAffectes(string $societeCode, ?array $etablissementCodes = null): array
    {
        try {
            return Affectation::where('societe_code', $societeCode)
                ->when($etablissementCodes !== null, fn ($q) => $q->whereIn('etablissement_code', $etablissementCodes))
                ->pluck('rh_user_id')->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Charge un compte en refusant tout ce qui sort du périmètre. On répond 404 plutôt
     * que 403 : hors périmètre, l'existence même du compte n'a pas à être confirmée.
     */
    private function trouverDansPerimetre(string $user): RhUser
    {
        $query = RhUser::query();
        $this->cloisonner($query);

        return $query->where('Id', $user)->firstOrFail();
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));

        if ($this->ecrivain->loginExiste($data['login'])) {
            throw ValidationException::withMessages(['login' => ['Ce login est déjà utilisé.']]);
        }

        if (! empty($data['etablissement_code'])) {
            $etab = Etablissement::where('code', $data['etablissement_code'])->firstOrFail();
            PerimetreConsole::assertAutorisee($etab->societe_code);
        }

        $id = $this->ecrivain->creer($data);

        // L'affectation suit immédiatement la création, pour que le compte apparaisse
        // dans la liste de celui qui vient de le créer.
        if (! empty($data['etablissement_code']) && ! empty($data['role_id'])) {
            Affectation::create([
                'rh_user_id' => $id,
                'societe_code' => $etab->societe_code,
                'etablissement_code' => $etab->code,
                'role_id' => $data['role_id'],
                'actif' => true,
            ]);
        }

        return response()->json($this->ligne(RhUser::findOrFail($id)), 201);
    }

    public function update(Request $request, string $user)
    {
        $rh = $this->trouverDansPerimetre($user);
        $data = $request->validate($this->regles(false, (int) $rh->Id));

        if ($this->ecrivain->loginExiste($data['login'], (int) $rh->Id)) {
            throw ValidationException::withMessages(['login' => ['Ce login est déjà utilisé.']]);
        }

        $this->ecrivain->modifier((int) $rh->Id, $data);

        return response()->json($this->ligne(RhUser::findOrFail($rh->Id)));
    }

    public function activer(string $user)
    {
        $rh = $this->trouverDansPerimetre($user);
        $this->ecrivain->definirActif((int) $rh->Id, true);

        return response()->json($this->ligne(RhUser::findOrFail($rh->Id)));
    }

    /** Désactivation logique (Supprimer = 1) : le compte ne peut plus se connecter. */
    public function desactiver(string $user)
    {
        $rh = $this->trouverDansPerimetre($user);
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
