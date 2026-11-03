<?php

namespace App\Services\Echange;

use App\Services\ReferentielEcrivain;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import des classes — ECONOMAT.T_CLASSE, par le même ReferentielEcrivain que l'écran
 * Classes, et sous ses règles :
 *   - un code de classe est unique dans T_CLASSE, TOUTES années et tous établissements
 *     confondus (clé primaire sur le serveur) ;
 *   - le niveau doit exister pour l'année (en base, ou créé par la feuille Niveaux) ;
 *   - une classe créée est rattachée à l'établissement de travail — sans quoi elle
 *     disparaîtrait de la liste aussitôt créée. Plusieurs établissements visibles, ou
 *     aucun : la création est refusée, comme à l'écran.
 *
 * Une classe qui porte déjà ce code dans un AUTRE établissement est écartée, pas
 * modifiée : un fichier de l'école A ne renomme pas une classe de l'école B.
 */
class ImportClasses extends ImportReferentiel
{
    public function __construct()
    {
        parent::__construct('T_CLASSE', 'num', [
            'code' => 'CodeClasse',
            'nom' => 'LibelleClasse',
            'niveau_code' => 'CodN',
            'annee' => 'ANNEE',
            'etablissement' => 'CODEETABLISSEMENT',
        ]);
    }

    public function verdicts(array $lignes): array
    {
        $annee = ContexteScolaire::annee();
        $existantes = $this->existantes();
        $ailleurs = $this->codesDesAutresAnnees();
        $niveaux = $this->avecAttendusDe('niveaux', $this->niveaux());
        $etablissement = $this->etablissementDeCreation();
        $vus = [];
        $verdicts = [];

        foreach ($lignes as $ligne) {
            if ($this->toutImporter) {
                $verdicts[] = $this->verdictToutImporter($ligne, $annee, $existantes, $ailleurs, $niveaux, $etablissement, $vus);

                continue;
            }

            $n = (int) $ligne['_ligne'];
            $code = $this->texte($ligne['Code'] ?? null);
            $nom = $this->texte($ligne['Libellé'] ?? null);
            $niveau = $this->texte($ligne['Niveau'] ?? null);
            $apercu = trim(($code ?? '?').' — '.($nom ?? ''));

            $manque = array_keys(array_filter(
                ['Code' => $code, 'Libellé' => $nom, 'Niveau' => $niveau],
                fn ($v) => $v === null,
            ));
            if ($manque !== []) {
                $verdicts[] = $this->rejet($n, implode(', ', $manque).' : champ obligatoire.', $apercu);

                continue;
            }

            if (isset($vus[$code])) {
                $verdicts[] = $this->rejet($n, "Classe « {$code} » déjà présente ligne {$vus[$code]} du fichier.", $apercu);

                continue;
            }
            $vus[$code] = $n;

            $d = ['code' => $code, 'nom' => $nom, 'niveau_code' => $niveau];

            $trop = $this->depassement($d, ['code' => 20, 'nom' => 60, 'niveau_code' => 20], [
                'code' => 'Code', 'nom' => 'Libellé', 'niveau_code' => 'Niveau',
            ]);
            if ($trop !== null) {
                $verdicts[] = $this->rejet($n, $trop, $apercu);

                continue;
            }

            if (! isset($niveaux[$niveau])) {
                $verdicts[] = $this->rejet($n, "Niveau « {$niveau} » inconnu pour l’année {$annee}.", $apercu);

                continue;
            }

            $existante = $existantes[$code] ?? null;

            if ($existante === null && isset($ailleurs[$code])) {
                $verdicts[] = $this->rejet(
                    $n,
                    "Le code « {$code} » est déjà pris par une classe de {$ailleurs[$code]} : un code de classe ne sert qu'une fois, toutes années confondues. Donnez-lui un autre code, par exemple « {$code}-".(AnneeImport::paire($annee)[0] ?? 'AAAA').' ».',
                    $apercu,
                );

                continue;
            }

            if ($existante !== null) {
                if (! PerimetreEtablissement::classeVisible($code)) {
                    $verdicts[] = $this->rejet($n, "La classe « {$code} » appartient à un autre établissement.", $apercu);

                    continue;
                }
                $verdicts[] = $this->modification($n, $d + ['_id' => $existante], $apercu);

                continue;
            }

            if ($etablissement === null) {
                $verdicts[] = $this->rejet(
                    $n,
                    "Choisissez d'abord, dans l'en-tête, l'établissement où ouvrir les classes.",
                    $apercu,
                );

                continue;
            }

            $verdicts[] = $this->creation($n, $d + ['annee' => $annee, 'etablissement' => $etablissement], $apercu);
        }

        return $verdicts;
    }

    /** Les niveaux à créer pour les classes de ce fichier, déjà créés pendant l'application. */
    private array $niveauxAssures = [];

