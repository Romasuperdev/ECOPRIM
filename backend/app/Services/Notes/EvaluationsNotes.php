<?php

namespace App\Services\Notes;

use App\Models\RhUser;
use App\Services\GrilleCoefficients;
use App\Services\NoteEcrivain;
use App\Services\Notifications\Notifieur;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\SchemaNotes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les évaluations notées de l'espace Enseignant : créer, saisir, publier.
 *
 * Les NOTES vont dans ECONOMAT (T_NOTEENTETE / T_NOTEDETAILS), par NoteEcrivain, comme
 * depuis la saisie du personnel. Ce qu'ECONOMAT ne sait pas porter — intitulé, barème,
 * élèves absents, statut — vit dans notes_evaluations, rattaché par le code de l'entête.
 *
 * BROUILLON / PUBLIÉE. Une évaluation créée ici naît en brouillon : les parents ne la
 * voient pas, ni dans les notes, ni dans la moyenne, ni sur le bulletin téléchargé depuis
 * leur espace. « Publier aux parents » la rend visible et les prévient. Une entête sans
 * fiche ici (saisie avant, ou depuis ECONOMAT) est réputée publiée.
 *
 * ECONOMAT reconnaît une évaluation à sa classe, sa matière, sa session, son type et sa
 * DATE : deux évaluations du même type, dans la même matière, le même jour, seraient la
 * même. On le refuse à la création plutôt que de mélanger leurs notes.
 */
class EvaluationsNotes
{
    public const TYPES = ['Devoir', 'Interrogation', 'Composition', 'Examen'];

    private const BAREME_DEFAUT = 20.0;

    /** @var int[]|null entêtes en brouillon, pour la durée de la requête */
    private ?array $brouillons = null;

    public function __construct(
        private NoteEcrivain $ecrivain,
        private GrilleCoefficients $grille,
        private Notifieur $notifieur,
    ) {}

    // --- Lecture ---------------------------------------------------------------------

    /** Les évaluations de mes matières dans une classe, les plus récentes d'abord. */
    public function lister(string $classe, array $matieres): array
    {
        if ($matieres === []) {
            return [];
        }

        $roles = $this->ecrivain->entete();
        $entetes = ContexteScolaire::appliquer(
            DB::connection('economat')->table(SchemaNotes::ENTETE)
                ->where($roles['classe'], $classe)
                ->whereIn($roles['matiere'], $matieres),
            $roles['annee']
        )->get();

        $fiches = DB::connection('ecoprim')->table('notes_evaluations')
            ->whereIn('entete_code', $entetes->pluck($roles['id'])->all() ?: [0])
            ->get()->keyBy('entete_code');
        $stats = $this->statistiques($entetes->pluck($roles['id'])->map(fn ($c) => (int) $c)->all());
        $libelles = $this->libellesMatieres($entetes->pluck($roles['matiere'])->unique()->all());

        return $entetes->map(function ($e) use ($roles, $fiches, $stats, $libelles, $classe) {
            $code = (int) $e->{$roles['id']};
            $fiche = $fiches[$code] ?? null;
            $matiere = trim((string) $e->{$roles['matiere']});
            $bareme = $fiche ? (float) $fiche->bareme : $this->baremeDe($classe, $matiere, $e->{$roles['coefficient']} ?? null);
            $s = $stats[$code] ?? ['notes' => 0, 'somme' => 0.0];
            $date = $fiche?->date ?? (isset($roles['date']) ? $e->{$roles['date']} : null);

            return [
                'entete' => $code,
                'titre' => $fiche->titre ?? trim(($e->{$roles['type']} ?? 'Évaluation').' du '.$this->dateCourte($date)),
                'matiere' => $matiere,
                'matiere_libelle' => $libelles[$matiere] ?? $matiere,
                'type' => $e->{$roles['type']} ?? null,
                'session' => $e->{$roles['session']} ?? null,
                'date' => $date ? Carbon::parse($date)->toDateString() : null,
                'bareme' => $bareme,
                'statut' => $fiche->statut ?? 'publiee',
                'notes' => $s['notes'],
                'moyenne_sur_20' => $s['notes'] > 0 ? round($s['somme'] / $s['notes'] / $bareme * 20, 2) : null,
            ];
        })->sortByDesc(fn ($e) => ($e['date'] ?? '').sprintf('%010d', $e['entete']))->values()->all();
    }

