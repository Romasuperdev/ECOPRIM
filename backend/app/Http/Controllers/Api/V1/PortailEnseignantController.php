<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Devoir;
use App\Models\Eleve;
use App\Models\Evaluation;
use App\Models\Evenement;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Portail Enseignant : un compte RH_USER affecté du SEUL rôle « Enseignant » (voir
 * RhUser::typePortail(), appliqué par PortailMiddleware) n'a accès qu'à SES classes,
 * jamais à l'application complète.
 *
 * Les classes d'un enseignant se déduisent de T_PROFESSEUR.LOGIN = RH_USER.Login, puis
 * de T_CORPROFCLASSE — exactement comme l'emploi du temps en déduit déjà l'enseignant
 * d'un créneau. Les actions déléguent aux contrôleurs existants (CahierTextesController,
 * AbsenceController, EmploiDuTempsController) après vérification que la classe visée
 * est bien la sienne : pas de logique métier dupliquée, seulement la porte d'entrée.
 */
class PortailEnseignantController extends Controller
{
    public function __construct(
        private CahierTextesController $cahier,
        private AbsenceController $absences,
        private EmploiDuTempsController $emploi,
        private EleveController $eleves,
        private SaisieNoteController $notes,
    ) {}

    private function professeur(): ?object
    {
        $login = trim((string) auth()->user()->Login);
        if ($login === '') {
            return null;
        }

        try {
            return DB::connection('economat')->table('T_PROFESSEUR')
                ->whereRaw('LOWER(LTRIM(RTRIM(LOGIN))) = ?', [mb_strtolower($login)])
                ->first();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return string[] Codes des classes réellement enseignées, pour l'année de travail. */
    private function mesClasses(): array
    {
        $prof = $this->professeur();
        if (! $prof) {
            return [];
        }

        try {
            return DB::connection('economat')->table('T_CORPROFCLASSE')
                ->where('CodeProfesseur', $prof->Code)
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->pluck('CodeClasse')
                ->map(fn ($c) => trim((string) $c))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function assertClasseAutorisee(?string $classe): void
    {
        if (! $classe || ! in_array($classe, $this->mesClasses(), true)) {
            throw new HttpException(403, "Cette classe n'est pas parmi celles que vous enseignez.");
        }
    }

    /** Mes classes et matières, pour l'année de travail : l'accueil du portail. */
    public function classes()
    {
        $prof = $this->professeur();
        $annee = ContexteScolaire::annee();

        if (! $prof) {
            return ['annee' => $annee, 'classes' => []];
        }

        try {
            $lignes = DB::connection('economat')->table('T_CORPROFCLASSE')
                ->where('CodeProfesseur', $prof->Code)
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->get();
        } catch (Throwable $e) {
            $lignes = collect();
        }

        return [
            'annee' => $annee,
            'classes' => $lignes->map(fn ($l) => [
                'classe' => trim((string) $l->CodeClasse),
                'classe_libelle' => $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $l->CodeClasse),
                'matiere' => trim((string) $l->CodeMatiere),
                'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $l->CodeMatiere),
                'principale' => (bool) ($l->Principale ?? false),
                // Affiché sur la carte de la classe : un enseignant sait de combien
                // d'élèves il parle avant même d'ouvrir la liste.
                'effectif' => $this->effectif(trim((string) $l->CodeClasse)),
            ])->values(),
        ];
    }

    private function libelle(string $table, string $cle, string $colonne, ?string $valeur): ?string
    {
        if (! $valeur) {
            return null;
        }
        try {
            return DB::connection('economat')->table($table)->where($cle, $valeur)->value($colonne) ?: $valeur;
        } catch (Throwable $e) {
            return $valeur;
        }
    }

    /** Nombre d'élèves d'une classe pour l'année de travail. Null si illisible. */
    private function effectif(string $classe): ?int
    {
        if ($classe === '') {
            return null;
        }

        try {
            $q = DB::connection('economat')->table('T_ETUDIANT')->where('CodeClasse', $classe);
            ContexteScolaire::appliquer($q, 'AnneeAcad');

            return (int) $q->count();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Effectif d'une de mes classes. */
    public function eleves(Request $request, string $classe)
    {
        $this->assertClasseAutorisee($classe);
        $request->query->set('classe_code', $classe);

        return $this->eleves->index($request);
    }

    // --- Cahier de textes : mes classes seulement ---

    public function cahierReferentiels(Request $request)
    {
        $classe = $request->query('classe');
        if ($classe) {
            $this->assertClasseAutorisee($classe);
        }

        return $this->cahier->referentiels($request);
    }

    public function cahierIndex(Request $request)
    {
        $this->assertClasseAutorisee($request->query('classe'));

        return $this->cahier->index($request);
    }

    public function cahierStore(Request $request)
    {
        $this->assertClasseAutorisee($request->input('classe'));

        return $this->cahier->store($request);
    }

    public function cahierUpdate(Request $request, int $entete)
    {
        $this->assertClasseAutorisee($this->classeDuCahier($entete));

        return $this->cahier->update($request, $entete);
    }

    public function cahierDestroy(int $entete)
    {
        $this->assertClasseAutorisee($this->classeDuCahier($entete));

        return $this->cahier->destroy($entete);
    }

    public function cahierLigne(Request $request, int $entete)
    {
        $this->assertClasseAutorisee($this->classeDuCahier($entete));

        return $this->cahier->enregistrerLigne($request, $entete);
    }

    public function cahierLigneSupprimer(int $entete, string $matiere)
    {
        $this->assertClasseAutorisee($this->classeDuCahier($entete));

        return $this->cahier->supprimerLigne($entete, $matiere);
    }

    private function classeDuCahier(int $entete): ?string
    {
        try {
            return DB::connection('economat')->table('T_ENTETE_JOURNAL')
                ->where('CodeEntete', $entete)->value('CodeClasse');
        } catch (Throwable $e) {
            return null;
        }
    }

    // --- Emploi du temps : consultation de mes classes seulement ---

    public function emploiReferentiels()
    {
        return $this->emploi->referentiels();
    }

    public function emploiIndex(Request $request)
    {
        $this->assertClasseAutorisee($request->query('classe'));

        return $this->emploi->index($request);
    }

    // --- Absences : saisie sur mes classes seulement ---

    public function absencesIndex(Request $request)
    {
        $data = $request->validate(['classe_code' => ['required', 'string', 'max:50']]);
        $this->assertClasseAutorisee($data['classe_code']);

        return $this->absences->index($request);
    }

    public function absencesStore(Request $request)
    {
        $this->assertClasseAutorisee($this->classeDeLEleve($request->input('matricule')));

        return $this->absences->store($request);
    }

    public function absencesUpdate(Request $request, int $absence)
    {
        $this->assertClasseAutorisee($this->classeDeLAbsence($absence));

        // Modifier l'élève pourrait viser une classe hors périmètre : on revérifie sur la
        // NOUVELLE valeur, sans quoi ce champ serait une porte de sortie du périmètre.
        if ($request->filled('matricule')) {
            $this->assertClasseAutorisee($this->classeDeLEleve($request->input('matricule')));
        }

        return $this->absences->update($request, $absence);
    }

    public function absencesDestroy(int $absence)
    {
        $this->assertClasseAutorisee($this->classeDeLAbsence($absence));

        return $this->absences->destroy($absence);
    }

    private function classeDeLAbsence(int $id): ?string
    {
        try {
            return DB::connection('economat')->table('T_ABSENCEELEVE')->where('Code', $id)->value('CodeClasse');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function classeDeLEleve(?string $matricule): ?string
    {
        if (! $matricule) {
            return null;
        }

        $eleve = Eleve::where('Matricule', $matricule)
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->first();

        return $eleve ? trim((string) $eleve->getRawOriginal('CodeClasse')) : null;
    }

    // --- Saisie des notes : mes classes ET mes matières seulement ---

    /**
     * La saisie des notes appartient à l'enseignant qui a fait le cours : c'est ici
     * qu'elle vit, pas dans l'application du personnel (où un administrateur se la verrait
     * refuser — voir Permissions::INTERDITES_AUX_ADMINISTRATEURS).
     *
     * Le contrôle est plus fin qu'ailleurs dans ce portail : enseigner une classe ne donne
     * pas le droit d'en noter toutes les matières. On vérifie donc le COUPLE
     * (classe, matière), tel qu'il est affecté dans T_CORPROFCLASSE.
     */
    private function assertMatiereAutorisee(?string $classe, ?string $matiere): void
    {
        $prof = $this->professeur();
        $autorise = false;

        if ($prof && $classe && $matiere) {
            try {
                $autorise = DB::connection('economat')->table('T_CORPROFCLASSE')
                    ->where('CodeProfesseur', $prof->Code)
                    ->where('CodeClasse', $classe)
                    ->where('CodeMatiere', $matiere)
                    ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                    ->exists();
            } catch (Throwable $e) {
                $autorise = false;
            }
        }

        if (! $autorise) {
            throw new HttpException(403, "Vous n'enseignez pas cette matière dans cette classe.");
        }
    }

    /** Ce que le serveur a reconnu des tables de notes : la saisie s'ouvre ou s'explique. */
    public function notesStructure()
    {
        return $this->notes->structure();
    }

    /** La feuille de notes d'une de mes matières, élèves de la classe compris. */
    public function notesFeuille(Request $request)
    {
        $this->assertMatiereAutorisee($request->query('classe'), $request->query('matiere'));

        return $this->notes->feuille($request);
    }

    /** Enregistre la feuille : mêmes garde-fous métier que côté personnel (barème, absents...). */
    public function notesEnregistrer(Request $request)
    {
        $this->assertMatiereAutorisee($request->input('classe'), $request->input('matiere'));

        return $this->notes->enregistrer($request);
    }

    /** Les matières que j'enseigne dans cette classe — ce que la feuille propose de noter. */
    public function mesMatieres(string $classe)
    {
        $this->assertClasseAutorisee($classe);
        $prof = $this->professeur();

        try {
            $lignes = DB::connection('economat')->table('T_CORPROFCLASSE')
                ->where('CodeProfesseur', $prof->Code)
                ->where('CodeClasse', $classe)
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->get();
        } catch (Throwable $e) {
            $lignes = collect();
        }

        return $lignes->map(fn ($l) => [
            'code' => trim((string) $l->CodeMatiere),
            'libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $l->CodeMatiere),
        ])->unique('code')->values();
    }

    /**
     * Mes créneaux de la semaine, toutes classes confondues — la trame hebdomadaire ne
     * porte pas de date calendaire (jour = Lundi..Vendredi, pas un jour précis), donc
     * « prochains cours » se lit comme « cette semaine, dans l'ordre », pas comme les
     * tout prochains dans le temps réel.
     */
    public function prochainsCours()
    {
        $prof = $this->professeur();
        if (! $prof) {
            return [];
        }

        try {
            $creneaux = DB::connection('economat')->table('T_EMPLOIDUTEMPS as e')
                ->join('T_CORPROFCLASSE as c', function ($j) {
                    $j->on('c.CodeClasse', '=', 'e.CODECLASSE')->on('c.CodeMatiere', '=', 'e.CODEMATIERE');
                })
                ->where('c.CodeProfesseur', $prof->Code)
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'e.ANNEE'))
                ->select('e.CODEJOUR', 'e.CODEHEURE', 'e.CODECLASSE', 'e.CODEMATIERE', 'e.CODESALLE')
                ->get();
        } catch (Throwable $e) {
            return [];
        }

        return $creneaux->map(fn ($c) => [
            'jour' => (int) $c->CODEJOUR,
            'jour_libelle' => $this->libelle('T_EMPJOUR', 'Code', 'Libelle', (string) $c->CODEJOUR),
            'heure' => (int) $c->CODEHEURE,
            'heure_libelle' => $this->libelleHoraire($c->CODEHEURE),
            'classe' => $c->CODECLASSE,
            'classe_libelle' => $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $c->CODECLASSE),
            'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $c->CODEMATIERE),
            'salle_libelle' => $c->CODESALLE ? $this->libelle('T_SALLESCLASSE', 'CODESALLE', 'LIBELLESALLE', $c->CODESALLE) : null,
        ])
            ->sortBy([['jour', 'asc'], ['heure', 'asc']])
            ->values();
    }

    private function libelleHoraire($code): ?string
    {
        if (! $code) {
            return null;
        }
        try {
            $h = DB::connection('economat')->table('T_HORAIRE')->where('COD_HORAIRE', $code)->first();
        } catch (Throwable $e) {
            return null;
        }

        return $h ? trim(($h->HEUR_DEBUT ?? '').' - '.($h->HEUR_FIN ?? '')) : null;
    }

    // --- Devoirs, évaluations et calendrier : consultation sur mes classes seulement ---

    /** Devoirs donnés à mes classes, ou à une seule si `classe` est précisé. */
    public function devoirs(Request $request)
    {
        $classe = $request->query('classe');
        if ($classe) {
            $this->assertClasseAutorisee($classe);
        }

        return Devoir::query()
            ->where('annee', ContexteScolaire::annee())
            ->whereIn('classe_code', $classe ? [$classe] : $this->mesClasses())
            ->orderBy('date_remise')
            ->get()
            ->map(fn (Devoir $d) => [
                'id' => $d->id,
                'titre' => $d->titre,
                'consigne' => $d->consigne,
                'classe' => $d->classe_code,
                'classe_libelle' => $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $d->classe_code),
                'matiere' => $d->matiere_code,
                'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $d->matiere_code),
                'date_remise' => optional($d->date_remise)->format('Y-m-d'),
            ])
            ->values();
    }

    /** Évaluations planifiées sur mes classes, avant toute note. */
    public function evaluationsPlanifiees(Request $request)
    {
        $classe = $request->query('classe');
        if ($classe) {
            $this->assertClasseAutorisee($classe);
        }

        return Evaluation::query()
            ->where('annee', ContexteScolaire::annee())
            ->whereIn('classe_code', $classe ? [$classe] : $this->mesClasses())
            ->orderBy('date')
            ->get()
            ->map(fn (Evaluation $e) => [
                'id' => $e->id,
                'titre' => $e->titre,
                'classe' => $e->classe_code,
                'classe_libelle' => $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $e->classe_code),
                'matiere' => $e->matiere_code,
                'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $e->matiere_code),
                'type' => $e->type,
                'date' => optional($e->date)->format('Y-m-d'),
                'heure_debut' => $e->heure_debut,
                'heure_fin' => $e->heure_fin,
            ])
            ->values();
    }

    /** Calendrier scolaire : événements de mes classes, et ceux de tout l'établissement. */
    public function evenements()
    {
        return Evenement::query()
            ->where('annee', ContexteScolaire::annee())
            ->where(fn ($q) => $q->whereNull('classe_code')->orWhereIn('classe_code', $this->mesClasses()))
            ->orderBy('date_debut')
            ->get()
            ->map(fn (Evenement $e) => [
                'id' => $e->id,
                'titre' => $e->titre,
                'type' => $e->type,
                'description' => $e->description,
                'date_debut' => optional($e->date_debut)->format('Y-m-d'),
                'date_fin' => optional($e->date_fin)->format('Y-m-d'),
                'lieu' => $e->lieu,
                'classe' => $e->classe_code,
                'classe_libelle' => $e->classe_code
                    ? $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $e->classe_code)
                    : null,
            ])
            ->values();
    }
}
