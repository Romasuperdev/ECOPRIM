<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\Console\AffectationEleve;
use App\Models\Console\Etablissement;
use App\Models\Console\Role;
use App\Models\RhUser;
use App\Support\ElevesEtablissement;
use App\Support\PerimetreConsole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Affectations : un rôle d'un utilisateur (RH_USER) dans un établissement.
 * Règles : un utilisateur ne peut être affecté qu'à des établissements de LA MÊME société ;
 * pas de doublon (même utilisateur, même établissement, même rôle). Un Admin Société ne
 * peut affecter que dans sa propre société, un Admin Établissement seulement dans le ou
 * les établissements qui lui sont propres — sans quoi l'un ou l'autre se donnerait un
 * accès hors de son périmètre.
 *
 * Rôle Parent : le portail restreint (voir PortailParentController) ne montre QUE les
 * enfants explicitement rattachés ici (console_affectation_eleves) — jamais un
 * rapprochement automatique par email/téléphone, trop fragile pour ce que ça expose.
 */
class AffectationController extends Controller
{
    /**
     * Toutes les affectations du périmètre, en une vue.
     *
     * Elles ne se consultaient jusqu'ici que compte par compte, depuis la fiche d'un
     * utilisateur : impossible de répondre à « qui travaille dans cet établissement ? »
     * sans ouvrir les comptes un à un. C'est pourtant la question qu'on se pose en
     * arrivant sur un établissement.
     *
     * Lecture seule à dessein. La création et le retrait restent sur la fiche de
     * l'utilisateur, où les règles s'appliquent déjà — une société unique par compte, pas
     * de doublon, pas de rôle Admin Société conféré par un Admin Établissement. Un second
     * chemin d'écriture aurait voulu dire une seconde copie de ces règles.
     */
    public function index(Request $request)
    {
        $requete = Affectation::with(['etablissement', 'role'])
            // Chacun ne voit que son périmètre : un Admin Établissement les affectations de
            // SES établissements, un Admin Société celles de sa société.
            ->tap(fn ($q) => PerimetreConsole::appliquer($q))
            ->when($request->filled('etablissement'),
                fn ($q) => $q->where('etablissement_code', $request->input('etablissement')))
            ->when($request->filled('actif'),
                fn ($q) => $q->where('actif', $request->boolean('actif')));

        // Un Admin Établissement ne dépasse pas ses propres établissements : la restriction
        // par société ne suffirait pas, elle lui ouvrirait ceux de ses voisins.
        $user = auth()->user();
        if (! $user->peutAccederNiveauSociete()) {
            $requete->whereIn('etablissement_code', $user->etablissementsAdministres());
        }

        $affectations = $requete->orderBy('etablissement_code')->orderBy('rh_user_id')->get();

        // Les comptes vivent dans RH_USER (lecture seule, autre base) : Eloquent ne sait pas
        // les joindre. Une seule requête pour tous plutôt qu'une par ligne.
        $utilisateurs = RhUser::whereIn('Id', $affectations->pluck('rh_user_id')->unique()->all())
            ->get()->keyBy('Id');

        return $affectations->map(function (Affectation $a) use ($utilisateurs) {
            $u = $utilisateurs->get($a->rh_user_id);

            return [
                'id' => $a->id,
                'rh_user_id' => $a->rh_user_id,
                'utilisateur' => $u ? trim(($u->Prenom ?? '').' '.($u->Nom ?? '')) ?: $u->Login : null,
                'login' => $u->Login ?? null,
                'email' => $u->Email ?? null,
                'etablissement_code' => $a->etablissement_code,
                'etablissement' => $a->etablissement?->intitule,
                'societe_code' => $a->societe_code,
                'role' => $a->role?->nom,
                'role_code' => $a->role?->code,
                'actif' => $a->actif,
                'date_debut' => $a->date_debut?->toDateString(),
                'date_fin' => $a->date_fin?->toDateString(),
                // Une affectation échue reste en base — c'est un historique — mais elle
                // n'ouvre plus rien. Le dire évite de la croire encore agissante.
                'echue' => $a->date_fin !== null && $a->date_fin->isPast(),
            ];
        })->values();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rh_user_id' => ['required', 'integer'],
            'etablissement_code' => ['required', 'string', Rule::exists('ecoprim.console_etablissements', 'code')],
            // Facultatif : sans rôle, le compte se connecte et fait les traitements, sans
            // toucher au paramétrage (Permissions::SANS_ROLE).
            'role_id' => ['nullable', Rule::exists('ecoprim.console_roles', 'id')],
            // Matricules d'élèves à rattacher — pertinent seulement pour le rôle Parent,
            // ignoré silencieusement pour tout autre rôle.
            'eleves' => ['nullable', 'array'],
            'eleves.*' => ['string', 'max:50', Rule::exists('economat.T_ETUDIANT', 'Matricule')],
        ]);

        $user = auth()->user();
        if (empty($data['role_id'])) {
            $data['role_id'] = Role::sansRole()->id;
        }

        // Seul un compte DÉJÀ dans le périmètre de l'appelant peut recevoir une
        // affectation de sa part. Accepter n'importe quel identifiant RH_USER — table
        // commune à toute la suite — permettait d'affecter un compte étranger, qui devenait
        // alors visible, puis de réinitialiser son mot de passe : une prise de contrôle
        // valable dans toutes les applications. Un compte nouveau entre dans le périmètre
        // par UserController::store, qui l'affecte dans le même geste. Le contrôle passe
        // AVANT la recherche du compte : hors périmètre, son existence n'a pas à être dite.
        $this->assertCompteDansPerimetre((int) $data['rh_user_id']);

        // L'utilisateur doit exister dans RH_USER (lecture seule).
        $cible = RhUser::find($data['rh_user_id']);
        abort_unless($cible, 422, "Utilisateur RH_USER introuvable.");
        $this->assertCibleGerable($cible);

        $etab = Etablissement::where('code', $data['etablissement_code'])->firstOrFail();

        PerimetreConsole::assertAutoriseeEtablissement($etab->code, $etab->societe_code);

        // Un Admin Établissement (sans le niveau société) ne peut pas conférer le rôle
        // Admin Société : il se donnerait un accès qui dépasse son propre périmètre. Le
        // rôle se reconnaît à son code, jamais à son nom (voir RhUser::ROLES_ADMINISTRATION).
        if (! $user->peutAccederNiveauSociete()) {
            $role = Role::findOrFail($data['role_id']);
            abort_if($role->estRoleAdminSociete(), 403, "Vous ne pouvez pas attribuer le rôle Admin Société.");
        }

        $role = Role::find($data['role_id']);
        if ($role && $role->code === RhUser::ROLE_PARENT) {
            ElevesEtablissement::exiger($data['eleves'] ?? [], $etab->code);
        }

        // Règle : un compte ne s'affecte qu'aux établissements de la société à laquelle il
        // est lié dans « Société - Utilisateurs ».
        \App\Support\SocietesDuCompte::exigerEtablissementDeSaSociete((int) $data['rh_user_id'], $etab->societe_code);

        // Règle : un utilisateur appartient à une seule société.
        $existante = Affectation::where('rh_user_id', $data['rh_user_id'])->first();
        if ($existante && $existante->societe_code !== $etab->societe_code) {
            throw ValidationException::withMessages([
                'etablissement_code' => ["Cet utilisateur est déjà rattaché à une autre société ({$existante->societe_code}) ; on ne peut l'affecter qu'à des établissements de cette société."],
            ]);
        }

        // Anti-doublon (utilisateur + établissement + rôle).
        $doublon = Affectation::where('rh_user_id', $data['rh_user_id'])
            ->where('etablissement_code', $data['etablissement_code'])
            ->where('role_id', $data['role_id'])->exists();
        if ($doublon) {
            throw ValidationException::withMessages(['role_id' => [
                Role::find($data['role_id'])?->estSansRole()
                    ? 'Cet utilisateur est déjà affecté à cet établissement.'
                    : 'Cet utilisateur a déjà ce rôle dans cet établissement.',
            ]]);
        }

        $affectation = Affectation::create([
            'rh_user_id' => $data['rh_user_id'],
            'societe_code' => $etab->societe_code,
            'etablissement_code' => $data['etablissement_code'],
            'role_id' => $data['role_id'],
        ]);

        if ($role && $role->code === RhUser::ROLE_PARENT) {
            $this->synchroniserEnfants($affectation, $data['eleves'] ?? []);
        }

        return response()->json($affectation->load(['etablissement', 'role', 'eleves']), 201);
    }

    public function destroy(Affectation $affectation)
    {
        PerimetreConsole::assertAutoriseeEtablissement($affectation->etablissement_code, $affectation->societe_code);
        // Retirer l'affectation d'un administrateur plus élevé (un Admin Société présent
        // dans l'établissement) n'est pas du ressort d'un Admin Établissement.
        if ($cible = RhUser::find($affectation->rh_user_id)) {
            $this->assertCibleGerable($cible);
        }
        $affectation->delete();

        return response()->noContent();
    }

    /**
     * Remplace la liste des enfants rattachés à une affectation « Parent » — la fiche
     * utilisateur envoie la liste complète voulue, pas une addition/un retrait unitaire.
     */
    public function definirEnfants(Request $request, Affectation $affectation)
    {
        PerimetreConsole::assertAutoriseeEtablissement($affectation->etablissement_code, $affectation->societe_code);

        $role = $affectation->role;
        abort_unless($role && $role->code === RhUser::ROLE_PARENT, 422,
            "Seule une affectation du rôle Parent peut être rattachée à des élèves.");

        if ($cible = RhUser::find($affectation->rh_user_id)) {
            $this->assertCibleGerable($cible);
        }

        $data = $request->validate([
            'eleves' => ['present', 'array'],
            'eleves.*' => ['string', 'max:50', Rule::exists('economat.T_ETUDIANT', 'Matricule')],
        ]);

        // Un matricule existant ne suffit pas : l'élève doit être, cette année, dans une
        // classe de l'établissement de l'affectation.
        ElevesEtablissement::exiger($data['eleves'], $affectation->etablissement_code);

        $this->synchroniserEnfants($affectation, $data['eleves']);

        return response()->json($affectation->load(['etablissement', 'role', 'eleves']));
    }

    /**
     * Le compte a-t-il déjà une affectation dans le périmètre de l'appelant ? Le Super
     * Admin n'est pas borné ; un Admin Société l'est à ses sociétés, un Admin
     * Établissement à ses établissements. Même définition que la liste des utilisateurs
     * (UserController::cloisonner) : une affectation inactive ou échue y compte, elle
     * rend déjà le compte visible.
     */
    private function assertCompteDansPerimetre(int $rhUserId): void
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            return;
        }

        $societes = $user->societesAdministrees();
        $etablissements = $user->etablissementsAdministres();

        $dedans = ($societes !== [] || $etablissements !== [])
            && Affectation::where('rh_user_id', $rhUserId)
                ->where(fn ($q) => $q
                    ->when($societes !== [], fn ($w) => $w->orWhereIn('societe_code', $societes))
                    ->when($etablissements !== [], fn ($w) => $w->orWhereIn('etablissement_code', $etablissements)))
                ->exists();

        abort_unless($dedans, 403,
            "Ce compte n'est pas dans votre périmètre : seul un compte déjà affecté chez vous, "
            ."ou créé depuis cet écran, peut recevoir une affectation. Adressez-vous au Super Administrateur.");
    }

    /** Même règle que UserController : jamais un compte d'un niveau égal ou supérieur. */
    private function assertCibleGerable(RhUser $cible): void
    {
        abort_unless(auth()->user()->peutGererCompte($cible), 403,
            "Ce compte a un niveau d'administration égal ou supérieur au vôtre : vous ne pouvez pas le gérer.");
    }

    private function synchroniserEnfants(Affectation $affectation, array $matricules): void
    {
        $matricules = collect($matricules)->map(fn ($m) => trim((string) $m))->filter()->unique()->values();

        $affectation->eleves()->whereNotIn('eleve_matricule', $matricules)->delete();

        foreach ($matricules as $matricule) {
            AffectationEleve::firstOrCreate([
                'affectation_id' => $affectation->id,
                'eleve_matricule' => $matricule,
            ]);
        }
    }
}
