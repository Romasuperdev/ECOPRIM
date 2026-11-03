<?php

namespace App\Support;

use App\Services\CorProfClasseEcrivain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Les classes où un enseignant donne cours, saisies depuis sa fiche.
 *
 * Une classe cochée devient une affectation (ECONOMAT.T_CORPROFCLASSE) pour chacune des
 * matières enseignées par l'enseignant — la même table que l'écran « Affectation
 * enseignant – classe », dont les règles valent ici aussi :
 *   — une matière n'a qu'un enseignant par classe et par année : si elle est déjà
 *     assurée par un autre, on ne l'écrase pas, on le signale ;
 *   — décocher une classe retire les affectations de l'enseignant dans cette classe,
 *     sauf si des créneaux ou des notes en dépendent (DependancesAffectation).
 *
 * Elle porte aussi l'ÉTABLISSEMENT de l'enseignant (T_PROFESSEUR.Site) : T_PROFESSEUR
 * n'a pas de colonne établissement, et un enseignant créé sans classe restait invisible
 * de la liste, qui ne le retrouvait que par ses affectations.
 */
class ClassesEnseignant
{
    private static ?bool $colonneSite = null;

    public function __construct(private CorProfClasseEcrivain $affectations) {}

    /** La colonne Site existe-t-elle sur cette base ? (absente des bases anciennes) */
    public static function siteDisponible(): bool
    {
        if (self::$colonneSite !== null) {
            return self::$colonneSite;
        }
        try {
            return self::$colonneSite = Schema::connection('economat')->hasColumn('T_PROFESSEUR', 'Site');
        } catch (Throwable $e) {
            return self::$colonneSite = false;
        }
    }

    public static function oublier(): void
    {
        self::$colonneSite = null;
    }

    /**
     * Rattache l'enseignant à l'établissement de travail — ou à celui qu'on désigne —,
     * s'il n'en a pas encore.
     */
    public static function rattacher(int $code, ?string $etab = null): void
    {
        $etab ??= PerimetreEtablissement::code();
        if (! $etab || ! self::siteDisponible()) {
            return;
        }
        DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $code)
            ->where(fn ($q) => $q->whereNull('Site')->orWhere('Site', ''))
            ->update(['Site' => $etab]);
    }

    /**
     * Refuse, AVANT toute écriture, des classes inconnues, hors de l'établissement de
     * travail, ou cochées sans matière : l'enseignant ne doit pas être créé à moitié.
     */
    public function verifier(array $classes, array $matieres): void
    {
        $classes = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $classes)));
        if ($classes === []) {
            return;
        }
        if (array_filter($matieres) === []) {
            throw ValidationException::withMessages(['classes' => [
                "Cochez d'abord les matières enseignées : chaque classe est affectée pour ces matières.",
            ]]);
        }
        foreach ($classes as $classe) {
            $connue = DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', $classe)->exists();
            if (! $connue || ! PerimetreEtablissement::classeVisible($classe)) {
                throw ValidationException::withMessages(['classes' => [
                    "La classe « {$classe} » n'existe pas dans l'établissement de travail.",
                ]]);
            }
        }
    }

    /** Codes des classes où l'enseignant est affecté pour l'année de travail. */
    public function classesDe(int $code): array
    {
        try {
            $q = $this->affectations->requete()->where('CodeProfesseur', $code);
            ContexteScolaire::appliquer($q, 'ANNEE');

            return $q->distinct()->pluck('CodeClasse')->map(fn ($c) => trim((string) $c))->filter()->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Aligne les affectations de l'enseignant sur la liste de classes reçue.
     *
     * @return string[] avertissements (matières déjà assurées par un autre enseignant)
     */
    public function synchroniser(int $code, array $classes, array $matieres): array
    {
        $annee = ContexteScolaire::annee();
        $classes = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $classes))));
        $actuelles = $this->classesDe($code);
        $ajoutees = array_diff($classes, $actuelles);
        $retirees = array_diff($actuelles, $classes);

        $this->verifier($ajoutees, $matieres);

        $avertissements = [];

        foreach ($ajoutees as $classe) {
            foreach ($matieres as $matiere) {
                $existante = $this->affectations->pourClasseEtMatiere($classe, $matiere, $annee);
                if ($existante) {
                    if ((int) $existante->CodeProfesseur !== $code) {
                        $avertissements[] = $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $matiere)
                            .' en '.$this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $classe)
                            .' est déjà assurée par '.$this->nomProfesseur($existante->CodeProfesseur)
                            .' : affectation non modifiée.';
                    }
                    continue;
                }
                $this->affectations->creer([
                    'classe' => $classe, 'matiere' => $matiere, 'enseignant' => $code,
                    'annee' => $annee, 'principale' => false,
                ]);
            }
        }

        foreach ($retirees as $classe) {
            $q = $this->affectations->requete()->where('CodeProfesseur', $code)->where('CodeClasse', $classe);
            ContexteScolaire::appliquer($q, 'ANNEE');
            foreach ($q->get() as $ligne) {
                AnneeScolaireGuard::assertModifiable($ligne->ANNEE, 'Le retrait de cette classe');
                DependancesAffectation::assertRetraitPossible($ligne->CodeClasse ?? null, $ligne->CodeMatiere ?? null, $ligne->ANNEE ?? null);
                $this->affectations->supprimer((int) $ligne->Code);
            }
        }

        return $avertissements;
    }

    private function libelle(string $table, string $cle, string $colonne, string $code): string
    {
        try {
            return (string) (DB::connection('economat')->table($table)->where($cle, $code)->value($colonne) ?: $code);
        } catch (Throwable $e) {
            return $code;
        }
    }

    private function nomProfesseur($code): string
    {
        try {
            $p = DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $code)->first(['NomProfesseur', 'PrenomProfesseur']);

            return $p ? trim($p->NomProfesseur.' '.$p->PrenomProfesseur) : 'un autre enseignant';
        } catch (Throwable $e) {
            return 'un autre enseignant';
        }
    }
}
