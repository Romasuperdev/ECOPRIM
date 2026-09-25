<?php

namespace App\Services\Echange;

use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Un importateur de jeu de données.
 *
 * DEUX TEMPS, UNE SEULE RÈGLE. L'analyse et l'application appellent toutes deux
 * `verdicts()` : l'analyse s'arrête là et rend le rapport, l'application exécute ce que le
 * rapport annonçait. C'est la seule façon d'être sûr que l'aperçu dit vrai — deux jeux de
 * règles parallèles auraient divergé au premier cas particulier, et l'écran aurait promis
 * 95 créations pour en faire 91.
 *
 * TROIS VERDICTS et pas un de plus : création, modification, rejet. Aucune suppression :
 * la règle d'ECONOMAT vaut ici comme partout, un import ne fait jamais disparaître une
 * ligne. Un élève absent du fichier reste inscrit — l'absence d'une ligne n'est pas une
 * instruction, c'est souvent juste un fichier partiel.
 */
abstract class Importateur
{
    public const CREATION = 'creation';

    public const MODIFICATION = 'modification';

    public const REJET = 'rejet';

    /**
     * Raison pour laquelle ce jeu ne peut pas être importé en ce moment, ou null.
     *
     * Le verrou d'année clôturée vaut pour les trois importateurs et se pose donc ici :
     * une année close ne se rouvre pas par un fichier, alors qu'elle résiste à tous les
     * écrans de saisie. Les importateurs qui ont une raison de plus l'ajoutent en
     * appelant d'abord `parent::indisponible()`.
     */
    public function indisponible(): ?string
    {
        $annee = ContexteScolaire::annee();

        return AnneeScolaireGuard::estCloturee($annee)
            ? "L'année {$annee} est clôturée : plus rien ne peut y être importé."
            : null;
    }

    /**
     * Le sort de chaque ligne, sans rien écrire.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array{ligne: int, action: string, motif: ?string, donnees: array, apercu: string}>
     */
    abstract public function verdicts(array $lignes): array;

    /**
     * Exécute les verdicts qui ne sont pas des rejets.
     *
     * @param  array<int, array>  $verdicts
     * @return array{creees: int, modifiees: int, echecs: array<int, array{ligne: int, motif: string}>}
     */
    abstract public function appliquer(array $verdicts): array;

    protected function creation(int $ligne, array $donnees, string $apercu): array
    {
        return ['ligne' => $ligne, 'action' => self::CREATION, 'motif' => null, 'donnees' => $donnees, 'apercu' => $apercu];
    }

    protected function modification(int $ligne, array $donnees, string $apercu): array
    {
        return ['ligne' => $ligne, 'action' => self::MODIFICATION, 'motif' => null, 'donnees' => $donnees, 'apercu' => $apercu];
    }

    protected function rejet(int $ligne, string $motif, string $apercu = ''): array
    {
        return ['ligne' => $ligne, 'action' => self::REJET, 'motif' => $motif, 'donnees' => [], 'apercu' => $apercu];
    }

    protected function texte($valeur): ?string
    {
        $valeur = trim((string) ($valeur ?? ''));

        return $valeur === '' ? null : $valeur;
    }

    /**
     * Une date telle qu'un tableur la rend, quelle que soit la locale de celui qui l'a
     * saisie. On essaie les formats explicitement, dans l'ordre : `Carbon::parse` seul
     * lirait « 03/04/2015 » comme le 4 mars, alors qu'ici c'est le 3 avril.
     */
    protected function date($valeur): ?string
    {
        $valeur = $this->texte($valeur);
        if ($valeur === null) {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'Y/m/d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $valeur);
                if ($date !== false && $date->format($format) === $valeur) {
                    return $date->toDateString();
                }
            } catch (Throwable $e) {
                // Format suivant.
            }
        }

        try {
            return CarbonImmutable::parse($valeur)->toDateString();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** « Oui », « 1 », « vrai », « x » -> vrai. Tout le reste -> faux. */
    protected function booleen($valeur): bool
    {
        $valeur = mb_strtolower((string) ($valeur ?? ''));

        return in_array(trim($valeur), ['1', 'oui', 'o', 'vrai', 'true', 'x', 'yes'], true);
    }

    /** Un nombre écrit à la française (« 12,5 ») comme à l'anglaise, ou null. */
    protected function nombre($valeur): ?float
    {
        $valeur = $this->texte($valeur);
        if ($valeur === null) {
            return null;
        }

        $valeur = str_replace([' ', ' ', ','], ['', '', '.'], $valeur);

        return is_numeric($valeur) ? (float) $valeur : null;
    }
}
