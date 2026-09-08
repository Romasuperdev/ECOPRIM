<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
                'photo' => $e->photo,
            ])->values(),
        ];
    }

    public function enfant(string $matricule)
    {
        $this->assertEnfantAutorise($matricule);

        return $this->trouverEnfant($matricule)->load('classe');
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
}
