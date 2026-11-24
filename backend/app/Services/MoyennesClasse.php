<?php

namespace App\Services;

use App\Support\ContexteScolaire;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moyennes d'une classe calculées à partir des notes saisies (V_NOTECLASSE).
 *
 * POURQUOI. Le classement officiel est celui d'ECONOMAT (V_MOYENNE_ELEVE_CLASSE). Mais
 * ECONOMAT ne remplit cette vue que lorsqu'on y lance le calcul : tant que personne ne l'a
 * fait, la page Résultats restait vide alors que toutes les notes étaient là. Ce calcul
 * prend le relais dans ce cas-là, et dans ce cas-là seulement — dès qu'ECONOMAT a produit
 * ses moyennes, ce sont elles qu'on affiche (voir RapportController::resultats()).
 *
 * LA RÈGLE, celle du primaire ivoirien : chaque matière est notée sur un barème (Maths
 * sur 50, Dictée sur 20…) ; la moyenne est le total des points obtenus rapporté au total
 * des points possibles, ramené sur 20. Plusieurs notes dans une même matière et une même
 * session (devoir + composition) sont d'abord moyennées entre elles.
 *
 * Seules les matières où l'élève a une note comptent dans son total : une note absente
 * n'est pas un zéro. Le nombre de matières notées est renvoyé pour que cela se voie.
 */
class MoyennesClasse
{
    private const BAREME_DEFAUT = 20.0;

    /** Un coefficient d'entête au moins égal à ce seuil est déjà un total de points. */
    private const SEUIL_POINTS = 10.0;

    public function __construct(private GrilleCoefficients $grille) {}

    /**
     * @return array{session: ?string, sessions: array<int, string>, matieres: array<int, array>, lignes: Collection}
     */
    public function calculer(string $codeClasse, ?string $session = null): array
    {
        $toutes = $this->notesDeLaClasse($codeClasse);
        $sessions = $this->sessions($toutes);

        // Sans session demandée, la plus récente : mélanger S1 et S2 dans une même
        // moyenne n'aurait aucun sens.
        $session ??= $sessions === [] ? null : end($sessions);

        $notes = $toutes
            ->filter(fn ($n) => (string) $n->CodeSession === (string) $session && $n->Note !== null)
            // V_NOTECLASSE joint T_ETUDIANT par matricule : un élève inscrit sur deux années
            // y figure deux fois avec la même note. Une note = un entête + un élève.
            ->unique(fn ($n) => $n->Code.'|'.$n->Matricule)
            ->values();

        $grille = $this->grille->pourClasse($codeClasse);

        $matieres = $notes->groupBy('CodeMatiere')
            ->map(fn ($lot, $code) => [
                'code' => (string) $code,
                'libelle' => $lot->first()->LibelleMatiere ?? (string) $code,
                'bareme' => $this->bareme(
                    $grille[$code]['bareme'] ?? null,
                    $lot->pluck('Coefficient')->filter(fn ($c) => $c !== null)->first(),
                ),
            ])
            ->sortBy('libelle')
            ->values();

        $baremes = $matieres->pluck('bareme', 'code');
        $parEleve = $notes->groupBy('Matricule');

        $lignes = $this->eleves($codeClasse, $notes)->map(function ($eleve) use ($parEleve, $baremes, $session) {
            $notesEleve = [];
            $total = 0.0;
            $totalMax = 0.0;

            foreach (($parEleve[$eleve['matricule']] ?? collect())->groupBy('CodeMatiere') as $code => $lot) {
                $note = (float) $lot->avg('Note');
                $notesEleve[$code] = round($note, 2);
                $total += $note;
                $totalMax += $baremes[$code] ?? self::BAREME_DEFAUT;
            }

            return $eleve + [
                'notes' => (object) $notesEleve,
                'total' => $totalMax > 0 ? round($total, 2) : null,
                'total_max' => $totalMax > 0 ? round($totalMax, 2) : null,
                'matieres_notees' => count($notesEleve),
                'moyenne' => $totalMax > 0 ? round($total / $totalMax * 20, 2) : null,
                'rang' => null,
                'session' => $session,
                'passage' => null,
            ];
        });

        return [
            'session' => $session,
            'sessions' => $sessions,
            'matieres' => $matieres->all(),
            'lignes' => $this->classer($lignes),
        ];
    }

