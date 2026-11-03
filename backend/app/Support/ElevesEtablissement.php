<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Appartenance d'élèves à un établissement, pour le rattachement des enfants d'un compte
 * Parent (console_affectation_eleves).
 *
 * Il ne suffit pas qu'un matricule EXISTE : rattacher l'élève d'un autre établissement —
 * voire d'une autre société — à un compte Parent ouvrirait à ce compte la fiche, les
 * notes et les absences d'un enfant qui n'est pas de son ressort. T_ETUDIANT n'a pas de
 * colonne établissement : l'appartenance passe par sa classe (T_ETUDIANT.CodeClasse →
 * T_CLASSE.CODEETABLISSEMENT), toutes deux bornées à l'année de travail — un code de
 * classe se réemploie d'une année sur l'autre, un élève change d'école.
 */
class ElevesEtablissement
{
    /**
     * Refuse (422 sur `$champ`) tout matricule dont l'élève n'est pas, cette année, dans
     * une classe de `$etablissementCode`. Lecture impossible : 503, jamais « accepté ».
     */
    public static function exiger(array $matricules, ?string $etablissementCode, string $champ = 'eleves'): void
    {
        $matricules = collect($matricules)->map(fn ($m) => trim((string) $m))->filter()->unique()->values()->all();
        if ($matricules === []) {
            return;
        }

        $hors = self::horsEtablissement($matricules, trim((string) $etablissementCode));
        if ($hors !== []) {
            throw ValidationException::withMessages([
                $champ => [
                    (count($hors) > 1 ? 'Ces élèves ne sont pas inscrits' : 'Cet élève n\'est pas inscrit')
                    .' cette année dans une classe de cet établissement : '.implode(', ', $hors).'.',
                ],
            ]);
        }
    }

    /** Matricules (parmi ceux donnés) qui n'appartiennent pas à l'établissement. */
    public static function horsEtablissement(array $matricules, string $etablissementCode): array
    {
        if ($etablissementCode === '') {
            return array_values($matricules);
        }

        try {
            $requete = DB::connection('economat')->table('T_ETUDIANT as e')
                ->join('T_CLASSE as c', 'c.CodeClasse', '=', 'e.CodeClasse')
                ->whereIn('e.Matricule', $matricules)
                ->where('c.CODEETABLISSEMENT', $etablissementCode);
            ContexteScolaire::appliquer($requete, 'c.ANNEE');
            ContexteScolaire::appliquer($requete, 'e.AnneeAcad');

            $dedans = $requete->distinct()->pluck('e.Matricule')
                ->map(fn ($m) => trim((string) $m))->all();
        } catch (Throwable $e) {
            throw new HttpException(503,
                "Le référentiel des élèves ne répond pas : impossible de vérifier l'établissement de ces élèves. Réessayez plus tard.");
        }

        return array_values(array_diff($matricules, $dedans));
    }
}
