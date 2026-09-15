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
 * Portail Parent : un compte RH_USER affecté du SEUL rôle « Parent » (voir
 * RhUser::typePortail(), appliqué par PortailMiddleware) n'a accès qu'à SES enfants,
 * explicitement rattachés par l'administrateur (console_affectation_eleves) — jamais à
 * l'application complète, ni au reste d'une classe.
 *
 * Tout est en LECTURE. Le classement de moyennes() ne renvoie jamais que la ligne de cet
 * enfant plus des agrégats (rang, effectif) : jamais le détail des autres élèves de sa
 * classe, contrairement à RapportController::moyennesClasse destiné au personnel.
 */
class PortailParentController extends Controller
{
    public function __construct(
        private CahierTextesController $cahier,
        private RapportController $rapports,
        private BulletinController $bulletin,
        private AbsenceController $absences,
        private EmploiDuTempsController $emploi,
        private ImpressionController $impressions,
    ) {}

    private function mesEnfants(): array
    {
        return auth()->user()->enfantsMatricules();
    }

    private function assertEnfantAutorise(string $matricule): void
    {
        if (! in_array($matricule, $this->mesEnfants(), true)) {
            throw new HttpException(403, "Cet élève ne fait pas partie de vos enfants rattachés.");
        }
    }

    private function trouverEnfant(string $matricule): Eleve
    {
        $eleve = Eleve::where('Matricule', $matricule)
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->first();
        abort_unless($eleve, 404, "Cet élève n'est pas inscrit pour l'année en cours.");

        return $eleve;
    }

    /** Mes enfants rattachés, pour l'année de travail : l'accueil du portail. */
    public function enfants()
    {
        $matricules = $this->mesEnfants();
        $annee = ContexteScolaire::annee();

        if ($matricules === []) {
            return ['annee' => $annee, 'enfants' => []];
        }

        $eleves = Eleve::whereIn('Matricule', $matricules)
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->get();

        return [
            'annee' => $annee,
            'enfants' => $eleves->map(fn ($e) => [
                'matricule' => $e->matricule,
                'nom' => $e->nom,
                'prenom' => $e->prenom,
                'classe' => $e->classe_code,
                'classe_libelle' => $e->classe?->nom,
                'enseignant_titulaire' => $this->titulaire($e->classe_code, $e->annee),
                'photo' => $e->photo,
            ])->values(),
        ];
    }

    public function enfant(string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule)->load('classe');

        return array_merge($eleve->toArray(), [
            'enseignant_titulaire' => $this->titulaire($eleve->classe_code, $eleve->annee),
        ]);
    }

    /** Nom de l'enseignant titulaire (T_CORPROFCLASSE.Principale) d'une classe. */
    private function titulaire(?string $classe, ?string $annee): ?string
    {
        if (! $classe) {
            return null;
        }

        try {
            $p = DB::connection('economat')->table('T_CORPROFCLASSE as c')
                ->join('T_PROFESSEUR as p', 'p.Code', '=', 'c.CodeProfesseur')
                ->where('c.CodeClasse', $classe)
                ->where('c.Principale', true)
                ->when($annee, fn ($q) => $q->where('c.ANNEE', $annee))
                ->first(['p.NomProfesseur', 'p.PrenomProfesseur']);
        } catch (Throwable $e) {
            return null;
        }

        $nom = $p ? trim(($p->PrenomProfesseur ?? '').' '.($p->NomProfesseur ?? '')) : '';

        return $nom !== '' ? $nom : null;
    }

    public function absences(Request $request, string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $request->query->set('matricule', $matricule);

        return $this->absences->index($request);
    }

    /** Le cahier de textes de la CLASSE de l'enfant — pas une donnée personnelle. */
    public function cahierTextes(Request $request, string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);
        $request->query->set('classe', $eleve->classe_code);

        return $this->cahier->index($request);
    }

    public function moyennes(Request $request, string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);
        $session = $request->input('session');

        $classement = $this->rapports->moyennes($eleve->classe_code, $session);
        $ligne = $classement->firstWhere('matricule', $matricule);

        return [
            'annee' => ContexteScolaire::annee(),
            'session' => $session,
            'moyenne_generale' => $ligne['moyenne'] ?? null,
            'rang' => $ligne['rang'] ?? null,
            'effectif' => $classement->count(),
            'par_matiere' => $this->rapports->notesParMatiere($matricule, $session),
        ];
    }

    public function bulletin(Request $request, string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);

        return $this->bulletin->show($request, $eleve);
    }

    /** Certificat de scolarité de l'enfant — même document que celui imprimable par le personnel. */
    public function certificatScolarite(Request $request, string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);

        return $this->impressions->certificatScolarite($request, $eleve);
    }

    /** Attestation de fréquentation de l'enfant. */
    public function attestationFrequentation(Request $request, string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);

        return $this->impressions->attestationFrequentation($request, $eleve);
    }

    /** Jours, heures et salles : la trame de la grille (référentiel commun, non sensible). */
    public function emploiReferentiels()
    {
        return $this->emploi->referentiels();
    }

    /** Emploi du temps de la classe de l'enfant. */
    public function emploiDuTemps(Request $request, string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);
        $request->query->set('classe', $eleve->classe_code);

        return $this->emploi->index($request);
    }

    /** Devoirs donnés à la classe de l'enfant. */
    public function devoirs(string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);

        return Devoir::query()
            ->where('annee', $eleve->annee)
            ->where('classe_code', $eleve->classe_code)
            ->orderBy('date_remise')
            ->get()
            ->map(fn (Devoir $d) => [
                'id' => $d->id,
                'titre' => $d->titre,
                'consigne' => $d->consigne,
                'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $d->matiere_code),
                'date_remise' => optional($d->date_remise)->format('Y-m-d'),
            ])
            ->values();
    }

    /** Évaluations planifiées pour la classe de l'enfant, avant toute note. */
    public function evaluationsPlanifiees(string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);

        return Evaluation::query()
            ->where('annee', $eleve->annee)
            ->where('classe_code', $eleve->classe_code)
            ->orderBy('date')
            ->get()
            ->map(fn (Evaluation $e) => [
                'id' => $e->id,
                'titre' => $e->titre,
                'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $e->matiere_code),
                'type' => $e->type,
                'date' => optional($e->date)->format('Y-m-d'),
            ])
            ->values();
    }

    /** Calendrier scolaire : événements de la classe de l'enfant, et ceux de tout l'établissement. */
    public function evenements(string $matricule)
    {
        $this->assertEnfantAutorise($matricule);
        $eleve = $this->trouverEnfant($matricule);

        return Evenement::query()
            ->where('annee', $eleve->annee)
            ->where(fn ($q) => $q->whereNull('classe_code')->orWhere('classe_code', $eleve->classe_code))
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
            ])
            ->values();
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
}
