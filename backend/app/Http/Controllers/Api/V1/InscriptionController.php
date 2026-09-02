<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use App\Services\EtudiantEcrivain;
use App\Services\PhotoEleveStockage;
use App\Support\AnneeScolaireGuard;
use App\Support\CoherenceScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inscriptions — saisie d'un élève dans ECONOMAT.dbo.T_ETUDIANT.
 *
 * Une inscription EST un élève : les quatre mouvements (inscription, réinscription,
 * transfert entrant, transfert sortant) sont portés par les indicateurs
 * Inscription / Reinscription / Transfert de T_ETUDIANT.
 * Création et modification ; jamais de suppression (table partagée avec ECONOMAT).
 */
class InscriptionController extends Controller
{
    public function __construct(
        private EtudiantEcrivain $ecrivain,
        private PhotoEleveStockage $photos,
    ) {}

    public function index(Request $request)
    {
        $eleves = Eleve::query()
            ->when($request->filled('annee'), fn ($q) => $q->where('AnneeAcad', $request->input('annee')))
            ->when($request->filled('classe'), fn ($q) => $q->where('CodeClasse', $request->input('classe')))
            ->when($request->filled('mouvement'), function ($q) use ($request) {
                match ($request->input('mouvement')) {
                    'inscription' => $q->where('Inscription', 1),
                    'reinscription' => $q->where('Reinscription', 1),
                    'transfert_entrant', 'transfert_sortant' => $q->where('Transfert', 1),
                    default => null,
                };
            })
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = $request->input('q');
                $q->where(fn ($w) => $w->where('Nom', 'like', "%{$t}%")
                    ->orWhere('Prenom', 'like', "%{$t}%")
                    ->orWhere('Matricule', 'like', "%{$t}%"));
            })
            ->orderBy('Nom')->orderBy('Prenom')
            ->paginate(min($request->integer('per_page', 20), 200));

        return $eleves;
    }

    public function show(int $inscription)
    {
        return Eleve::findOrFail($inscription);
    }

    private function regles(bool $creation): array
    {
        $l = EtudiantEcrivain::LARGEURS;

        return [
            'mouvement' => [$creation ? 'required' : 'nullable', Rule::in(EtudiantEcrivain::MOUVEMENTS)],
            // Identité
            'matricule' => ['required', 'string', 'max:'.$l['matricule']],
            'nom' => ['required', 'string', 'max:'.$l['nom']],
            'prenom' => ['required', 'string', 'max:'.$l['prenom']],
            'sexe' => ['nullable', 'in:M,F'],
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'lieu_naissance' => ['nullable', 'string', 'max:'.$l['lieu_naissance']],
            'nationalite' => ['nullable', 'string', 'max:'.$l['nationalite']],
            // Coordonnées
            'adresse' => ['nullable', 'string', 'max:'.$l['adresse']],
            'ville' => ['nullable', 'string', 'max:'.$l['ville']],
            'commune' => ['nullable', 'string', 'max:'.$l['commune']],
            'quartier' => ['nullable', 'string', 'max:'.$l['quartier']],
            'telephone' => ['nullable', 'string', 'max:'.$l['telephone']],
            'email' => ['nullable', 'email', 'max:'.$l['email']],
            // Scolarité
            'annee' => ['required', 'string', 'max:'.$l['annee']],
            'cycle_code' => ['nullable', 'string', 'max:'.$l['cycle_code']],
            'niveau_code' => ['nullable', 'string', 'max:'.$l['niveau_code']],
            'classe_code' => ['nullable', 'string', 'max:'.$l['classe_code']],
            'redoublant' => ['nullable', 'string', 'max:'.$l['redoublant']],
            'etab_origine' => ['nullable', 'string', 'max:'.$l['etab_origine']],
            'niveau_origine' => ['nullable', 'string', 'max:'.$l['niveau_origine']],
            'date_inscription' => ['nullable', 'date'],
            // Filiation
            'pere_nom' => ['nullable', 'string', 'max:'.$l['pere_nom']],
            'pere_prenom' => ['nullable', 'string', 'max:'.$l['pere_prenom']],
            'pere_profession' => ['nullable', 'string', 'max:'.$l['pere_profession']],
            'pere_telephone' => ['nullable', 'string', 'max:'.$l['pere_telephone']],
            'pere_email' => ['nullable', 'email', 'max:'.$l['pere_email']],
            'mere_nom' => ['nullable', 'string', 'max:'.$l['mere_nom']],
            'mere_prenom' => ['nullable', 'string', 'max:'.$l['mere_prenom']],
            'mere_profession' => ['nullable', 'string', 'max:'.$l['mere_profession']],
            'mere_telephone' => ['nullable', 'string', 'max:'.$l['mere_telephone']],
            'mere_email' => ['nullable', 'email', 'max:'.$l['mere_email']],
            'societe_code' => ['nullable', 'string', 'max:'.$l['societe_code']],
        ];
    }

    /** Mouvements qui font ENTRER un élève dans l'établissement : une ligne est créée. */
    private const MOUVEMENTS_ENTRANTS = ['inscription', 'transfert_entrant'];

    /**
     * Enregistre un mouvement.
     *
     * T_ETUDIANT ne garde qu'UNE ligne par élève (l'historique est archivé par ECONOMAT
     * dans T_HISTETUDIANT). Donc :
     *  - inscription / transfert entrant -> l'élève est nouveau : on crée la ligne, et le
     *    matricule ne doit pas déjà exister ;
     *  - réinscription / transfert sortant -> l'élève existe : on met à jour SA ligne, et
     *    on refuse s'il occupe déjà l'année visée (une inscription par an).
     */
    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));

        AnneeScolaireGuard::assertModifiable($data['annee'] ?? null, "L'inscription d'un élève");
        $this->assertCoherence($data);

        $entrant = in_array($data['mouvement'], self::MOUVEMENTS_ENTRANTS, true);
        $existant = $this->ecrivain->parMatricule($data['matricule']);

        if ($entrant) {
            if ($existant) {
                throw ValidationException::withMessages([
                    'matricule' => [
                        "Le matricule « {$data['matricule']} » est déjà celui de "
                        ."{$existant->Nom} {$existant->Prenom}. Pour un élève déjà connu, "
                        .'choisissez « Réinscription ».',
                    ],
                ]);
            }

            if ($doublon = $this->ecrivain->memeIdentite($data)) {
                throw ValidationException::withMessages([
                    'nom' => [
                        "Un élève nommé {$doublon->Nom} {$doublon->Prenom}, né le "
                        .substr((string) $doublon->DateNaiss, 0, 10)
                        .", est déjà inscrit (matricule {$doublon->Matricule}).",
                    ],
                ]);
            }

            $code = $this->ecrivain->creer($data);

            return response()->json(Eleve::findOrFail($code), 201);
        }

        // Réinscription / transfert sortant : l'élève doit exister.
        if (! $existant) {
            throw ValidationException::withMessages([
                'matricule' => [
                    "Aucun élève ne porte le matricule « {$data['matricule']} ». "
                    .'Pour un nouvel élève, choisissez « Inscription ».',
                ],
            ]);
        }

        // Une inscription par an : il occupe déjà l'année visée.
        if (trim((string) $existant->AnneeAcad) === trim($data['annee'])) {
            throw ValidationException::withMessages([
                'annee' => [
                    "{$existant->Nom} {$existant->Prenom} est déjà inscrit pour l'année "
                    ."{$data['annee']}. Un élève ne peut l'être qu'une fois par année.",
                ],
            ]);
        }

        // Son année actuelle doit elle aussi être ouverte : on modifie bien cette ligne.
        AnneeScolaireGuard::assertModifiable($existant->AnneeAcad, 'La réinscription de cet élève');

        $this->ecrivain->modifier((int) $existant->Code, $data);

        return response()->json(Eleve::findOrFail($existant->Code), 200);
    }

    /** Contrôles de cohérence communs à la création et à la modification. */
    private function assertCoherence(array $data): void
    {
        CoherenceScolaire::assertNiveau($data['niveau_code'] ?? null, $data['cycle_code'] ?? null);
        CoherenceScolaire::assertClasse($data['classe_code'] ?? null, $data['niveau_code'] ?? null, $data['annee'] ?? null);
        CoherenceScolaire::assertDateInscription($data['date_inscription'] ?? null, $data['annee'] ?? null);

        // Un transfert entrant vient forcément de quelque part.
        if (($data['mouvement'] ?? null) === 'transfert_entrant' && trim((string) ($data['etab_origine'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'etab_origine' => ["L'établissement d'origine est requis pour un transfert entrant."],
            ]);
        }
    }

    public function update(Request $request, int $inscription)
    {
        $eleve = Eleve::findOrFail($inscription);
        $data = $request->validate($this->regles(false));

        // L'année ACTUELLE du dossier verrouille sa modification, et on n'autorise pas
        // non plus de le déplacer VERS une année clôturée.
        AnneeScolaireGuard::assertModifiable($eleve->annee, 'La modification de ce dossier');
        AnneeScolaireGuard::assertModifiable($data['annee'] ?? null, 'Le rattachement à cette année');
        $this->assertCoherence($data);

        if (! empty($data['matricule']) && $this->ecrivain->matriculeExiste($data['matricule'], $inscription)) {
            throw ValidationException::withMessages(['matricule' => ['Ce matricule est déjà attribué.']]);
        }

        $this->ecrivain->modifier((int) $eleve->getKey(), $data);

        return response()->json(Eleve::findOrFail($inscription));
    }

    /**
     * Sert la photo de l'élève depuis le dossier partagé. Le dossier n'étant pas
     * exposé par le serveur web, le fichier est renvoyé en flux par l'API.
     */
    public function photo(int $inscription)
    {
        $eleve = Eleve::findOrFail($inscription);
        $valeur = DB::connection('economat')->table('T_ETUDIANT')
            ->where('Code', $eleve->getKey())->value('Photo');

        $chemin = $this->photos->chemin($valeur);
        abort_if(! $chemin, 404, 'Aucune photo pour cet élève.');

        return response()->file($chemin);
    }

    /**
     * Téléverse la photo : le fichier va dans le dossier partagé lu par ECONOMAT,
     * et seul son nom est écrit dans T_ETUDIANT.Photo.
     */
    public function televerserPhoto(Request $request, int $inscription)
    {
        $eleve = Eleve::findOrFail($inscription);

        AnneeScolaireGuard::assertModifiable($eleve->annee, "L'ajout d'une photo");

        $request->validate([
            'photo' => [
                'required', 'file', 'image',
                'mimes:'.implode(',', $this->photos->extensionsAutorisees()),
                'max:'.$this->photos->tailleMaxKo(),
            ],
        ], [], ['photo' => 'photo']);

        $code = (int) $eleve->getKey();
        $nomBase = trim((string) $eleve->matricule) ?: ('eleve-'.$code);

        try {
            $nomFichier = $this->photos->enregistrer($request->file('photo'), $nomBase);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        DB::connection('economat')->table('T_ETUDIANT')
            ->where('Code', $code)->update(['Photo' => $nomFichier]);

        return response()->json([
            'photo' => $nomFichier,
            'message' => 'Photo enregistrée.',
        ]);
    }
}
