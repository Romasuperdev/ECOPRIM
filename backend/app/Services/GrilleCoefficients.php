<?php

namespace App\Services;

use App\Models\CoefficientMatiere;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La grille des barèmes et coefficients, résolue pour un niveau ou une classe.
 *
 * CE QU'ELLE NE FAIT PAS : calculer une moyenne. ECONOMAT s'en charge — c'est la règle du
 * projet depuis le pivot (voir RapportController) et le bulletin imprime SA moyenne et SON
 * rang. Une moyenne calculée ici en contredirait une autre sur la même page. La grille sert
 * donc à la SAISIE : elle fournit le barème et le coefficient qu'on inscrit dans
 * T_NOTEENTETE, et c'est ECONOMAT qui en tire la moyenne.
 *
 * LA PLUS PRÉCISE GAGNE. Une matière peut être décrite plusieurs fois : grille commune,
 * grille de l'établissement, grille d'une année, grille d'une classe. On ne fusionne pas,
 * on choisit — la ligne la plus précise l'emporte (CoefficientMatiere::precision()), et
 * l'on garde trace de ce qui a gagné pour que l'écran puisse signaler une surcharge.
 */
class GrilleCoefficients
{
    /**
     * La grille applicable à une classe : matiere_code -> détail.
     *
     * @return array<string, array{coefficient: float, note_max: float, bareme: float, origine: string, id: int, surcharge: bool}>
     */
    public function pourClasse(string $classeCode, ?string $etablissement = null, ?string $annee = null): array
    {
        return $this->resoudre($this->niveauDe($classeCode), $classeCode, $etablissement, $annee);
    }

    /** La grille applicable à un niveau, sans descendre à une classe précise. */
    public function pourNiveau(string $niveauCode, ?string $etablissement = null, ?string $annee = null): array
    {
        return $this->resoudre($niveauCode, null, $etablissement, $annee);
    }

    /**
     * Le barème d'une matière pour une classe, ou null si la grille ne dit rien.
     * Null et non 20 : c'est à l'appelant de décider de sa valeur par défaut, et de la
     * nommer. Un 20 rendu ici passerait pour une grille alors qu'il n'y en a pas.
     */
    public function baremeDe(string $classeCode, string $matiereCode, ?string $annee = null): ?float
    {
        return $this->pourClasse($classeCode, null, $annee)[$matiereCode]['bareme'] ?? null;
    }

    /** Le coefficient d'une matière pour une classe, ou null. */
    public function coefficientDe(string $classeCode, string $matiereCode, ?string $annee = null): ?float
    {
        return $this->pourClasse($classeCode, null, $annee)[$matiereCode]['coefficient'] ?? null;
    }

    /**
     * @return array<string, array{coefficient: float, note_max: float, bareme: float, origine: string, id: int, surcharge: bool}>
     */
    private function resoudre(?string $niveau, ?string $classe, ?string $etablissement, ?string $annee): array
    {
        $etablissement ??= PerimetreEtablissement::code();
        $annee ??= ContexteScolaire::annee();

        $lignes = $this->candidates($niveau, $classe, $etablissement, $annee);

        $retenues = [];
        foreach ($lignes as $ligne) {
            $matiere = $ligne->matiere_code;
            $actuelle = $retenues[$matiere] ?? null;

            // À précision égale, la dernière écrite gagne : c'est la correction la plus
            // récente, et l'index unique garantit qu'il n'y en a pas deux identiques.
            if ($actuelle === null
                || $ligne->precision() > $actuelle->precision()
                || ($ligne->precision() === $actuelle->precision() && $ligne->getKey() > $actuelle->getKey())) {
                $retenues[$matiere] = $ligne;
            }
        }

        $grille = [];
        foreach ($retenues as $matiere => $ligne) {
            $grille[$matiere] = [
                'id' => (int) $ligne->getKey(),
                'coefficient' => (float) $ligne->coefficient,
                // `note_max` tel qu'il a été réglé (souvent null), `bareme` celui qui
                // s'applique vraiment. Les confondre masquerait qu'une case n'a pas de
                // barème propre.
                'note_max' => $ligne->note_max !== null ? (float) $ligne->note_max : null,
                'bareme' => $ligne->bareme(),
                'origine' => $this->origine($ligne),
                'surcharge' => ! $ligne->estDefaut(),
            ];
        }

        ksort($grille);

        return $grille;
    }

    /**
     * Les lignes qui pourraient s'appliquer. Une seule requête : on trie en mémoire, car
     * départager sur la précision demande de les avoir toutes, et une grille d'école tient
     * en quelques dizaines de lignes.
     *
     * @return \Illuminate\Support\Collection<int, CoefficientMatiere>
     */
    private function candidates(?string $niveau, ?string $classe, ?string $etablissement, ?string $annee)
    {
        try {
            return CoefficientMatiere::query()
                ->where('actif', true)
                // Portée : la grille du niveau, et celle de cette classe précise.
                ->where(function ($q) use ($niveau, $classe) {
                    $q->where(fn ($w) => $w->whereNull('classe_code')->whereNull('niveau_code'));
                    if ($niveau !== null) {
                        $q->orWhere(fn ($w) => $w->where('niveau_code', $niveau)->whereNull('classe_code'));
                    }
                    if ($classe !== null) {
                        $q->orWhere('classe_code', $classe);
                    }
                })
                // Établissement : le sien, ou la grille commune.
                ->where(fn ($q) => $q->whereNull('etablissement_code')
                    ->when($etablissement !== null, fn ($w) => $w->orWhere('etablissement_code', $etablissement)))
                // Année : ses DEUX écritures, ou « toutes années ». ECONOMAT note l'année
                // tantôt en code, tantôt en libellé — d'où variantesDe(), comme ailleurs.
                ->where(fn ($q) => $q->whereNull('annee')
                    ->when($annee !== null, fn ($w) => $w->orWhereIn('annee', ContexteScolaire::variantesDe($annee))))
                ->get();
        } catch (Throwable $e) {
            // Table absente (migrations non passées) : pas de grille, pas d'erreur. La
            // saisie doit continuer de fonctionner sans elle, avec ses valeurs par défaut.
            return collect();
        }
    }

    private function origine(CoefficientMatiere $ligne): string
    {
        if ($ligne->classe_code !== null) {
            return 'classe';
        }
        if ($ligne->etablissement_code !== null) {
            return $ligne->annee !== null ? 'etablissement_annee' : 'etablissement';
        }

        return $ligne->annee !== null ? 'annee' : 'defaut';
    }

    /** Le niveau d'une classe, lu dans ECONOMAT (T_CLASSE.CodN). */
    public function niveauDe(string $classeCode): ?string
    {
        try {
            $niveau = DB::connection('economat')->table('T_CLASSE')
                ->where('CodeClasse', $classeCode)
                ->value('CodN');

            return $niveau !== null && trim((string) $niveau) !== '' ? trim((string) $niveau) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
