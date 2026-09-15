<?php

namespace App\Services;

use App\Models\Console\Affectation;
use App\Models\Console\AffectationEleve;
use App\Models\Console\Etablissement;
use App\Models\Console\Role;
use App\Models\RhUser;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Création automatique des accès Parent et Enseignant, au moment où la personne entre
 * dans l'école : à l'inscription d'un élève pour son parent, à la création d'une fiche
 * pour l'enseignant. Le compte vit dans dbmasterbacou.RH_USER comme tous les autres —
 * aucune table d'identités parallèle.
 *
 * Trois règles décidées avec l'utilisateur :
 *
 *   1. UN SEUL compte parent par élève : le père/tuteur s'il a un téléphone, sinon la
 *      mère. Renseigner les deux ne crée pas deux accès.
 *   2. Le TÉLÉPHONE identifie la personne. Un parent qui inscrit un deuxième enfant est
 *      reconnu par son numéro : on rattache l'enfant au compte existant au lieu d'en
 *      créer un doublon — c'est tout l'intérêt (« un parent, plusieurs élèves »).
 *   3. Le téléphone sert aussi d'identifiant de connexion, et le mot de passe est tiré au
 *      hasard puis renvoyé UNE fois dans la réponse, pour être lu à l'intéressé. Il n'est
 *      jamais stocké en clair : RH_USER n'en garde que le haché.
 *
 * Rien ici ne doit faire échouer l'acte principal : si la base console est injoignable ou
 * le référentiel incomplet, l'inscription de l'élève (ou la fiche enseignant) reste
 * enregistrée et le compte-rendu porte l'erreur, à l'écran, pour être retenté plus tard.
 */
class AccesAutomatique
{
    /** Sans 0/O ni 1/I/L : un mot de passe dicté à voix haute ou recopié à la main. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LONGUEUR_MOT_DE_PASSE = 8;

    public function __construct(
        private RhUserEcrivain $ecrivain,
        private ProfesseurEcrivain $professeurs,
    ) {}

    /**
     * Accès du parent d'un élève qu'on vient d'inscrire, et rattachement de cet enfant.
     *
     * @return array|null Compte-rendu affichable, ou null si aucun parent n'a de téléphone.
     */
    public function pourParent(array $donnees, string $matriculeEleve): ?array
    {
        $parent = $this->parentPrincipal($donnees);
        if (! $parent) {
            return null;
        }

        try {
            $compte = $this->compteEtAffectation(
                $parent['telephone'], $parent['nom'], $parent['prenom'], $parent['email'],
                RhUser::ROLE_PARENT, 'Parent'
            );

            // firstOrCreate : réinscrire le même enfant l'année suivante ne duplique rien.
            $rattachement = AffectationEleve::firstOrCreate([
                'affectation_id' => $compte['affectation']->id,
                'eleve_matricule' => $matriculeEleve,
            ]);

            return $this->compteRendu($compte, [
                'role' => 'Parent',
                'enfant_rattache' => $rattachement->wasRecentlyCreated,
            ]);
        } catch (Throwable $e) {
            return ['erreur' => "L'accès du parent n'a pas pu être créé : ".$e->getMessage()];
        }
    }

    /**
     * Accès d'un enseignant qu'on vient d'enregistrer.
     *
     * Le portail Enseignant retrouve ses classes par T_PROFESSEUR.LOGIN = RH_USER.Login
     * (voir PortailEnseignantController::professeur) : sans ce lien, l'enseignant se
     * connecterait sur un portail vide. On écrit donc aussi le login côté fiche.
     */
    public function pourEnseignant(array $donnees, int $codeProfesseur): ?array
    {
        $telephone = $this->premierTelephone([$donnees['cellulaire'] ?? null, $donnees['telephone'] ?? null]);
        if ($telephone === null) {
            return null;
        }

        try {
            $compte = $this->compteEtAffectation(
                $telephone, $donnees['nom'] ?? null, $donnees['prenom'] ?? null, $donnees['email'] ?? null,
                RhUser::ROLE_ENSEIGNANT, 'Enseignant'
            );

            $this->professeurs->definirLogin($codeProfesseur, $compte['login']);

            return $this->compteRendu($compte, ['role' => 'Enseignant']);
        } catch (Throwable $e) {
            return ['erreur' => "L'accès de l'enseignant n'a pas pu être créé : ".$e->getMessage()];
        }
    }

    /**
     * Le parent qui reçoit l'accès : le père/tuteur s'il a un téléphone, sinon la mère.
     * Un seul compte par élève — c'est la règle retenue.
     */
    private function parentPrincipal(array $d): ?array
    {
        foreach ([['pere_nom', 'pere_prenom', 'pere_telephone', 'pere_email'],
            ['mere_nom', 'mere_prenom', 'mere_telephone', 'mere_email']] as [$nom, $prenom, $tel, $email]) {
            $telephone = $this->premierTelephone([$d[$tel] ?? null]);
            if ($telephone !== null) {
                return [
                    'nom' => $d[$nom] ?? null, 'prenom' => $d[$prenom] ?? null,
                    'telephone' => $telephone, 'email' => $d[$email] ?? null,
                ];
            }
        }

        return null;
    }