    /**
     * Mode « tout importer » : une ligne de classe, rattrapée partout où c'est possible.
     *
     * Rattrapés : libellé absent (le code en tient lieu), niveau absent (déduit du code),
     * niveau inconnu de l'année (créé, repris d'une autre année s'il y existait), valeur
     * trop longue (coupée), code déjà pris par une autre année ou par un établissement
     * interdit à ce compte (classe créée sous un code libre, « CE1A-2025 », et les autres
     * feuilles du classeur y rangent leurs « CE1A »), code répété dans le fichier (mise à
     * jour de la ligne précédente).
     *
     * Restent écartés : sans code, sans niveau déductible, sans établissement de travail
     * déterminé (plusieurs établissements visibles).
     */
    private function verdictToutImporter(array $ligne, ?string $annee, array $existantes, array $ailleurs, array &$niveaux, ?string $etablissement, array &$vus): array
    {
        $n = (int) $ligne['_ligne'];
        $code = $this->texte($ligne['Code'] ?? null);
        $nom = $this->texte($ligne['Libellé'] ?? null);
        $niveau = $this->texte($ligne['Niveau'] ?? null);
        $apercu = trim(($code ?? '?').' — '.($nom ?? ''));
        $notes = [];

        if ($code === null) {
            return $this->rejet($n, 'Code absent : c’est la clé de la classe, elle ne se devine pas.', $apercu);
        }
        $origine = $code;

        if ($nom === null) {
            $nom = $code;
            $notes[] = 'libellé absent : le code en tient lieu';
        }

        if ($niveau === null) {
            $niveau = $this->niveauDevine($code, $niveaux);
            if ($niveau === null) {
                return $this->rejet($n, 'Niveau absent, et il ne se déduit pas du code de la classe : indiquez-le.', $apercu);
            }
            $notes[] = "niveau absent : « {$niveau} » déduit du code";
        }

        $d = $this->tronquer(['code' => $code, 'nom' => $nom, 'niveau_code' => $niveau], ['code' => 20, 'nom' => 60, 'niveau_code' => 20], $notes, [
            'code' => 'Code', 'nom' => 'Libellé', 'niveau_code' => 'Niveau',
        ]);
        $code = $d['code'];
        $niveau = $d['niveau_code'];
        $brut = $d;

        if (! isset($niveaux[$niveau])) {
            $d['_niveau_a_creer'] = $this->planNiveau($niveau, $annee);
            $niveaux[$niveau] = $d['_niveau_a_creer'];
            $notes[] = "niveau « {$niveau} » inconnu pour {$annee} : créé à l’import";
        } elseif (is_array($niveaux[$niveau])) {
            // Niveau qu'une ligne précédente fait créer : celle-ci le demande aussi, au cas où.
            $d['_niveau_a_creer'] = $niveaux[$niveau];
        }

        // Classe que l'import reprend déjà d'une autre année sous un code libre (voir
        // AnneeImport::completerStructure) : la ligne met à jour cette reprise.
        $reprise = $this->attendus['_alias_classes'][$code] ?? null;
        if ($reprise !== null && ! isset($existantes[$code])) {
            $notes[] = "classe « {$code} » déjà reprise d’une autre année sous « {$reprise} » : cette ligne la met à jour";
            $d['code'] = $reprise;
            $vus[$code] = $vus[$reprise] = ['ligne' => $n, 'brut' => $brut, 'donnees' => $d + ['_par_code' => $reprise]];

            return $this->ajuste($this->modification($n, $d + ['_par_code' => $reprise], $apercu), $notes);
        }

        if (isset($vus[$code])) {
            $avant = $vus[$code];
            $note = $avant['brut'] == $brut
                ? "ligne identique à la ligne {$avant['ligne']} : déjà prise en compte"
                : "même classe que la ligne {$avant['ligne']} : cette ligne la complète (la dernière valeur l’emporte)";
            $r = $avant['donnees'];

            return $this->ajuste($this->modification($n, array_intersect_key($d, ['nom' => 1, 'niveau_code' => 1, '_niveau_a_creer' => 1]) + [
                'code' => $r['code'],
                '_cle' => $r['code'],
            ] + (isset($r['_id']) ? ['_id' => $r['_id']] : [])
              + (isset($r['_par_code']) ? ['_par_code' => $r['_par_code']] : []), $apercu), [...$notes, $note]);
        }

        $existante = $existantes[$code] ?? null;
        $renommer = null;

        if ($existante !== null && ! PerimetreEtablissement::classeVisible($code)) {
            $proprietaire = $this->etablissementDe($code);
            $autorises = PerimetreEtablissement::etablissementsAutorises();
            if ($autorises === null || in_array($proprietaire, $autorises, true)) {
                $notes[] = "classe de l’établissement {$proprietaire}, autre que celui de travail : mise à jour quand même";
            } else {
                $renommer = "le code « {$code} » appartient à une classe d’un autre établissement";
                $existante = null;
            }
        } elseif ($existante === null && isset($ailleurs[$code])) {
            $renommer = "le code « {$code} » est déjà pris par une classe de {$ailleurs[$code]}";
        }

        if ($renommer !== null) {
            $pris = $existantes + $ailleurs + $vus + ($this->attendus['classes'] ?? []);
            $debut = AnneeImport::paire((string) $annee)[0] ?? (int) date('Y');
            $candidat = mb_substr($code.'-'.$debut, 0, 20);
            $nouveau = isset($pris[$candidat]) ? $this->codeLibre($candidat, $pris, fn ($c) => isset($pris[$c]), 20) : $candidat;
            $notes[] = "{$renommer} : classe créée sous « {$nouveau} », où les autres feuilles du classeur rangeront leurs « {$code} »";
            $d['code'] = $nouveau;
            $d['_alias_de'] = $origine;
            $code = $nouveau;
        } elseif ($origine !== $code) {
            $d['_alias_de'] = $origine;
        }

        if ($existante !== null) {
            $vus[$code] = ['ligne' => $n, 'brut' => $brut, 'donnees' => $d + ['_id' => $existante]];

            return $this->ajuste($this->modification($n, $d + ['_id' => $existante], $apercu), $notes);
        }

        if ($etablissement === null) {
            return $this->rejet($n, "Choisissez d'abord, dans l'en-tête, l'établissement où ouvrir les classes.", $apercu);
        }

        $vus[$code] = ['ligne' => $n, 'brut' => $brut, 'donnees' => $d];
        if ($origine !== $code) {
            $vus[$origine] = $vus[$code];
        }

        return $this->ajuste($this->creation($n, $d + ['annee' => $annee, 'etablissement' => $etablissement, '_cle' => $code], $apercu), $notes);
    }

