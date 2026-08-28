<?php

namespace App\Console\Commands;

use App\Models\Societe;
use App\Models\UsSociete;
use Illuminate\Console\Command;

/**
 * Import ponctuel des sociétés réelles de dbmasterbacou.US_SOCIETE vers la table
 * indépendante ecoprim.societes (aucune connexion live conservée après coup — voir
 * la décision d'architecture de la Console Administrative dans AVANCEMENT.md).
 * Idempotent : upsert par code, ré-exécutable sans dupliquer.
 */
class ImporterSocietesDbmasterbacou extends Command
{
    protected $signature = 'societes:importer-dbmasterbacou';

    protected $description = 'Importe les sociétés de dbmasterbacou.US_SOCIETE dans ecoprim.societes (upsert par code)';

    public function handle(): int
    {
        $lignes = UsSociete::all();

        $crees = 0;
        $mis_a_jour = 0;

        foreach ($lignes as $ligne) {
            $code = trim((string) $ligne->CODESOCIETE);

            if ($code === '') {
                continue;
            }

            $donnees = [
                'nom' => trim((string) $ligne->NOMSOCIETE) ?: $code,
                'adresse' => $this->premier($ligne->AD1SOCIETE, $ligne->ADRESSE),
                'adresse_ligne2' => $this->premier($ligne->AD2SOCIETE, $ligne->COMPLEMENTSOCIETE),
                'code_postal' => $ligne->CPSOCIETE,
                'ville' => $ligne->VILLESOCIETE,
                'pays' => $ligne->PAYSSOCIETE,
                'telephone' => $ligne->TELSOCIETE,
                'fax' => $ligne->FAXSOCIETE,
                'email' => $ligne->EMAILSOCIETE,
                'site_web' => $ligne->SITESOCIETE,
                'activite_principale' => $ligne->ACTIVITESOCIETE,
                'activite_secondaire' => $ligne->ACTIVITESECONDAIRE,
                'forme_juridique' => $ligne->FORMJURIDQIUE,
                'regime_fiscal' => $ligne->REGIMEFISCAL,
                'capital' => $ligne->CAPITAL,
                'representant_civilite' => $ligne->CIVILITEREPRESENTANT !== null ? (string) $ligne->CIVILITEREPRESENTANT : null,
                'representant_nom' => $this->premier($ligne->NOMPRENOMREPRESENTANT, $ligne->REPRESENTANT),
                'representant_fonction' => $this->premier($ligne->FONCTIONREPRESENTANT, $ligne->FONCTIONREPESENTANT),
                'representant_telephone' => $ligne->TELREPRESENTANT,
                'representant_mobile' => $ligne->CELREPRESENTANT,
            ];

            $societe = Societe::withTrashed()->where('code', $code)->first();

            if ($societe) {
                $societe->update($donnees);
                $mis_a_jour++;
            } else {
                Societe::create(['code' => $code, 'statut' => 'actif'] + $donnees);
                $crees++;
            }
        }

        $this->info("Import terminé : {$crees} société(s) créée(s), {$mis_a_jour} mise(s) à jour.");

        return self::SUCCESS;
    }

    private function premier(...$valeurs): ?string
    {
        foreach ($valeurs as $valeur) {
            $valeur = trim((string) $valeur);
            if ($valeur !== '') {
                return $valeur;
            }
        }

        return null;
    }
}
