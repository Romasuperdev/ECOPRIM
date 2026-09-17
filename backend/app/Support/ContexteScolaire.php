<?php

namespace App\Support;

use App\Models\AnneeScolaire;
use Carbon\CarbonImmutable;
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
     * Mêmes deux formes, mais pour une année DÉSIGNÉE (par son libellé ou par son
     * code), et non pour l'année de travail.
     *
     * À utiliser dès qu'un filtre reçoit une année de l'extérieur. Une égalité
     * stricte sur la valeur reçue paraît suffisante et ne l'est pas : elle ne
     * retrouve que les lignes stockées dans la même convention que celle qui a été
     * transmise. Le filtre « année » des inscriptions faisait exactement cela, et
     * rendait une liste VIDE sur deux des trois années du référentiel.
     *
     * Si l'année n'est pas au référentiel, on la renvoie telle quelle : mieux vaut
     * chercher ce qui a été demandé que de ne rien filtrer du tout.
     */
    public static function variantesDe(?string $annee): array
    {
        $annee = trim((string) $annee);
        if ($annee === '') {
            return [];
        }

        try {
            $trouvee = AnneeScolaire::all()->first(
                fn ($a) => (string) $a->libelle === $annee || (string) $a->code_annee === $annee
            );
        } catch (Throwable $e) {
            return [$annee];
        }

        if ($trouvee === null) {
            return [$annee];
        }

        return array_values(array_unique(array_filter(
            [$trouvee->libelle, $trouvee->code_annee],
            fn ($v) => (string) $v !== ''
        )));
    }

    /**
     * Nombre de jours ouvrés écoulés depuis le début de l'année de travail, arrêté à
     * aujourd'hui (ou à la fin de l'année si elle est passée). `null` si l'année n'a pas
     * de date de début, ou n'a pas encore commencé.
     *
     * C'est le dénominateur de tout taux d'assiduité : sans lui, on compare un cumul
     * d'absences à un effectif, ce qui n'a pas de sens — l'erreur que faisait la première
     * version du tableau de bord. Les vacances ne sont pas déduites : elles gonflent
     * légèrement le dénominateur, donc le taux. Les écrans affichent le nombre de jours
     * à côté du pourcentage pour que la lecture reste vérifiable.
     */
    public static function joursOuvresEcoules(): ?int
    {
        try {
            $variantes = self::variantes();
            $annee = AnneeScolaire::all()->first(
                fn ($a) => in_array($a->libelle, $variantes, true) || in_array($a->code_annee, $variantes, true)
            );
        } catch (Throwable $e) {
            return null;
        }

        if (! $annee?->date_debut) {
            return null;
        }

        $debut = CarbonImmutable::parse($annee->date_debut);
        $fin = $annee->date_fin ? CarbonImmutable::parse($annee->date_fin) : null;
        $aujourdhui = CarbonImmutable::now();
        $terme = ($fin !== null && $fin->lessThan($aujourdhui)) ? $fin : $aujourdhui;

        if ($terme->lessThan($debut)) {
            return null; // Année pas encore commencée.
        }

        $jours = 0;
        for ($jour = $debut; $jour->lessThanOrEqualTo($terme); $jour = $jour->addDay()) {
            if (! $jour->isWeekend()) {
                $jours++;
            }
        }

        return $jours ?: null;
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
