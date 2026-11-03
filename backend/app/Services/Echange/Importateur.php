<?php

namespace App\Services\Echange;

use App\Services\ReferentielEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
     * Ce que les feuilles précédentes du même classeur vont créer : jeu -> code -> valeur.
     * `classes` et `niveaux` -> true, `eleves` -> matricule -> classe.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $attendus = [];

    /**
     * « Tout importer » (le mode par défaut) : une ligne n'est écartée que si l'écrire est
     * réellement impossible. Ce qui se rattrape l'est (valeur trop longue coupée, date
     * illisible laissée vide, classe inconnue créée, doublon du fichier traité comme une
     * mise à jour…), et la ligne garde son verdict de création ou de modification avec, en
     * `motif`, ce qui a été ajusté. Faux : mode strict, la moindre anomalie écarte la ligne.
     */
    protected bool $toutImporter = true;

    /** @var array<string, array> code de classe -> résolution (voir resoudreClasse) */
    private array $resolutionsClasses = [];

    /** @var array<string, true>|null niveaux de l'année visée, lus une fois */
    private ?array $niveauxDeLAnnee = null;

    public function toutImporter(bool $oui = true): static
    {
        $this->toutImporter = $oui;

        return $this;
    }

    /**
     * Les classes que ces verdicts créeront à l'application (« tout importer »), code ->
     * niveau : ServiceImport les fait connaître aux feuilles suivantes du classeur.
     *
     * @return array<string, string>
     */
    public static function classesCreeesPar(array $verdicts): array
    {
        $codes = [];
        foreach ($verdicts as $v) {
            $plan = $v['donnees']['_classe_a_creer'] ?? null;
            if ($v['action'] !== self::REJET && is_array($plan) && isset($plan['code'])) {
                $codes[$plan['code']] = (string) ($plan['niveau_code'] ?? '');
            }
        }

        return $codes;
    }

    /**
     * Un classeur d'année neuve crée ses niveaux, puis ses classes, puis ses élèves : chaque
     * feuille doit être jugée sur ce que les précédentes vont écrire, pas seulement sur ce
     * qui est déjà en base — sinon toutes ses lignes seraient écartées pour « inconnu ».
     */
    public function avecAttendus(array $attendus): static
    {
        $this->attendus = $attendus;

        return $this;
    }

    /**
     * L'établissement auquel rattacher ce que l'import crée (classe, enseignant) : celui
     * de travail s'il est unique, sinon null — comme ClasseController. Sans rattachement,
     * une classe ou un enseignant sans classe disparaîtrait de l'écran aussitôt créé.
     */
    protected function etablissementDeCreation(): ?string
    {
        $visibles = PerimetreEtablissement::etablissementsVisibles();

        return is_array($visibles) && count($visibles) === 1 ? (string) $visibles[0] : null;
    }

    /**
     * Ce qu'on ajoute au rejet d'une classe inconnue pour qu'il se corrige sans chercher.
     * On SUGGÈRE, on ne corrige pas : « CM1 » désigne la seule classe du niveau CM1
     * aujourd'hui, et une seconde le rendrait ambigu demain — sans que personne le voie.
     *
     * @param  array<string, string|true>  $classes  code -> niveau (true : créée par le classeur)
     */
    protected function suggestionClasse(string $saisie, array $classes): string
    {
        $norme = fn ($v) => preg_replace('/[\s._-]+/u', '', mb_strtolower(trim((string) $v)));
        $cible = $norme($saisie);

        // Le même code écrit autrement (« cm1a »), ou le niveau d'une classe (« CM1 »).
        $candidats = array_keys(array_filter(
            $classes,
            fn ($niveau, $code) => $norme($code) === $cible || (is_string($niveau) && $niveau !== '' && $norme($niveau) === $cible),
            ARRAY_FILTER_USE_BOTH,
        ));

        if (count($candidats) === 1) {
            return " Vouliez-vous dire « {$candidats[0]} » ? La colonne Classe attend le code de la classe, pas son niveau.";
        }
        if ($candidats !== []) {
            return ' Plusieurs classes y répondent : '.implode(', ', $candidats).' — indiquez laquelle.';
        }

        $codes = array_map('strval', array_keys($classes));
        sort($codes);

        return $codes === []
            ? ' Aucune classe n’est ouverte pour cette année : créez-les depuis l’écran Classes, ou dans la feuille Classes du classeur.'
            : ' Classes de l’année : '.implode(', ', array_slice($codes, 0, 15)).(count($codes) > 15 ? '…' : '').'.';
    }

    /** Codes d'un référentiel : ceux de la base, plus ceux qu'une feuille précédente crée. */
    protected function avecAttendusDe(string $jeu, array $connus): array
    {
        return $connus + array_fill_keys(array_keys($this->attendus[$jeu] ?? []), true);
    }

    /**
     * Raison pour laquelle ce jeu ne peut pas être importé en ce moment, ou null.
     *
     * Le verrou d’année clôturée vaut pour tous les importateurs et se pose donc ici.
     * Une année close résiste à tous les écrans de saisie ; seul l'import peut encore y
     * ranger un historique, et seulement sous la permission AnneeImport::PERMISSION
     * (administrateurs d'office). Les importateurs qui ont une raison de plus l'ajoutent
     * en appelant d'abord `parent::indisponible()`.
     */
    public function indisponible(): ?string
    {
        $annee = ContexteScolaire::annee();

        return match (AnneeScolaireGuard::verdict($annee)) {
            true => AnneeImport::autorise()
                ? null
                : "L'année {$annee} est clôturée : seul un administrateur, ou un rôle ayant la permission « Importer dans une année clôturée ou à créer », peut encore y importer.",
            // Verrou invérifiable : on n'importe pas sur une incertitude.
            null => "Impossible de vérifier si l'année {$annee} est clôturée : import refusé par prudence. Réessayez dans un instant.",
            default => null,
        };
    }

    /**
     * Une lecture dont dépendent les verdicts (classes connues, matricules existants…) a
     * échoué : on REFUSE l'analyse. La traiter comme « rien trouvé » fabriquait des
     * doublons (matricules existants vides = tout en création) ou sautait un contrôle
     * (classes inconnues = aucune vérification) — et le rapport l'aurait annoncé sereinement.
     */
    protected function referentielInjoignable(string $quoi, Throwable $e): never
    {
        Log::warning("Import : {$quoi} illisible, analyse refusée.", ['exception' => $e]);

        throw new RuntimeException(
            "Référentiel injoignable ({$quoi}) : l'analyse est refusée plutôt que faite à l'aveugle. Réessayez dans un instant."
        );
    }

    /**
     * Motif d'échec d'une ligne, montrable à l'écran : le message d'une exception MÉTIER
     * (levée volontairement), sinon un texte générique — le détail technique (texte SQL,
     * noms de tables) part au journal.
     */
    protected function motifEchec(Throwable $e, int $ligne): string
    {
        if ($e instanceof ValidationException) {
            return (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }
        if ($e instanceof HttpExceptionInterface
            || ($e instanceof RuntimeException && ! $e instanceof PDOException)) {
            return $e->getMessage();
        }

        Log::error("Import : ligne {$ligne} non écrite.", ['exception' => $e]);

        return "La base a refusé l'écriture de cette ligne. Le détail a été journalisé : réessayez, ou prévenez l'administrateur.";
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

    /**
     * Une valeur plus longue que sa colonne est REFUSÉE, pas tronquée : SQL Server
     * rejetterait la ligne, et tronquer en silence l'abîmerait sans que personne le voie.
     *
     * @param  array<string, int>  $largeurs  champ -> largeur
     * @param  array<string, string>  $libelles  champ -> en-tête du fichier
     */
    protected function depassement(array $d, array $largeurs, array $libelles = []): ?string
    {
        foreach ($largeurs as $champ => $largeur) {
            $valeur = $d[$champ] ?? null;
            if (is_string($valeur) && mb_strlen($valeur) > $largeur) {
                $nom = $libelles[$champ] ?? $champ;

                return "« {$nom} » dépasse {$largeur} caractères (".mb_strlen($valeur).').';
            }
        }

        return null;
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

    // --- « Tout importer » : rattrapages partagés par tous les importateurs -------------

    /**
     * Pose sur un verdict de création ou de modification ce qui a été ajusté pour que la
     * ligne passe. Le rapport le montre : une ligne n'est jamais rattrapée en silence.
     *
     * @param  array<int, ?string>  $notes
     */
    protected function ajuste(array $verdict, array $notes): array
    {
        $notes = array_values(array_unique(array_filter($notes)));
        if ($notes !== [] && $verdict['action'] !== self::REJET) {
            $deja = $verdict['motif'] ?? null;
            $verdict['motif'] = implode(' ; ', array_filter([$deja, ...$notes]));
        }

        return $verdict;
    }

    /**
     * Les valeurs plus longues que leur colonne, coupées à sa largeur — et dit. La base
     * refuserait la ligne entière pour un caractère de trop.
     *
     * @param  array<string, int>  $largeurs  champ -> largeur
     * @param  array<string, string>  $libelles  champ -> en-tête du fichier
     */
    protected function tronquer(array $d, array $largeurs, array &$notes, array $libelles = []): array
    {
        foreach ($largeurs as $champ => $largeur) {
            $valeur = $d[$champ] ?? null;
            if (is_string($valeur) && mb_strlen($valeur) > $largeur) {
                $d[$champ] = rtrim(mb_substr($valeur, 0, $largeur));
                $nom = $libelles[$champ] ?? $champ;
                $notes[] = "« {$nom} » coupé à {$largeur} caractères (il en comptait ".mb_strlen($valeur).')';
            }
        }

        return $d;
    }

    /** Comparaison sans casse, sans accents ni ponctuation (« composition » = « Composition »). */
    protected function normaliser(?string $texte): string
    {
        $texte = mb_strtolower(trim((string) $texte));
        $sans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $sans !== false ? $sans : $texte) ?? $texte);
    }

    /**
     * Le prochain code libre : « X-2 », « X-3 »… — libre dans le fichier ($pris) et selon
     * `$enBase` (qui dit si un candidat est déjà pris en base).
     *
     * @param  array<string, mixed>  $pris
     */
    protected function codeLibre(string $origine, array $pris, callable $enBase, int $largeur = 50, bool $essayerOrigine = false): string
    {
        for ($i = $essayerOrigine ? 1 : 2; $i < 1000; $i++) {
            $suffixe = $i === 1 ? '' : "-{$i}";
            $candidat = mb_substr($origine, 0, $largeur - mb_strlen($suffixe)).$suffixe;
            if (! isset($pris[$candidat]) && ! $enBase($candidat)) {
                return $candidat;
            }
        }

        return mb_substr($origine, 0, $largeur - 7).'-'.bin2hex(random_bytes(3));
    }

    /**
     * Ce qu'on peut faire, en mode « tout importer », d'un code de classe que l'année visée
     * ne connaît pas — une fois que ServiceImport a déjà repris, via AnneeImport, celles
     * qu'un établissement visible avait ouvertes une autre année.
     *
     *   - ouverte cette année dans un établissement que le compte a le droit de voir : on
     *     l'utilise, et c'est dit ;
     *   - portée par un établissement interdit à ce compte, ou une autre année hors de son
     *     périmètre : écartée — un code de classe est unique dans T_CLASSE, et l'emprunter
     *     mêlerait les élèves de deux écoles ;
     *   - inconnue partout : créée dans l'établissement de travail à l'APPLICATION, par le
     *     même écrivain que l'écran Classes, sur le niveau donné par le fichier ou deviné
     *     d'après son code (« CE1 B » -> CE1). Sans niveau sûr, elle reste écartée : une
     *     classe sans niveau n'a ni grille ni bulletin.
     *
     * @return array{motif: ?string, plan: ?array, note: ?string}  motif non nul = rejet
     */
    protected function resoudreClasse(string $code, ?string $niveau = null, bool $copieSiPrise = true): array
    {
        if (isset($this->resolutionsClasses[$code])) {
            return $this->resolutionsClasses[$code];
        }

        $annee = ContexteScolaire::annee();
        $variantes = array_map(fn ($v) => trim((string) $v), ContexteScolaire::variantes());

        try {
            $lignes = DB::connection('economat')->table('T_CLASSE')
                ->where('CodeClasse', $code)
                ->get(['CodeClasse', 'ANNEE', 'CODEETABLISSEMENT']);
            $autorises = PerimetreEtablissement::etablissementsAutorises();
        } catch (Throwable $e) {
            $this->referentielInjoignable('classes', $e);
        }

        $etab = fn ($c) => trim((string) ($c->CODEETABLISSEMENT ?? ''));

        if ($lignes->isNotEmpty()) {
            $cetteAnnee = $lignes->filter(fn ($c) => in_array(trim((string) $c->ANNEE), $variantes, true));
            foreach ($cetteAnnee as $c) {
                if ($autorises === null || in_array($etab($c), $autorises, true)) {
                    return $this->resolutionsClasses[$code] = [
                        'motif' => null, 'plan' => null,
                        'note' => "classe « {$code} » de l’établissement {$etab($c)}, autre que celui de travail",
                    ];
                }
            }

            $ou = implode(', ', array_unique(array_filter($lignes->map(fn ($c) => trim((string) $c->ANNEE).' / '.$etab($c))->all())));

            // « Tout importer » : le code est pris ailleurs (autre établissement, autre
            // année hors du périmètre) — la classe est ouverte ici sous un code libre.
            if ($this->toutImporter && $copieSiPrise) {
                $libre = $this->codeLibre($code, [], fn ($c) => DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', $c)->exists(), 20);
                $plan = $this->planClasse($libre, $code, $niveau);
                if ($plan['motif'] === null) {
                    $plan['note'] = "classe « {$code} » déjà utilisée ailleurs ({$ou}) : ouverte ici sous « {$libre} »"
                        .($plan['note'] ? ' — '.$plan['note'] : '');
                }

                return $this->resolutionsClasses[$code] = $plan;
            }

            return $this->resolutionsClasses[$code] = [
                'motif' => $cetteAnnee->isNotEmpty()
                    ? "Classe « {$code} » hors de l’établissement de travail ({$ou}), auquel ce compte n’a pas accès."
                    : "Classe « {$code} » ouverte seulement hors de l’année {$annee} et hors des établissements de ce compte ({$ou}) : un code de classe ne sert qu’une fois, il ne peut pas être repris ici.",
                'plan' => null, 'note' => null,
            ];
        }

        if (mb_strlen($code) > 20) {
            if (! $this->toutImporter) {
                return $this->resolutionsClasses[$code] = [
                    'motif' => "Classe « {$code} » inconnue, et son code dépasse 20 caractères : elle ne peut pas être créée sous ce code.",
                    'plan' => null, 'note' => null,
                ];
            }
            $court = $this->codeLibre($code, [], fn ($c) => DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', $c)->exists(), 20, true);
            $plan = $this->planClasse($court, $code, $niveau);
            if ($plan['motif'] === null) {
                $plan['note'] = "code de classe « {$code} » trop long : ouverte sous « {$court} » — ".$plan['note'];
            }

            return $this->resolutionsClasses[$code] = $plan;
        }

        return $this->resolutionsClasses[$code] = $this->planClasse($code, $code, $niveau);
    }

    /**
     * Le plan de création d'une classe inconnue : niveau donné, deviné, ou laissé à
     * renseigner ; établissement de travail, ou à défaut celui du compte (« tout importer »).
     * Le résultat porte `code` : le code sous lequel la classe sera ouverte.
     */
    protected function planClasse(string $code, string $libelle, ?string $niveau): array
    {
        $annee = ContexteScolaire::annee();
        $niveaux = $this->niveauxConnus();
        $niveauRetenu = ($niveau !== null && isset($niveaux[$niveau])) ? $niveau : $this->niveauDevine($libelle, $niveaux);
        $notes = [];

        if ($niveauRetenu === null) {
            if (! $this->toutImporter) {
                return [
                    'motif' => "Classe « {$libelle} » inconnue pour l’année {$annee}, et son niveau ne se déduit ni du fichier ni de son code : créez-la (écran ou feuille Classes), ou indiquez son niveau.",
                    'plan' => null, 'note' => null,
                ];
            }
            // Niveau du fichier même inconnu de l'année, sinon aucun : la classe est créée,
            // son niveau se renseigne ensuite depuis l'écran Classes.
            $niveauRetenu = $niveau;
            $notes[] = $niveau !== null ? "niveau « {$niveau} » inconnu de l’année, repris tel quel" : 'niveau à renseigner dans l’écran Classes';
        }

        $etablissement = $this->etablissementDeCreation();
        if ($etablissement === null && $this->toutImporter) {
            $etablissement = $this->etablissementParDefaut();
            if ($etablissement !== null) {
                $notes[] = "établissement {$etablissement} retenu faute d’établissement de travail choisi";
            }
        }
        if ($etablissement === null) {
            return [
                'motif' => "Classe « {$libelle} » inconnue pour l’année {$annee} : choisissez dans l’en-tête l’établissement où la créer, puis relancez l’analyse.",
                'plan' => null, 'note' => null,
            ];
        }

        return [
            'motif' => null,
            'code' => $code,
            'plan' => [
                'code' => $code,
                'nom' => $libelle,
                'niveau_code' => $niveauRetenu,
                'annee' => $annee,
                'etablissement' => $etablissement,
            ],
            'note' => "classe « {$code} » inconnue : créée à l’import (niveau ".($niveauRetenu ?? '—').", établissement {$etablissement})"
                .($notes ? ' ; '.implode(' ; ', $notes) : ''),
        ];
    }

    /**
     * Établissement où ouvrir ce que l'import crée quand aucun établissement de travail
     * n'est choisi : celui de la session, le rattachement du compte, sinon le premier de
     * son périmètre (ou, pour un Super Admin, le premier établissement actif).
     */
    protected function etablissementParDefaut(): ?string
    {
        try {
            if (($code = PerimetreEtablissement::code()) !== null) {
                return $code;
            }
            $user = auth()->user();
            $autorises = PerimetreEtablissement::etablissementsAutorises($user);
            $rattachement = trim((string) ($user->Etab ?? ''));
            if ($rattachement !== '' && ($autorises === null || in_array($rattachement, $autorises, true))) {
                return $rattachement;
            }
            if (is_array($autorises)) {
                return $autorises[0] ?? null;
            }

            $premier = DB::connection('ecoprim')->table('console_etablissements')
                ->where('actif', true)->orderBy('code')->value('code');

            return $premier !== null ? (string) $premier : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Crée, au moment d'écrire, la classe que l'analyse a prévu d'ouvrir — une seule fois,
     * même si cent lignes la demandent, et pas du tout si le code est déjà pris.
     */
    protected function assurerClasse(?array $plan): void
    {
        if (! is_array($plan) || empty($plan['code'])) {
            return;
        }

        // Un code de classe est unique dans T_CLASSE, toutes années confondues.
        if (DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', $plan['code'])->exists()) {
            return;
        }

        (new ReferentielEcrivain('T_CLASSE', 'num', [
            'code' => 'CodeClasse',
            'nom' => 'LibelleClasse',
            'niveau_code' => 'CodN',
            'annee' => 'ANNEE',
            'etablissement' => 'CODEETABLISSEMENT',
        ]))->creer(array_filter([
            'code' => $plan['code'],
            'nom' => mb_substr((string) ($plan['nom'] ?? $plan['code']), 0, 60),
            'niveau_code' => $plan['niveau_code'] ?? null,
            'annee' => $plan['annee'] ?? null,
            'etablissement' => $plan['etablissement'] ?? null,
        ], fn ($v) => $v !== null));

        // Le périmètre se lit dans T_CLASSE : il vient de changer.
        PerimetreEtablissement::oublier();
    }

    /** Niveaux de l'année visée, en base ou créés par la feuille Niveaux du classeur. */
    protected function niveauxConnus(): array
    {
        if ($this->niveauxDeLAnnee === null) {
            try {
                $this->niveauxDeLAnnee = DB::connection('economat')->table('T_NIVEAU')
                    ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                    ->pluck('CodeNiveau')
                    ->mapWithKeys(fn ($c) => [trim((string) $c) => true])
                    ->all();
            } catch (Throwable $e) {
                $this->referentielInjoignable('niveaux', $e);
            }
        }

        return $this->avecAttendusDe('niveaux', $this->niveauxDeLAnnee);
    }

    /** Le niveau dont le code commence le code de la classe — le plus long : « CM1 B » -> CM1. */
    protected function niveauDevine(string $classe, array $niveaux): ?string
    {
        $cible = preg_replace('/[\s._-]+/u', '', mb_strtolower($classe));
        $meilleur = null;
        foreach (array_keys($niveaux) as $niveau) {
            $n = preg_replace('/[\s._-]+/u', '', mb_strtolower((string) $niveau));
            if ($n !== '' && str_starts_with($cible, $n) && ($meilleur === null || mb_strlen($n) > mb_strlen($meilleur[1]))) {
                $meilleur = [(string) $niveau, $n];
            }
        }

        return $meilleur[0] ?? null;
    }
}