    /** Premier numéro exploitable de la liste, normalisé ; null si aucun. */
    private function premierTelephone(array $candidats): ?string
    {
        foreach ($candidats as $brut) {
            $normalise = $this->normaliserTelephone($brut);
            if ($normalise !== null) {
                return $normalise;
            }
        }

        return null;
    }

    /**
     * Le numéro devient un identifiant de connexion : on retire tout ce qui n'est ni
     * chiffre ni « + » (espaces, points, tirets), pour que « 07 08 09 10 11 » et
     * « 07-08-09-10-11 » désignent bien la même personne au moment de la reconnaître.
     */
    private function normaliserTelephone(?string $brut): ?string
    {
        $propre = preg_replace('/[^0-9+]/', '', (string) $brut) ?? '';

        // Moins de 6 chiffres : une saisie partielle, pas un numéro — on n'en fait pas un compte.
        return strlen(preg_replace('/\D/', '', $propre) ?? '') >= 6 ? $propre : null;
    }

    /**
     * Retrouve le compte de cette personne, ou le crée, puis s'assure qu'elle a bien
     * l'affectation correspondant à son rôle dans l'établissement courant.
     *
     * @return array{utilisateur:RhUser, affectation:Affectation, login:string, mot_de_passe:?string, nouveau:bool}
     */
    private function compteEtAffectation(
        string $telephone, ?string $nom, ?string $prenom, ?string $email, string $codeRole, string $nomRole
    ): array {
        $perimetre = $this->perimetre();
        $existant = $this->trouverParTelephone($telephone);

        if ($existant) {
            $utilisateur = $existant;
            $motDePasse = null; // Compte déjà connu : on ne réinitialise rien au passage.
            $nouveau = false;
        } else {
            $motDePasse = $this->motDePasse();
            $id = $this->ecrivain->creer([
                'login' => $telephone,
                'mot_de_passe' => $motDePasse,
                'nom' => $nom ?: $telephone,
                'prenom' => $prenom,
                'email' => $email,
                'contact' => $telephone,
                'etab' => $perimetre['etablissement'],
            ]);
            $utilisateur = RhUser::findOrFail($id);
            $nouveau = true;
        }

        return [
            'utilisateur' => $utilisateur,
            'affectation' => $this->affectation($utilisateur, $codeRole, $nomRole, $perimetre),
            'login' => trim((string) $utilisateur->Login),
            'mot_de_passe' => $motDePasse,
            'nouveau' => $nouveau,
        ];
    }

    /**
     * Le numéro reconnaît la personne : soit il est déjà son identifiant (compte créé
     * ici), soit il figure dans ses coordonnées (compte créé à la main par un admin) —
     * dans les deux cas c'est elle, et on ne crée pas de doublon.
     */
    private function trouverParTelephone(string $telephone): ?RhUser
    {
        return RhUser::query()
            ->where(fn ($q) => $q->where('Login', $telephone)->orWhere('Contact', $telephone))
            ->orderBy('Id')
            ->first();
    }

    /** L'affectation de ce rôle dans l'établissement courant, créée si elle manque. */
    private function affectation(RhUser $utilisateur, string $codeRole, string $nomRole, array $perimetre): Affectation
    {
        // Le catalogue de rôles n'est semé que par `php artisan console:importer` : on ne
        // suppose pas qu'il l'a été, sinon la toute première inscription échouerait.
        $role = Role::firstOrCreate(['code' => $codeRole], ['nom' => $nomRole]);

        return Affectation::firstOrCreate([
            'rh_user_id' => $utilisateur->Id,
            'etablissement_code' => $perimetre['etablissement'],
            'role_id' => $role->id,
        ], [
            'societe_code' => $perimetre['societe'],
            'actif' => true,
        ]);
    }

    /** Établissement de travail (session, sinon rattachement du compte), et sa société. */
    private function perimetre(): array
    {
        $etablissement = null;
        try {
            $etablissement = Session::get('etablissement_code');
        } catch (Throwable $e) {
            $etablissement = null;
        }
        $etablissement = trim((string) ($etablissement ?: (auth()->user()->Etab ?? ''))) ?: null;

        $societe = null;
        if ($etablissement) {
            try {
                $societe = Etablissement::where('code', $etablissement)->value('societe_code');
            } catch (Throwable $e) {
                $societe = null;
            }
        }

        return ['etablissement' => $etablissement, 'societe' => $societe];
    }

    private function motDePasse(): string
    {
        $alphabet = self::ALPHABET;
        $motDePasse = '';
        for ($i = 0; $i < self::LONGUEUR_MOT_DE_PASSE; $i++) {
            $motDePasse .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $motDePasse;
    }

    /** Ce que l'écran affiche à la secrétaire, une seule fois. */
    private function compteRendu(array $compte, array $extra): array
    {
        $utilisateur = $compte['utilisateur'];

        return array_merge([
            'nouveau' => $compte['nouveau'],
            'login' => $compte['login'],
            // Renseigné seulement à la création : un compte existant garde le sien.
            'mot_de_passe' => $compte['mot_de_passe'],
            'nom' => trim(($utilisateur->Prenom ?? '').' '.($utilisateur->Nom ?? '')) ?: $compte['login'],
            // Un compte désactivé reste rattaché, mais son titulaire ne peut pas se
            // connecter : le dire plutôt que laisser croire que l'accès est ouvert.
            'desactive' => $utilisateur->estSupprime(),
        ], $extra);
    }
}
