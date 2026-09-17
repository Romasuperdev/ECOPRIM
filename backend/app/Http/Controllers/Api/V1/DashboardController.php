<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tableau de bord du personnel — indicateurs lus dans ECONOMAT.
 *
 * Deux principes tenus dans tout ce fichier :
 *
 * 1. Chaque indicateur est calculé isolément (`sansErreur`). Une vue absente ou
 *    une table injoignable renvoie null pour ce seul chiffre, au lieu de faire
 *    tomber la page entière.
 * 2. Tout est borné à l'année de travail ET à l'établissement de travail. Le
 *    filtre établissement passe par les classes, faute de colonne dédiée sur
 *    T_ETUDIANT — voir PerimetreEtablissement.
 *
 * Volontairement absent : le financier. T_VERSEMENT contient bien les
 * encaissements, mais la règle « jamais le financier » posée à la conception de
 * cet applicatif a été confirmée ; ce tableau de bord reste pédagogique.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $codesClasses = $this->sansErreur(fn () => PerimetreEtablissement::codesClasses());
        $effectifs = $this->effectifs();
        $absencesCeMois = $this->sansErreur(fn () => $this->absencesCeMois());

        return [
            'annee_scolaire_active' => ContexteScolaire::annee(),
            'etablissement' => [
                'code' => PerimetreEtablissement::code(),
                'nom' => PerimetreEtablissement::nom(),
            ],
            // Le front affiche un avertissement dès que `applique` est faux alors qu'un
            // établissement est choisi : les chiffres sont alors ceux de la société, et
            // il faut que cela se voie. `classes_sans_etablissement` dit quoi corriger.
            'perimetre' => [
                'applique' => $this->sansErreur(fn () => PerimetreEtablissement::applique()) ?? false,
                'raison' => $this->sansErreur(fn () => PerimetreEtablissement::raison()),
                'classes_sans_etablissement' => $this->sansErreur(
                    fn () => PerimetreEtablissement::classesOrphelines()
                ) ?? 0,
            ],
            'effectifs' => $effectifs,
            'moyenne_generale' => $this->sansErreur(fn () => $this->moyenneGenerale()),
            'absences_ce_mois' => $absencesCeMois,
            'assiduite' => $this->sansErreur(fn () => $this->assiduite($effectifs['total_eleves'])),
            'taux_reussite' => $this->sansErreur(fn () => $this->tauxReussite()),
            'taux_encadrement' => $this->sansErreur(fn () => $this->tauxEncadrement($codesClasses)),
            'repartition_niveaux' => $this->sansErreur(fn () => $this->repartitionNiveaux()) ?? [],
            'effectif_par_classe' => $this->sansErreur(fn () => $this->effectifParClasse()) ?? [],
            'moyenne_par_classe' => $this->sansErreur(fn () => $this->moyenneParClasse()) ?? [],
            'inscriptions_par_mois' => $this->sansErreur(fn () => $this->inscriptionsParMois()) ?? [],
            'absences_par_mois' => $this->sansErreur(fn () => $this->absencesParMois()) ?? [],
            'agenda' => $this->sansErreur(fn () => $this->agenda()) ?? [],
        ];
    }

    private function effectifs(): array
    {
        return [
            'total_eleves' => $this->sansErreur(fn () => $this->eleves()->count()) ?? 0,
            'total_classes' => $this->sansErreur(fn () => count(
                PerimetreEtablissement::codesClasses()
                    ?? ContexteScolaire::appliquer(
                        DB::connection('economat')->table('T_CLASSE'), 'ANNEE'
                    )->pluck('CodeClasse')->all()
            )) ?? 0,
            // Les enseignants ne portent ni classe ni établissement : on les compte
            // via leurs affectations dès qu'un établissement est choisi.
            'total_enseignants' => $this->sansErreur(fn () => $this->enseignants()) ?? 0,
        ];
    }

    /** Élèves de l'année et de l'établissement de travail. */
    private function eleves()
    {
        $requete = ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_ETUDIANT'), 'AnneeAcad'
        );

        return PerimetreEtablissement::appliquerParClasse($requete);
    }

    private function enseignants(): int
    {
        $codes = PerimetreEtablissement::codesClasses();

        if ($codes === null) {
            return (int) ContexteScolaire::appliquer(
                DB::connection('economat')->table('T_PROFESSEUR'), 'CodeAnnee'
            )->count();
        }

        $requete = DB::connection('economat')->table('T_CORPROFCLASSE')
            ->whereIn('CodeClasse', $codes);
        ContexteScolaire::appliquer($requete, 'ANNEE');

        return $requete->distinct()->count('CodeProfesseur');
    }

    private function absencesCeMois(): int
    {
        $requete = DB::connection('economat')->table('T_ABSENCEELEVE')
            ->whereMonth('Date', now()->month)
            ->whereYear('Date', now()->year);
        ContexteScolaire::appliquer($requete, 'AnneeCour');
        PerimetreEtablissement::appliquerParClasse($requete);

        return (int) $requete->count();
    }

    /**
     * Vrai taux d'assiduité, rapporté aux journées réellement écoulées.
     *
     * L'ancien calcul — `100 - absences / effectif` — n'avait pas de sens : il
     * comparait un cumul d'absences depuis la rentrée à un effectif, sans jamais
     * tenir compte du temps passé. Une école de 300 élèves atteignait 0 % dès la
     * 300ᵉ absence de l'année, quelle que soit la période.
     *
     * Ici : jours ouvrés écoulés depuis le début de l'année scolaire × effectif =
     * journées-élèves attendues ; les absences s'y rapportent. Les vacances ne
     * sont pas déduites (elles gonflent légèrement le dénominateur, donc le taux) —
     * `journees_attendues` est renvoyé pour que la lecture reste honnête.
     */
    private function assiduite(int $effectif): ?array
    {
        if ($effectif <= 0) {
            return null;
        }

        // Le calcul des jours ouvrés vit dans ContexteScolaire : la page Assiduité
        // s'en sert aussi, et deux copies auraient fini par diverger.
        $joursOuvres = ContexteScolaire::joursOuvresEcoules();
        if ($joursOuvres === null) {
            return null;
        }

        $requete = DB::connection('economat')->table('T_ABSENCEELEVE');
        ContexteScolaire::appliquer($requete, 'AnneeCour');
        PerimetreEtablissement::appliquerParClasse($requete);
        $absences = (int) $requete->count();

        $attendues = $joursOuvres * $effectif;

        return [
            'taux' => round(max(0, 100 - ($absences / $attendues) * 100), 1),
            'absences' => $absences,
            'jours_ouvres' => $joursOuvres,
            'journees_attendues' => $attendues,
        ];
    }

    /** Moyenne des moyennes élèves (V_MOYENNE_ELEVE_CLASSE). */
    private function moyenneGenerale(): ?float
    {
        $requete = DB::connection('economat')->table('V_MOYENNE_ELEVE_CLASSE');
        ContexteScolaire::appliquer($requete, 'CodeAnnee');
        PerimetreEtablissement::appliquerParClasse($requete);
        $moyenne = $requete->avg('Moyenne');

        return $moyenne !== null ? round((float) $moyenne, 2) : null;
    }

    /** Part des élèves ayant une moyenne ≥ 10. */
    private function tauxReussite(): ?array
    {
        $requete = DB::connection('economat')->table('V_MOYENNE_ELEVE_CLASSE')
            ->whereNotNull('Moyenne');
        ContexteScolaire::appliquer($requete, 'CodeAnnee');
        PerimetreEtablissement::appliquerParClasse($requete);

        $total = (int) $requete->count();
        if ($total === 0) {
            return null;
        }

        $admis = (int) (clone $requete)->where('Moyenne', '>=', 10)->count();

        return [
            'taux' => round(($admis / $total) * 100, 1),
            'admis' => $admis,
            'evalues' => $total,
        ];
    }

    /** Part des classes ayant un professeur principal désigné. */
    private function tauxEncadrement(?array $codesClasses): ?array
    {
        $classes = $codesClasses ?? ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_CLASSE'), 'ANNEE'
        )->pluck('CodeClasse')->map(fn ($c) => (string) $c)->all();

        $total = count($classes);
        if ($total === 0) {
            return null;
        }

        $requete = DB::connection('economat')->table('T_CORPROFCLASSE')
            ->whereIn('CodeClasse', $classes)
            ->where('Principale', true);
        ContexteScolaire::appliquer($requete, 'ANNEE');
        $couvertes = (int) $requete->distinct()->count('CodeClasse');

        return [
            'taux' => round(($couvertes / $total) * 100, 1),
            'couvertes' => $couvertes,
            'classes' => $total,
        ];
    }

    private function repartitionNiveaux(): array
    {
        $libelles = ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_NIVEAU'), 'ANNEE'
        )->pluck('LibelleNiveau', 'CodeNiveau');

        return $this->eleves()
            ->select('CodeNiveau', DB::raw('COUNT(*) as effectif'))
            ->whereNotNull('CodeNiveau')
            ->groupBy('CodeNiveau')
            ->get()
            ->map(fn ($l) => [
                'niveau' => $libelles[$l->CodeNiveau] ?? $l->CodeNiveau,
                'effectif' => (int) $l->effectif,
            ])
            ->sortByDesc('effectif')
            ->values()
            ->all();
    }

    private function effectifParClasse(): array
    {
        $libelles = $this->libellesClasses();

        return $this->eleves()
            ->select('CodeClasse', DB::raw('COUNT(*) as effectif'))
            ->whereNotNull('CodeClasse')
            ->groupBy('CodeClasse')
            ->get()
            ->map(fn ($l) => [
                'classe' => $libelles[$l->CodeClasse] ?? $l->CodeClasse,
                'effectif' => (int) $l->effectif,
            ])
            ->sortByDesc('effectif')
            ->values()
            ->all();
    }

    private function moyenneParClasse(): array
    {
        $libelles = $this->libellesClasses();

        $requete = DB::connection('economat')->table('V_MOYENNE_ELEVE_CLASSE')
            ->select('CodeClasse', DB::raw('AVG(Moyenne) as moyenne'))
            ->whereNotNull('CodeClasse');
        ContexteScolaire::appliquer($requete, 'CodeAnnee');
        PerimetreEtablissement::appliquerParClasse($requete);

        return $requete
            ->groupBy('CodeClasse')
            ->get()
            ->map(fn ($l) => [
                'classe' => $libelles[$l->CodeClasse] ?? $l->CodeClasse,
                'moyenne' => $l->moyenne !== null ? round((float) $l->moyenne, 2) : null,
            ])
            ->filter(fn ($r) => $r['moyenne'] !== null)
            ->sortByDesc('moyenne')
            ->values()
            ->all();
    }

    /**
     * Mouvements mois par mois, sur les douze mois de l'année scolaire.
     * T_ETUDIANT distingue inscription, réinscription et transfert par trois
     * drapeaux ; un élève sans drapeau est compté comme inscription.
     */
    private function inscriptionsParMois(): array
    {
        [$an, $mois] = $this->partiesDate('DateInscription');

        $lignes = $this->eleves()
            ->whereNotNull('DateInscription')
            ->select(
                DB::raw("$an as annee"),
                DB::raw("$mois as mois"),
                DB::raw('SUM(CASE WHEN Reinscription = 1 THEN 1 ELSE 0 END) as reinscriptions'),
                DB::raw('SUM(CASE WHEN Transfert = 1 THEN 1 ELSE 0 END) as transferts'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy(DB::raw($an), DB::raw($mois))
            ->get();

        return $lignes
            ->sortBy(fn ($l) => sprintf('%04d-%02d', $l->annee, $l->mois))
            ->map(fn ($l) => [
                'mois' => sprintf('%04d-%02d', $l->annee, $l->mois),
                'libelle' => self::MOIS[(int) $l->mois - 1],
                'reinscriptions' => (int) $l->reinscriptions,
                'transferts' => (int) $l->transferts,
                'inscriptions' => max(0, (int) $l->total - (int) $l->reinscriptions - (int) $l->transferts),
            ])
            ->values()
            ->all();
    }

    private function absencesParMois(): array
    {
        [$an, $mois] = $this->partiesDate('Date');

        $requete = DB::connection('economat')->table('T_ABSENCEELEVE')
            ->whereNotNull('Date')
            ->select(
                DB::raw("$an as annee"),
                DB::raw("$mois as mois"),
                DB::raw('SUM(CASE WHEN Justifier = 1 THEN 1 ELSE 0 END) as justifiees'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy(DB::raw($an), DB::raw($mois));
        ContexteScolaire::appliquer($requete, 'AnneeCour');
        PerimetreEtablissement::appliquerParClasse($requete);

        return $requete->get()
            ->sortBy(fn ($l) => sprintf('%04d-%02d', $l->annee, $l->mois))
            ->map(fn ($l) => [
                'mois' => sprintf('%04d-%02d', $l->annee, $l->mois),
                'libelle' => self::MOIS[(int) $l->mois - 1],
                'justifiees' => (int) $l->justifiees,
                'non_justifiees' => max(0, (int) $l->total - (int) $l->justifiees),
            ])
            ->values()
            ->all();
    }

    /**
     * Les dix prochaines échéances, toutes natures confondues : évaluations
     * planifiées, devoirs à rendre, événements du calendrier. Ces trois tables
     * sont propres à NEXORA (ecoprim), donc fiables et datées.
     */
    private function agenda(): array
    {
        $annees = ContexteScolaire::variantes();
        $aujourdhui = now()->toDateString();
        $classes = $this->libellesClasses();
        $nomClasse = fn ($code) => $code ? ($classes[$code] ?? $code) : null;

        $lire = function (string $table, string $colonneDate, string $type) use ($annees, $aujourdhui) {
            $requete = DB::connection('ecoprim')->table($table)
                ->whereDate($colonneDate, '>=', $aujourdhui)
                ->orderBy($colonneDate)
                ->limit(10);
            if ($annees !== []) {
                $requete->whereIn('annee', $annees);
            }

            return $requete->get()->map(fn ($l) => [
                'date' => (string) $l->{$colonneDate},
                'titre' => $l->titre,
                'classe_code' => $l->classe_code ?? null,
                'nature' => $type,
                'type' => $l->type ?? null,
            ]);
        };

        return collect()
            ->concat($this->sansErreur(fn () => $lire('evaluations', 'date', 'evaluation')) ?? [])
            ->concat($this->sansErreur(fn () => $lire('devoirs', 'date_remise', 'devoir')) ?? [])
            ->concat($this->sansErreur(fn () => $lire('evenements', 'date_debut', 'evenement')) ?? [])
            ->sortBy('date')
            ->take(10)
            ->map(fn ($l) => [
                'date' => substr($l['date'], 0, 10),
                'titre' => $l['titre'],
                'classe' => $nomClasse($l['classe_code']),
                'nature' => $l['nature'],
                'type' => $l['type'],
            ])
            ->values()
            ->all();
    }

    /** Libellés des classes du périmètre, indexés par code. */
    private function libellesClasses()
    {
        $requete = ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_CLASSE'), 'ANNEE'
        );
        PerimetreEtablissement::appliquerParClasse($requete);

        return $requete->pluck('LibelleClasse', 'CodeClasse');
    }

    /**
     * Extraction année/mois, selon le moteur.
     *
     * ECONOMAT tourne sur SQL Server (YEAR/MONTH), mais les tests isolent la connexion
     * sur SQLite, qui ne connaît que `strftime`. Sans ce détour, les deux graphiques
     * mensuels levaient une exception avalée par `sansErreur` : ils rendaient un tableau
     * vide et n'étaient donc jamais réellement testés.
     */
    private function partiesDate(string $colonne): array
    {
        if (DB::connection('economat')->getDriverName() === 'sqlite') {
            return [
                "CAST(strftime('%Y', $colonne) AS INTEGER)",
                "CAST(strftime('%m', $colonne) AS INTEGER)",
            ];
        }

        return ["YEAR($colonne)", "MONTH($colonne)"];
    }

    private const MOIS = [
        'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc',
    ];

    /** Isole chaque indicateur : une source manquante ne fait pas tomber la page. */
    private function sansErreur(callable $calcul)
    {
        try {
            return $calcul();
        } catch (Throwable $e) {
            return null;
        }
    }
}
