<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use App\Services\NoteEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\SchemaNotes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Saisie des notes — la feuille de notes d'une évaluation.
 *
 * L'écran de notes ne savait que LIRE : il interroge `V_NOTECLASSE`, qui est une vue. Ce
 * contrôleur ouvre l'écriture, dans les deux vraies tables (`T_NOTEENTETE`,
 * `T_NOTEDETAILS`), en passant par App\Services\NoteEcrivain.
 *
 * Le travail se fait par FEUILLE et non note par note : on choisit une classe, une matière,
 * une session et un type d'évaluation, on reçoit la liste des élèves — déjà notés ou non —
 * et on renvoie l'ensemble. C'est ainsi qu'un enseignant note, et cela évite autant d'appels
 * réseau que d'élèves.
 *
 * `GET structure` existe pour une raison précise : la structure de ces tables n'est pas
 * documentée et NEXORA la découvre à l'exécution. Cet appel montre ce que le serveur a vu
 * et quel rôle il a donné à chaque colonne — c'est le moyen de contrôler le rapprochement,
 * et de comprendre un refus, sans ouvrir SQL Server.
 */
class SaisieNoteController extends Controller
{
    public function __construct(private NoteEcrivain $ecrivain) {}

    /** Ce que le serveur a vu des deux tables : colonnes réelles et rôles retenus. */
    public function structure()
    {
        return SchemaNotes::diagnostic();
    }

    private function critereFeuille(Request $request): array
    {
        return $request->validate([
            'classe' => ['required', 'string', 'max:50'],
            'matiere' => ['required', 'string', 'max:50'],
            'session' => ['required', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:50'],
            'libelle' => ['nullable', 'string', 'max:150'],
            'date' => ['nullable', 'date'],
            'coefficient' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bareme' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ]);
    }

    /**
     * La feuille de notes : les élèves de la classe, avec la note déjà saisie s'il y en a.
     *
     * Les élèves viennent de la classe et non des notes existantes : une feuille neuve doit
     * afficher tout le monde, sinon on ne peut rien saisir.
     */
    public function feuille(Request $request)
    {
        $criteres = $this->critereFeuille($request);
        $this->assertDisponible();

        $annee = ContexteScolaire::annee();
        $eleves = Eleve::query()
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->where('CodeClasse', $criteres['classe'])
            ->orderBy('Nom')->orderBy('Prenom')
            ->get();

        $entete = $this->enteteExistante($criteres + ['annee' => $annee]);
        $saisies = $entete ? collect($this->ecrivain->notesDe($entete))->keyBy('eleve') : collect();

        return [
            'annee' => $annee,
            'entete' => $entete,
            'bareme' => $criteres['bareme'] ?? 20,
            'eleves' => $eleves->map(function ($e) use ($saisies) {
                $matricule = $e->getRawOriginal('Matricule');
                $deja = $saisies->get($matricule);

                return [
                    'matricule' => $matricule,
                    'nom' => $e->nom,
                    'prenom' => $e->prenom,
                    'note' => $deja['note'] ?? null,
                    'appreciation' => $deja['appreciation'] ?? null,
                    'absent' => (bool) ($deja['absent'] ?? false),
                ];
            })->values(),
        ];
    }

    /**
     * Enregistre la feuille. Tout part en une fois : l'entête est créée ou retrouvée, puis
     * chaque note est posée ou remplacée.
     */
    public function enregistrer(Request $request)
    {
        $criteres = $this->critereFeuille($request);
        $this->assertDisponible();

        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, 'La saisie des notes');

        $data = $request->validate([
            'notes' => ['required', 'array', 'min:1'],
            'notes.*.matricule' => ['required', 'string', 'max:50'],
            'notes.*.note' => ['nullable', 'numeric', 'min:0'],
            'notes.*.appreciation' => ['nullable', 'string', 'max:255'],
            'notes.*.absent' => ['nullable', 'boolean'],
        ]);

        $bareme = (float) ($criteres['bareme'] ?? 20);
        $attendus = $this->matriculesDeLaClasse($criteres['classe']);

        $lignes = [];
        foreach ($data['notes'] as $i => $n) {
            // Une note ne peut pas dépasser le barème : 25/20 est une faute de frappe,
            // et elle fausserait durablement la moyenne calculée par ECONOMAT.
            if ($n['note'] !== null && (float) $n['note'] > $bareme) {
                throw ValidationException::withMessages([
                    "notes.$i.note" => "La note dépasse le barème de {$bareme}.",
                ]);
            }
            // On ne note pas un élève d'une autre classe : la feuille serait incohérente
            // avec la classe déclarée, et ECONOMAT rattache la note à cette classe-là.
            if (! in_array($n['matricule'], $attendus, true)) {
                throw ValidationException::withMessages([
                    "notes.$i.matricule" => "L'élève {$n['matricule']} n'appartient pas à la classe {$criteres['classe']}.",
                ]);
            }

            $lignes[] = [
                'eleve' => $n['matricule'],
                // Un absent n'a pas de note : on efface celle qui aurait été saisie avant.
                'note' => ($n['absent'] ?? false) ? null : ($n['note'] ?? null),
                'appreciation' => $n['appreciation'] ?? null,
                'absent' => (bool) ($n['absent'] ?? false),
            ];
        }

        try {
            $entete = $this->ecrivain->entetePour($criteres + ['annee' => $annee]);
            $bilan = $this->ecrivain->enregistrer($entete, $lignes);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return ['entete' => $entete] + $bilan;
    }

    private function matriculesDeLaClasse(string $classe): array
    {
        return Eleve::query()
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->where('CodeClasse', $classe)
            ->pluck('Matricule')->filter()->map(fn ($m) => (string) $m)->all();
    }

    private function enteteExistante(array $criteres): ?int
    {
        try {
            $roles = $this->ecrivain->entete();
            $requete = \Illuminate\Support\Facades\DB::connection('economat')->table(SchemaNotes::ENTETE);
            foreach (['classe', 'matiere', 'session', 'annee', 'type', 'libelle'] as $role) {
                if (isset($roles[$role]) && ! empty($criteres[$role])) {
                    $requete->where($roles[$role], $criteres[$role]);
                }
            }
            $ligne = $requete->first();

            return $ligne ? (int) $ligne->{$roles['id']} : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Fermé par défaut : on refuse d'ouvrir la saisie sur une structure non reconnue. */
    private function assertDisponible(): void
    {
        if (! $this->ecrivain->disponible()) {
            abort(response()->json([
                'message' => $this->ecrivain->raisonIndisponible(),
                'structure' => SchemaNotes::diagnostic(),
            ], 409));
        }
    }
}
