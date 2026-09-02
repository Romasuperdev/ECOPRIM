<?php

namespace App\Support;

use App\Models\AnneeScolaire;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Année scolaire de travail, choisie dans l'en-tête et conservée en session.
 *
 * Elle doit filtrer TOUTES les informations rattachées à une année. Difficulté :
 * ECONOMAT n'a pas une convention unique — certaines tables stockent le libellé
 * (T_ETUDIANT.AnneeAcad, T_CLASSE.ANNEE), d'autres le code (T_PROFESSEUR.CodeAnnee,
 * V_NOTECLASSE.CodeAnnee). Le filtre porte donc sur les DEUX formes, ce qui évite de
 * deviner table par table et reste juste si la convention diffère d'une installation
 * à l'autre.
 */
class ContexteScolaire
{
    private static ?array $cache = null;

    /** Libellé de l'année de travail (session, sinon année active). */
    public static function annee(): ?string
    {
        return self::resoudre()['libelle'];
    }

    /** Libellé et code de l'année, pour un filtre tolérant aux deux conventions. */
    public static function variantes(): array
    {
        $a = self::resoudre();

        return array_values(array_unique(array_filter([$a['libelle'], $a['code']], fn ($v) => (string) $v !== '')));
    }

    /**
     * Restreint une requête à l'année de travail.
     * Sans année connue, la requête est laissée intacte : on ne masque pas tout
     * l'applicatif parce que le référentiel est vide ou injoignable.
     */
    public static function appliquer($query, string $colonne)
    {
        $variantes = self::variantes();

        return $variantes === [] ? $query : $query->whereIn($colonne, $variantes);
    }

    public static function oublier(): void
    {
        self::$cache = null;
    }

    private static function resoudre(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $choisi = null;
        try {
            $choisi = Session::get('annee_travail');
        } catch (Throwable $e) {
            $choisi = null;
        }

        try {
            $annees = AnneeScolaire::all();
        } catch (Throwable $e) {
            return self::$cache = ['libelle' => $choisi, 'code' => null];
        }

        $trouvee = $choisi
            ? $annees->first(fn ($a) => $a->libelle === $choisi || $a->code_annee === $choisi)
            : null;

        $trouvee ??= $annees->first(fn ($a) => $a->active);

        return self::$cache = [
            'libelle' => $trouvee?->libelle ?? $choisi,
            'code' => $trouvee?->code_annee,
        ];
    }
}