    /** Le niveau à créer : repris d'une autre année s'il y existe (libellé, ordre), sinon son code. */
    private function planNiveau(string $code, ?string $annee): array
    {
        try {
            $modele = DB::connection('economat')->table('T_NIVEAU')->where('CodeNiveau', $code)->first(['LibelleNiveau', 'Ordre']);
        } catch (Throwable $e) {
            $modele = null;
        }

        return array_filter([
            'code' => $code,
            'libelle' => $this->texte($modele->LibelleNiveau ?? null) ?? $code,
            'ordre' => $modele->Ordre ?? null,
            'annee' => $annee,
        ], fn ($v) => $v !== null);
    }

    protected function avantEcriture(array $donnees): void
    {
        $plan = $donnees['_niveau_a_creer'] ?? null;
        if (! is_array($plan) || isset($this->niveauxAssures[$plan['code']])) {
            return;
        }

        $niveaux = new ReferentielEcrivain('T_NIVEAU', 'Num', [
            'code' => 'CodeNiveau', 'libelle' => 'LibelleNiveau', 'ordre' => 'Ordre', 'annee' => 'ANNEE',
        ]);
        $existe = $niveaux->requete()
            ->where('CodeNiveau', $plan['code'])
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
            ->exists();
        if (! $existe) {
            $niveaux->creer($plan);
        }
        $this->niveauxAssures[$plan['code']] = true;
    }

    private function etablissementDe(string $code): string
    {
        try {
            return trim((string) DB::connection('economat')->table('T_CLASSE')
                ->where('CodeClasse', $code)
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->value('CODEETABLISSEMENT'));
        } catch (Throwable $e) {
            $this->referentielInjoignable('classes', $e);
        }
    }

    /**
     * Code -> num, pour toutes les classes de l'année, TOUS établissements : c'est ainsi
     * qu'on reconnaît un code déjà pris ailleurs, que l'écran refuserait aussi.
     *
     * @return array<string, int>
     */
    private function existantes(): array
    {
        try {
            return $this->ecrivain->requete()
                ->select('num', 'CodeClasse')
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->get()
                ->mapWithKeys(fn ($l) => [trim((string) $l->CodeClasse) => $l->num])
                ->all();
        } catch (Throwable $e) {
            $this->referentielInjoignable('classes existantes', $e);
        }
    }

    /** @return array<string, string> code -> année, pour les classes des AUTRES années */
    private function codesDesAutresAnnees(): array
    {
        try {
            $variantes = ContexteScolaire::variantes();

            return $this->ecrivain->requete()
                ->select('CodeClasse', 'ANNEE')
                ->when($variantes !== [], fn ($q) => $q->where(fn ($w) => $w->whereNotIn('ANNEE', $variantes)->orWhereNull('ANNEE')))
                ->get()
                ->mapWithKeys(fn ($l) => [trim((string) $l->CodeClasse) => (string) ($l->ANNEE ?: 'une autre année')])
                ->all();
        } catch (Throwable $e) {
            $this->referentielInjoignable('classes des autres années', $e);
        }
    }

    /** @return array<string, true> */
    private function niveaux(): array
    {
        try {
            return DB::connection('economat')->table('T_NIVEAU')
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->pluck('CodeNiveau')
                ->mapWithKeys(fn ($c) => [trim((string) $c) => true])
                ->all();
        } catch (Throwable $e) {
            $this->referentielInjoignable('niveaux', $e);
        }
    }
}