    /** Une évaluation, avec la feuille des élèves de la classe. Crée la fiche d'une entête ancienne. */
    public function feuille(int $entete): array
    {
        $e = $this->entete($entete);
        $roles = $this->ecrivain->entete();
        $fiche = $this->fiche($entete, $e);
        $absents = $this->absentsDe($fiche);
        $notes = collect($this->ecrivain->notesDe($entete))->keyBy('eleve');

        $eleves = ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_ETUDIANT')->where('CodeClasse', $fiche->classe_code),
            'AnneeAcad'
        )->orderBy('Nom')->orderBy('Prenom')->get(['Matricule', 'Nom', 'Prenom'])->unique('Matricule');

        return [
            'entete' => $entete,
            'titre' => $fiche->titre,
            'classe' => $fiche->classe_code,
            'matiere' => $fiche->matiere_code,
            'matiere_libelle' => $this->libellesMatieres([$fiche->matiere_code])[$fiche->matiere_code] ?? $fiche->matiere_code,
            'type' => $e->{$roles['type']} ?? $fiche->type,
            'session' => $e->{$roles['session']} ?? $fiche->session,
            'date' => $fiche->date ? Carbon::parse($fiche->date)->toDateString() : null,
            'bareme' => (float) $fiche->bareme,
            'statut' => $fiche->statut,
            'publiee_le' => $fiche->publiee_le,
            'eleves' => $eleves->map(fn ($el) => [
                'matricule' => $el->Matricule,
                'nom' => $el->Nom,
                'prenom' => $el->Prenom,
                'note' => isset($notes[$el->Matricule]) && $notes[$el->Matricule]['note'] !== null
                    ? (float) $notes[$el->Matricule]['note'] : null,
                'absent' => in_array($el->Matricule, $absents, true),
            ])->values(),
        ];
    }

    // --- Écriture --------------------------------------------------------------------

    public function creer(RhUser $auteur, array $d): array
    {
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La création d'une évaluation");
        $this->assertDisponible();

        $criteres = [
            'classe' => $d['classe'], 'matiere' => $d['matiere'], 'session' => $d['session'],
            'type' => $d['type'], 'date' => $d['date'], 'annee' => $annee,
        ];

        // Même identité qu'ECONOMAT : une évaluation existe déjà sous ces critères ?
        $existante = $this->ecrivain->enteteExistante($criteres);
        if ($existante !== null) {
            $titre = DB::connection('ecoprim')->table('notes_evaluations')->where('entete_code', $existante)->value('titre');
            throw ValidationException::withMessages([
                'date' => ['Une évaluation « '.($titre ?? $d['type']).' » de cette matière existe déjà ce jour-là. '
                    .'Ouvrez-la, ou choisissez un autre type ou une autre date.'],
            ]);
        }

        $regle = $this->grille->pourClasse($d['classe'])[$d['matiere']] ?? null;
        $bareme = (float) ($d['bareme'] ?? $regle['bareme'] ?? self::BAREME_DEFAUT);

        $entete = $this->ecrivain->entetePour(array_filter($criteres + [
            'libelle' => $d['titre'],
            'bareme' => $bareme,
            'coefficient' => $regle['coefficient'] ?? null,
        ], fn ($v) => $v !== null));

        $maintenant = now();
        DB::connection('ecoprim')->table('notes_evaluations')->insert([
            'entete_code' => $entete,
            'classe_code' => $d['classe'],
            'matiere_code' => $d['matiere'],
            'session' => $d['session'],
            'type' => $d['type'],
            'date' => $d['date'],
            'titre' => mb_substr(trim($d['titre']), 0, 150),
            'bareme' => $bareme,
            'statut' => 'brouillon',
            'rh_user_id' => $auteur->Id,
            'annee' => $annee,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
        $this->brouillons = null;

        return $this->feuille($entete);
    }

    /**
     * Enregistre la feuille ; avec $publier, la rend visible aux parents et les prévient.
     *
     * @param  array<int, array{matricule: string, note: float|null, absent?: bool}>  $saisies
     */
    public function enregistrer(int $entete, array $saisies, bool $publier): array
    {
        $e = $this->entete($entete);
        $fiche = $this->fiche($entete, $e);
        AnneeScolaireGuard::assertModifiable(ContexteScolaire::annee(), 'La saisie des notes');
        $this->assertDisponible();

        $bareme = (float) $fiche->bareme;
        $classe = ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_ETUDIANT')->where('CodeClasse', $fiche->classe_code),
            'AnneeAcad'
        )->pluck('Matricule')->map(fn ($m) => (string) $m)->all();

        $lignes = [];
        $absents = [];
        foreach ($saisies as $i => $s) {
            if (! in_array($s['matricule'], $classe, true)) {
                throw ValidationException::withMessages([
                    "notes.$i.matricule" => "L'élève {$s['matricule']} n'est pas dans la classe.",
                ]);
            }
            $absent = (bool) ($s['absent'] ?? false);
            if (! $absent && $s['note'] !== null && (float) $s['note'] > $bareme) {
                throw ValidationException::withMessages([
                    "notes.$i.note" => "La note de {$s['matricule']} dépasse le barème ({$bareme}).",
                ]);
            }
            if ($absent) {
                $absents[] = $s['matricule'];
            }
            if ($absent || $s['note'] !== null) {
                $lignes[] = ['eleve' => $s['matricule'], 'note' => $absent ? null : (float) $s['note'], 'absent' => $absent];
            }
        }

        $bilan = $this->ecrivain->enregistrer($entete, $lignes);
        $this->effacerNotes($entete, $absents);

        $premierePublication = $publier && $fiche->statut !== 'publiee';
        DB::connection('ecoprim')->table('notes_evaluations')->where('entete_code', $entete)->update(array_filter([
            'absents' => json_encode(array_values($absents)),
            'statut' => $publier ? 'publiee' : null,
            'publiee_le' => $premierePublication ? now() : null,
            'updated_at' => now(),
        ], fn ($v) => $v !== null));
        $this->brouillons = null;

        $prevenus = $premierePublication ? $this->prevenirParents($entete) : 0;

        return ['feuille' => $this->feuille($entete), 'parents_prevenus' => $prevenus] + $bilan;
    }

    // --- Pour l'espace Parent ----------------------------------------------------------

    /** @return int[] entêtes encore en brouillon : à cacher aux parents */
    public function brouillons(): array
    {
        if ($this->brouillons !== null) {
            return $this->brouillons;
        }

        try {
            return $this->brouillons = DB::connection('ecoprim')->table('notes_evaluations')
                ->where('statut', 'brouillon')->pluck('entete_code')->map(fn ($c) => (int) $c)->all();
        } catch (\Throwable $e) {
            return $this->brouillons = []; // table pas encore installée : rien n'est en brouillon
        }
    }

    /** Les notes publiées d'un élève, avec intitulé et barème, les plus récentes d'abord. */
    public function notesPubliees(string $matricule, ?string $classe): array
    {
        $vue = ContexteScolaire::appliquer(
            DB::connection('economat')->table('V_NOTECLASSE')->where('Matricule', $matricule),
            'CodeAnnee'
        )->get(['Code', 'Note', 'CodeMatiere', 'LibelleMatiere', 'TypeNote', 'CodeSession', 'Coefficient'])
            ->unique('Code')
            ->reject(fn ($n) => in_array((int) $n->Code, $this->brouillons(), true));

        $codes = $vue->pluck('Code')->map(fn ($c) => (int) $c)->all();
        $fiches = DB::connection('ecoprim')->table('notes_evaluations')
            ->whereIn('entete_code', $codes ?: [0])->get()->keyBy('entete_code');
        $dates = $this->datesEntetes($codes);

        return $vue->map(function ($n) use ($fiches, $dates, $classe, $matricule) {
            $fiche = $fiches[(int) $n->Code] ?? null;
            $bareme = $fiche ? (float) $fiche->bareme : $this->baremeDe((string) $classe, (string) $n->CodeMatiere, $n->Coefficient);
            $date = $fiche?->date ?? ($dates[(int) $n->Code] ?? null);

            return [
                'entete' => (int) $n->Code,
                'titre' => $fiche->titre ?? trim(($n->TypeNote ?? 'Évaluation').($date ? ' du '.$this->dateCourte($date) : '')),
                'matiere' => $n->LibelleMatiere ?? $n->CodeMatiere,
                'session' => $n->CodeSession,
                'date' => $date ? Carbon::parse($date)->toDateString() : null,
                'note' => $n->Note !== null ? (float) $n->Note : null,
                'bareme' => $bareme,
                'absent' => $fiche ? in_array($matricule, $this->absentsDe($fiche), true) : false,
            ];
        })->sortByDesc(fn ($n) => ($n['date'] ?? '').sprintf('%010d', $n['entete']))->values()->all();
    }

    // --- Rouages ---------------------------------------------------------------------

    private function assertDisponible(): void
    {
        abort_unless($this->ecrivain->disponible(), 409, $this->ecrivain->raisonIndisponible());
    }

    private function entete(int $code): object
    {
        $roles = $this->ecrivain->entete();
        $e = DB::connection('economat')->table(SchemaNotes::ENTETE)->where($roles['id'], $code)->first();
        abort_unless($e, 404, 'Évaluation introuvable.');

        return $e;
    }

    /** La classe et la matière d'une entête : pour le contrôle d'accès, avant toute lecture. */
    public function classeEtMatiere(int $code): array
    {
        $roles = $this->ecrivain->entete();
        $e = $this->entete($code);

        return [trim((string) $e->{$roles['classe']}), trim((string) $e->{$roles['matiere']})];
    }

    /** La fiche NEXORA d'une entête ; créée, publiée, pour une entête plus ancienne. */
    private function fiche(int $code, object $e): object
    {
        $fiche = DB::connection('ecoprim')->table('notes_evaluations')->where('entete_code', $code)->first();
        if ($fiche) {
            return $fiche;
        }

        $roles = $this->ecrivain->entete();
        $classe = trim((string) $e->{$roles['classe']});
        $matiere = trim((string) $e->{$roles['matiere']});
        $date = isset($roles['date']) ? $e->{$roles['date']} : null;
        $maintenant = now();

        DB::connection('ecoprim')->table('notes_evaluations')->insert([
            'entete_code' => $code,
            'classe_code' => $classe,
            'matiere_code' => $matiere,
            'session' => $e->{$roles['session']} ?? null,
            'type' => $e->{$roles['type']} ?? null,
            'date' => $date ? Carbon::parse($date)->toDateString() : null,
            'titre' => mb_substr(trim(($e->{$roles['type']} ?? 'Évaluation').($date ? ' du '.$this->dateCourte($date) : '')), 0, 150),
            'bareme' => $this->baremeDe($classe, $matiere, $e->{$roles['coefficient']} ?? null),
            // Déjà visible des parents avant : elle le reste.
            'statut' => 'publiee',
            'annee' => ContexteScolaire::annee(),
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);

        return DB::connection('ecoprim')->table('notes_evaluations')->where('entete_code', $code)->first();
    }

    private function absentsDe(object $fiche): array
    {
        return $fiche->absents ? (json_decode($fiche->absents, true) ?: []) : [];
    }

    /** Un absent n'a pas de note : on efface celle qui aurait été saisie avant. */
    private function effacerNotes(int $entete, array $matricules): void
    {
        if ($matricules === []) {
            return;
        }
        $roles = $this->ecrivain->detail();
        DB::connection('economat')->table(SchemaNotes::DETAIL)
            ->where($roles['entete'], $entete)
            ->whereIn($roles['eleve'], $matricules)
            ->update([$roles['note'] => null]);
    }

    /** Une notification par élève noté (ou absent), à ses parents. */
    private function prevenirParents(int $entete): int
    {
        $f = $this->feuille($entete);
        $prevenus = [];

        foreach ($f['eleves'] as $e) {
            if ($e['note'] === null && ! $e['absent']) {
                continue;
            }
            $parents = $this->notifieur->parentsDesEleves([$e['matricule']]);
            if ($parents === []) {
                continue;
            }
            $prenom = trim((string) $e['prenom']) ?: trim((string) $e['nom']);
            $corps = $e['absent']
                ? "{$prenom} était absent(e) à cette évaluation de {$f['matiere_libelle']}."
                : "{$prenom} : ".$this->nombre($e['note']).'/'.$this->nombre($f['bareme'])." en {$f['matiere_libelle']}.";

            $this->notifieur->envoyer($parents, 'note', "Nouvelle note : {$f['titre']}", $corps,
                '/mon-espace/enfants/'.rawurlencode($e['matricule']).'?onglet=resultats',
                ['entete' => $entete, 'matricule' => $e['matricule']]);
            array_push($prevenus, ...$parents);
        }

        return count(array_unique($prevenus));
    }

    /** @return array<int, array{notes: int, somme: float}> */
    private function statistiques(array $entetes): array
    {
        if ($entetes === []) {
            return [];
        }
        $roles = $this->ecrivain->detail();

        return DB::connection('economat')->table(SchemaNotes::DETAIL)
            ->whereIn($roles['entete'], $entetes)
            ->whereNotNull($roles['note'])
            ->get([$roles['entete'].' as entete', $roles['eleve'].' as eleve', $roles['note'].' as note'])
            ->unique(fn ($l) => $l->entete.'|'.$l->eleve)
            ->groupBy('entete')
            ->map(fn ($lot) => ['notes' => $lot->count(), 'somme' => (float) $lot->sum('note')])
            ->all();
    }

    private function datesEntetes(array $codes): array
    {
        $roles = $this->ecrivain->entete();
        if ($codes === [] || ! isset($roles['date'])) {
            return [];
        }

        return DB::connection('economat')->table(SchemaNotes::ENTETE)
            ->whereIn($roles['id'], $codes)
            ->pluck($roles['date'], $roles['id'])
            ->mapWithKeys(fn ($d, $c) => [(int) $c => $d])
            ->all();
    }

    private function libellesMatieres(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return DB::connection('economat')->table('T_MATIERE')
            ->whereIn('CodeMatiere', $codes)
            ->pluck('LibelleMatiere', 'CodeMatiere')
            ->map(fn ($l) => trim((string) $l))
            ->all();
    }

    /** Barème d'une matière : la grille, sinon le coefficient d'entête (voir MoyennesClasse). */
    private function baremeDe(string $classe, string $matiere, $coefficient): float
    {
        $grille = $classe !== '' ? ($this->grille->pourClasse($classe)[$matiere]['bareme'] ?? null) : null;
        if ($grille) {
            return (float) $grille;
        }
        $c = $coefficient !== null ? (float) $coefficient : 0.0;

        return $c > 0 ? ($c >= 10 ? $c : $c * 20) : self::BAREME_DEFAUT;
    }

    private function dateCourte($date): string
    {
        return $date ? Carbon::parse($date)->locale('fr')->isoFormat('D MMM') : '';
    }

    private function nombre(float $n): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'));
    }
}
