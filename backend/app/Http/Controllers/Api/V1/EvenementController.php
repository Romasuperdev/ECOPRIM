<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Evenement;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Calendrier scolaire — congés/vacances, réunions parents-professeurs, sorties et
 * activités pédagogiques, réunis dans une seule table (`App\Models\Evenement`,
 * connexion `ecoprim`) plutôt que quatre quasi identiques : même forme (titre, période,
 * lieu éventuel), seul le type change.
 *
 * `classe_code` pointe vers ECONOMAT en lecture seule ; nul pour un événement qui
 * concerne tout l'établissement. Donnée propre à NEXORA : suppression réelle.
 */
class EvenementController extends Controller
{
    private const LIBELLES_TYPE = [
        'vacances' => 'Congés / Vacances scolaires',
        'reunion' => 'Réunion parents-professeurs',
        'sortie' => 'Sortie pédagogique',
        'activite' => 'Activité scolaire',
    ];

    public function referentiels()
    {
        $annee = ContexteScolaire::annee();

        return [
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'types' => collect(self::LIBELLES_TYPE)
                ->map(fn ($libelle, $code) => ['code' => $code, 'libelle' => $libelle])
                ->values()->all(),
            'classes' => $this->lire('T_CLASSE', 'LibelleClasse', fn ($l) => [
                'code' => trim((string) $l->CodeClasse), 'libelle' => $l->LibelleClasse ?: $l->CodeClasse,
            ], 'ANNEE', fn ($l) => PerimetreEtablissement::classeVisible($l->CodeClasse ?? null)),
        ];
    }

    /**
     * @param  string|null  $colonneAnnee  Borne l'année quand la table la porte.
     * @param  callable|null  $garder  Filtre les lignes lues (périmètre établissement).
     */
    private function lire(string $table, string $tri, callable $projection, ?string $colonneAnnee = null, ?callable $garder = null): array
    {
        try {
            return DB::connection('economat')->table($table)
                ->when($colonneAnnee, fn ($q) => ContexteScolaire::appliquer($q, $colonneAnnee))
                ->orderBy($tri)->get()
                // Pas de ->when($garder) : une fermeture y serait prise pour la condition.
                ->pipe(fn ($lignes) => $garder ? $lignes->filter($garder) : $lignes)
                ->map($projection)->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Événements de l'année. La table n'a pas de colonne établissement : un événement
     * de classe suit sa classe ; un événement sans classe (« tout l'établissement ») ne
     * peut être rattaché à aucun, il reste donc visible — sauf pour une société sans
     * établissement, qui n'a rien à montrer.
     */
    public function index(Request $request)
    {
        $annee = ContexteScolaire::annee();

        if (PerimetreEtablissement::rienAMontrer()) {
            return collect();
        }
        $codes = PerimetreEtablissement::codesClasses();

        return Evenement::query()
            ->when($annee, fn ($q) => $q->where('annee', $annee))
            ->when($codes !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('classe_code')->orWhereIn('classe_code', $codes)
            ))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('date_debut')
            ->get()
            ->map(fn ($e) => $this->ligne($e))
            ->values();
    }

    private function ligne(Evenement $e): array
    {
        return [
            'id' => $e->id,
            'titre' => $e->titre,
            'type' => $e->type,
            'type_libelle' => self::LIBELLES_TYPE[$e->type] ?? $e->type,
            'description' => $e->description,
            'date_debut' => optional($e->date_debut)->format('Y-m-d'),
            'date_fin' => optional($e->date_fin)->format('Y-m-d'),
            'lieu' => $e->lieu,
            'classe' => $e->classe_code,
            'classe_libelle' => $e->classe_code
                ? $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $e->classe_code)
                : null,
            'annee' => $e->annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($e->annee),
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

    /** 404 pour un événement que la liste ne montrerait pas (voir index). */
    private function exigerVisible(Evenement $evenement): void
    {
        abort_if(PerimetreEtablissement::rienAMontrer(), 404, 'Événement introuvable.');
        if ($evenement->classe_code) {
            PerimetreEtablissement::exigerClasse($evenement->classe_code, 'Événement introuvable.');
        }
    }

    private function regles(): array
    {
        return [
            'titre' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', Rule::in(Evenement::TYPES)],
            'description' => ['nullable', 'string'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'lieu' => ['nullable', 'string', 'max:150'],
            'classe' => ['nullable', 'string', 'max:50', Rule::exists('economat.T_CLASSE', 'CodeClasse')],
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->regles());
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La création d'un événement");

        $evenement = Evenement::create([
            'titre' => $d['titre'], 'type' => $d['type'], 'description' => $d['description'] ?? null,
            'date_debut' => $d['date_debut'], 'date_fin' => $d['date_fin'],
            'lieu' => $d['lieu'] ?? null, 'classe_code' => $d['classe'] ?? null, 'annee' => $annee,
        ]);

        return response()->json($this->ligne($evenement), 201);
    }

    public function update(Request $request, Evenement $evenement)
    {
        $this->exigerVisible($evenement);
        $d = $request->validate($this->regles());
        AnneeScolaireGuard::assertModifiable($evenement->annee, 'La modification de cet événement');

        $evenement->update([
            'titre' => $d['titre'], 'type' => $d['type'], 'description' => $d['description'] ?? null,
            'date_debut' => $d['date_debut'], 'date_fin' => $d['date_fin'],
            'lieu' => $d['lieu'] ?? null, 'classe_code' => $d['classe'] ?? null,
        ]);

        return response()->json($this->ligne($evenement->refresh()));
    }

    public function destroy(Evenement $evenement)
    {
        $this->exigerVisible($evenement);
        AnneeScolaireGuard::assertModifiable($evenement->annee, 'La suppression de cet événement');
        $evenement->delete();

        return response()->noContent();
    }
}