    /** Les sessions où cette classe a des notes, dans l'ordre (S1, S2, S3). */
    public function sessionsDe(string $codeClasse): array
    {
        return $this->sessions($this->notesDeLaClasse($codeClasse));
    }

    private function notesDeLaClasse(string $codeClasse): Collection
    {
        $requete = DB::connection('economat')->table('V_NOTECLASSE')->where('CodeClasse', $codeClasse);
        ContexteScolaire::appliquer($requete, 'CodeAnnee');

        return $requete->get(['Code', 'Matricule', 'Nom', 'Prenom', 'Note', 'CodeMatiere',
            'LibelleMatiere', 'CodeSession', 'Coefficient']);
    }

    private function sessions(Collection $notes): array
    {
        return $notes->pluck('CodeSession')
            ->filter(fn ($s) => $s !== null && trim((string) $s) !== '')
            ->map(fn ($s) => (string) $s)
            ->unique()->sort()->values()->all();
    }

    /**
     * Le barème d'une matière, en points. La grille NEXORA d'abord ; à défaut, le
     * coefficient porté par l'entête de note.
     *
     * Ce coefficient s'écrit de deux façons selon qui a créé l'entête : ECONOMAT met un
     * multiplicateur de 20 (2,5 pour une matière sur 50), la saisie NEXORA met le total
     * de points tiré de la grille (50). Aucun barème de primaire ne dépasse 200 points,
     * d'où le seuil : en dessous de 10, c'est un multiplicateur.
     */
    private function bareme(?float $grille, $coefficient): float
    {
        if ($grille !== null && $grille > 0) {
            return $grille;
        }

        $c = $coefficient !== null ? (float) $coefficient : 0.0;
        if ($c > 0) {
            return $c >= self::SEUIL_POINTS ? $c : $c * 20;
        }

        return self::BAREME_DEFAUT;
    }

    /**
     * Les élèves de la classe pour l'année de travail — tous, notés ou non : c'est la
     * liste de classe qu'on vient chercher. S'y ajoutent ceux qui ont des notes dans
     * cette classe sans y être (plus) inscrits, pour ne perdre aucune note.
     */
    private function eleves(string $codeClasse, Collection $notes): Collection
    {
        $inscrits = ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_ETUDIANT')->where('CodeClasse', $codeClasse),
            'AnneeAcad'
        )->orderBy('Nom')->orderBy('Prenom')->get(['Code', 'Matricule', 'Nom', 'Prenom'])
            ->unique('Matricule')
            ->map(fn ($e) => [
                'code_eleve' => $e->Code,
                'matricule' => $e->Matricule,
                'nom' => $e->Nom,
                'prenom' => $e->Prenom,
            ]);

        $connus = $inscrits->pluck('matricule')->flip();
        $horsListe = $notes->reject(fn ($n) => isset($connus[$n->Matricule]))
            ->unique('Matricule')
            ->map(fn ($n) => [
                'code_eleve' => null,
                'matricule' => $n->Matricule,
                'nom' => $n->Nom,
                'prenom' => $n->Prenom,
            ]);

        return $inscrits->concat($horsListe)->values();
    }

    /**
     * Rang « à la française » : deux élèves à égalité partagent le rang, le suivant
     * saute (1, 2, 2, 4). Les élèves sans moyenne n'ont pas de rang et ferment la liste,
     * dans l'ordre alphabétique.
     */
    private function classer(Collection $lignes): Collection
    {
        $notes = $lignes->whereNotNull('moyenne')->sortByDesc('moyenne')->values();
        $sansNote = $lignes->whereNull('moyenne')->sortBy(fn ($l) => $l['nom'].' '.$l['prenom']);

        $precedente = null;
        $rang = 0;
        $classees = $notes->map(function ($ligne, $i) use (&$precedente, &$rang) {
            if ($ligne['moyenne'] !== $precedente) {
                $rang = $i + 1;
                $precedente = $ligne['moyenne'];
            }
            $ligne['rang'] = (string) $rang;

            return $ligne;
        });

        return $classees->concat($sansNote)->values();
    }
}
