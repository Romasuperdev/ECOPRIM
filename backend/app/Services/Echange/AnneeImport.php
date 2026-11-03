<?php

namespace App\Services\Echange;

use App\Models\AnneeScolaire;
use App\Services\EconomatTable;
use App\Services\ReferentielEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * L'année dans laquelle un import écrit.
 *
 * L'ANNÉE MENTIONNÉE. Sauf année choisie à l'écran, un import va dans l'année que le
 * fichier mentionne : la colonne Année de ses feuilles (Classes, Niveaux), à défaut son
 * nom (« nexora-annee-scolaire-2025-2026.xlsx »). C'est l'année que la personne a sous
 * les yeux ; la remplacer en silence par l'année de l'en-tête rangeait un historique dans
 * l'année en cours. Rien de mentionné : l'année de travail, comme avant.
 *
 * UNE ANNÉE QUI N'EXISTE PAS se crée — à l'APPLICATION seulement, l'analyse n'écrit rien.
 * Elle reprend la forme de l'année de travail (« Année Scolaire 2026-2027 » / « 2026 »
 * donne « Année Scolaire 2025-2026 » / « 2025 »), ses dates décalées et sa société. Une
 * année ANTÉRIEURE à l'année de travail naît clôturée : c'est un historique qu'on range,
 * pas une année qu'on ouvre.
 *
 * SA STRUCTURE. Une année neuve n'a ni niveaux ni classes, et sans classe aucun élève ne
 * se range. Si le classeur n'apporte pas sa feuille Classes, l'année reprend les niveaux
 * et les classes de l'année de travail (établissements visibles) — annoncé dans le
 * rapport avant toute écriture. Un code de classe ne sert qu'une fois dans T_CLASSE
 * (clé primaire) : la copie de « CE1 A » s'appelle donc « CE1 A-2025 », sous le même
 * libellé, et les « CE1 A » du fichier y sont rangés (`_alias_classes`).
 */
class AnneeImport
{
    /**
     * Écrire dans une année clôturée, ou en créer une, se fait sous cette permission —
     * accordée d'office aux administrateurs (RhUser::aLaPermission), et à tout autre rôle
     * depuis l'écran Permissions.
     */
    public const PERMISSION = 'importer_annee_cloturee';

    public function __construct(private LecteurClasseur $lecteur) {}

    public static function autorise(): bool
    {
        try {
            $user = auth()->user();

            return $user !== null && method_exists($user, 'aLaPermission') && $user->aLaPermission(self::PERMISSION);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Détermine l'année visée et y place le contexte de travail pour l'analyse.
     *
     * @param  bool  $choisie  une année a été choisie à l'écran (le contexte y est déjà)
     * @param  bool  $classeurApporteClasses  le classeur porte des classes : pas de reprise
     * @return array{libelle: ?string, code: ?string, source: string, existe: bool, cloturee: bool, creation: ?array, structure: ?array}
     */
    public function preparer(string $chemin, string $nomOrigine, bool $choisie, bool $classeurApporteClasses): array
    {
        $travail = ContexteScolaire::annee();
        $base = [
            'libelle' => $travail,
            'code' => ContexteScolaire::variantes()[1] ?? null,
            'source' => $choisie ? 'choisie' : 'travail',
            'existe' => true,
            'cloturee' => AnneeScolaireGuard::estCloturee($travail),
            'creation' => null,
            'structure' => null,
        ];

        if ($choisie) {
            return $base;
        }

        $paire = $this->mentionnee($chemin, $nomOrigine);
        if ($paire === null) {
            return $base;
        }

        $reference = $this->annee($travail);
        if ($reference !== null && self::paireDe($reference) === $paire) {
            return ['source' => 'fichier'] + $base;
        }

        $existante = $this->trouver($paire, $reference);
        if ($existante !== null) {
            ContexteScolaire::forcer((string) $existante->libelle);
            PerimetreEtablissement::oublier();

            return [
                'libelle' => $existante->libelle,
                'code' => $existante->code_annee,
                'source' => 'fichier',
                'existe' => true,
                'cloturee' => AnneeScolaireGuard::estCloturee($existante->libelle),
                'creation' => null,
                'structure' => null,
            ];
        }

        $creation = $this->aCreer($paire, $reference);
        $structure = $classeurApporteClasses ? null : $this->structure($paire[0], $reference);

        ContexteScolaire::forcerNouvelle($creation['libelle'], $creation['code']);
        PerimetreEtablissement::oublier();

        return [
            'libelle' => $creation['libelle'],
            'code' => $creation['code'],
            'source' => 'fichier',
            'existe' => false,
            'cloturee' => $creation['cloturee'],
            'creation' => $creation,
            'structure' => $structure,
            // L'année d'où viennent les classes reprises — pas forcément celle de travail.
            'reference' => $structure['annee'] ?? $reference?->libelle,
        ];
    }

    /** Ce que la structure reprise fait connaître aux feuilles, comme Importateur::avecAttendus. */
    public static function attendusDe(?array $structure): array
    {
        if (! $structure) {
            return [];
        }

        return [
            'niveaux' => array_fill_keys(array_keys($structure['niveaux']), true),
            'classes' => array_map(fn ($c) => (string) $c['niveau'], $structure['classes']),
            // Code du fichier (celui de l'année de travail) -> code de la copie.
            '_alias_classes' => array_map(fn ($c) => $c['code'], array_column($structure['classes'], null, 'origine')),
        ];
    }

    /**
     * « Tout importer » : les classes que le fichier cite et que l'année visée n'a pas, mais
     * qu'un établissement visible a ouvertes une AUTRE année (« PS » en 2024/2025), sont
     * ajoutées à la structure — copiées dans l'année visée sous un code libre (« PS-2025 »),
     * même libellé, même niveau. Une classe introuvable partout reste écartée : on ne
     * fabrique pas une classe sans savoir de quel niveau elle est.
     *
     * Appelé pendant l'analyse, dans le contexte de l'année visée. N'écrit rien.
     *
     * @param  array<int, string>  $citees  codes de classe cités par le fichier
     */
    public function completerStructure(array $cible, array $citees): array
    {
        $structure = $cible['structure'] ?? ['annee' => null, 'niveaux' => [], 'classes' => []];
        $alias = self::attendusDe($cible['structure'] ?? null)['_alias_classes'] ?? [];
        $debut = self::paire((string) $cible['libelle'])[0] ?? (int) date('Y');
        $etablissements = PerimetreEtablissement::etablissementsVisibles();
        $variantes = ContexteScolaire::variantes();

        try {
            $dansLAnnee = DB::connection('economat')->table('T_CLASSE')
                ->when($variantes !== [], fn ($q) => $q->whereIn('ANNEE', $variantes), fn ($q) => $q->whereRaw('1 = 0'))
                ->pluck('CodeClasse')->mapWithKeys(fn ($c) => [trim((string) $c) => true])->all();
            $pris = DB::connection('economat')->table('T_CLASSE')->pluck('CodeClasse')
                ->mapWithKeys(fn ($c) => [trim((string) $c) => true])->all() + $structure['classes'];

            foreach (array_unique($citees) as $code) {
                $code = trim((string) $code);
                if ($code === '' || isset($dansLAnnee[$code]) || isset($alias[$code]) || isset($structure['classes'][$code])) {
                    continue;
                }

                $source = DB::connection('economat')->table('T_CLASSE')
                    ->where('CodeClasse', $code)
                    ->when($etablissements !== null, fn ($q) => $q->whereIn('CODEETABLISSEMENT', $etablissements ?: ['']))
                    ->first(['CodeClasse', 'LibelleClasse', 'CodN', 'CODEETABLISSEMENT', 'ANNEE']);
                if ($source === null) {
                    continue;
                }

                $copie = $this->codeLibre($code, $debut, $pris);
                $pris[$copie] = true;
                $alias[$code] = $copie;
                $structure['classes'][$copie] = [
                    'code' => $copie,
                    'origine' => $code,
                    'nom' => $source->LibelleClasse,
                    'niveau' => trim((string) $source->CodN),
                    'etablissement' => $source->CODEETABLISSEMENT,
                    'depuis' => $source->ANNEE,
                ];

                $niveau = trim((string) $source->CodN);
                if ($niveau !== '' && ! isset($structure['niveaux'][$niveau])) {
                    $n = DB::connection('economat')->table('T_NIVEAU')
                        ->where('CodeNiveau', $niveau)->where('ANNEE', $source->ANNEE)
                        ->first(['LibelleNiveau', 'Ordre']);
                    if ($n) {
                        $structure['niveaux'][$niveau] = ['libelle' => $n->LibelleNiveau, 'ordre' => $n->Ordre];
                    }
                }
            }
        } catch (Throwable $e) {
            return $cible;
        }

        return ['structure' => $structure['classes'] === [] ? null : $structure] + $cible;
    }

    /** Une année qui existe déjà : seulement ouvrir les classes que l'import y ajoute. */
    public function ouvrirStructure(array $cible): void
    {
        if (! empty($cible['structure'])) {
            DB::connection('economat')->transaction(
                fn () => $this->ecrireStructure($cible['structure'], (string) $cible['libelle'])
            );
            PerimetreEtablissement::oublier();
        }
    }

    /**
     * Crée l'année (si personne ne l'a fait entre-temps) puis sa structure, et y place le
     * contexte de travail. Appelé par l'application de l'import, jamais par l'analyse.
     */
    public function creer(array $cible): void
    {
        // L'année et sa structure, ou rien : une année créée sans ses classes ne range
        // aucun élève, et resterait là, vide, à la place de celle qu'on voulait.
        DB::connection('economat')->transaction(fn () => $this->creerAnneeEtStructure($cible));

        AnneeScolaireGuard::oublier();
        ContexteScolaire::forcer($cible['creation']['libelle']);
        PerimetreEtablissement::oublier();
    }

    private function creerAnneeEtStructure(array $cible): void
    {
        $creation = $cible['creation'];

        if ($this->trouver(self::paireDe($creation['libelle']) ?? [0, 0], null) === null) {
            $colonnes = array_flip(Schema::connection('economat')->getColumnListing('T_ANNEEACADEMIQUE'));
            $ligne = array_intersect_key([
                'CodeAnnee' => $creation['code'],
                'LibelleAnnee' => $creation['libelle'],
                'DEBUT' => $creation['debut'],
                'FIN' => $creation['fin'],
                'Activer' => false,
                'CloturePartielle' => false,
                'ClotureDefinitive' => $creation['cloturee'],
                'CODESOCIETE' => $creation['societe'],
            ], $colonnes);

            EconomatTable::pour('T_ANNEEACADEMIQUE', 'CODE')->inserer($ligne);
        }

        if ($cible['structure']) {
            $this->ecrireStructure($cible['structure'], $creation['libelle']);
        }
    }

    /** [2025, 2026] pour « 2025-2026 », « Année Scolaire 2025/2026 », « 20252026 »… sinon null. */
    public static function paire(?string $texte): ?array
    {
        if (preg_match_all('/(?<!\d)(\d{4})\s*[-\/_ ]?\s*(\d{4})(?!\d)/', (string) $texte, $m, PREG_SET_ORDER)) {
            foreach ($m as $c) {
                if ((int) $c[2] === (int) $c[1] + 1) {
                    return [(int) $c[1], (int) $c[2]];
                }
            }
        }

        return null;
    }

    /** La paire d'une année du référentiel : son libellé, sinon son code (« 2026 » -> 2026-2027). */
    public static function paireDe($annee): ?array
    {
        if (is_string($annee)) {
            return self::paire($annee);
        }

        $paire = self::paire((string) $annee->libelle) ?? self::paire((string) $annee->code_annee);
        if ($paire === null && preg_match('/^\s*(\d{4})\s*$/', (string) $annee->code_annee, $m)) {
            $paire = [(int) $m[1], (int) $m[1] + 1];
        }

        return $paire;
    }

    /**
     * L'année que le fichier mentionne : la plus fréquente de ses colonnes Année, à défaut
     * celle de son nom.
     */
    private function mentionnee(string $chemin, string $nomOrigine): ?array
    {
        $compte = [];
        try {
            foreach ($this->lecteur->lireToutes($chemin) as $feuille) {
                $colonne = collect($feuille['entetes'])->first(fn ($e) => $this->normaliser($e) === 'annee');
                if ($colonne === null) {
                    continue;
                }
                foreach ($feuille['lignes'] as $ligne) {
                    if (($p = self::paire((string) ($ligne[$colonne] ?? ''))) !== null) {
                        $cle = implode('-', $p);
                        $compte[$cle] = ($compte[$cle] ?? 0) + 1;
                    }
                }
            }
        } catch (Throwable $e) {
            // Fichier illisible : l'analyse le dira elle-même, avec son propre message.
        }

        if ($compte !== []) {
            arsort($compte);

            return array_map('intval', explode('-', (string) array_key_first($compte)));
        }

        return self::paire(pathinfo($nomOrigine, PATHINFO_FILENAME));
    }

    private function annee(?string $libelle): ?AnneeScolaire
    {
        if ($libelle === null) {
            return null;
        }

        return AnneeScolaire::all()->first(fn ($a) => $a->libelle === $libelle || $a->code_annee === $libelle);
    }

    /** L'année du référentiel qui porte cette paire — de préférence dans la société de travail. */
    private function trouver(array $paire, ?AnneeScolaire $reference): ?AnneeScolaire
    {
        $candidates = AnneeScolaire::all()->filter(fn ($a) => self::paireDe($a) === $paire);
        $societe = $reference?->getRawOriginal('CODESOCIETE');

        return $candidates->first(fn ($a) => $societe !== null && $a->getRawOriginal('CODESOCIETE') === $societe)
            ?? $candidates->first();
    }

    /** L'année à créer, sur le modèle de l'année de travail. */
    private function aCreer(array $paire, ?AnneeScolaire $reference): array
    {
        [$debut, $fin] = $paire;
        $modele = $reference ? self::paireDe($reference) : null;

        if ($reference && $modele) {
            $remplacer = [(string) $modele[0] => (string) $debut, (string) $modele[1] => (string) $fin];
            $libelle = strtr((string) $reference->libelle, $remplacer);
            $code = strtr((string) $reference->code_annee, $remplacer);
            $decalage = $debut - $modele[0];
            $date = fn ($d, $defaut) => $d
                ? CarbonImmutable::parse($d)->addYears($decalage)->toDateString()
                : $defaut;
            $dateDebut = $date($reference->date_debut, "{$debut}-09-01");
            $dateFin = $date($reference->date_fin, "{$fin}-06-30");
            $anterieure = $debut < $modele[0];
        } else {
            $libelle = "{$debut}-{$fin}";
            $code = (string) $debut;
            $dateDebut = "{$debut}-09-01";
            $dateFin = "{$fin}-06-30";
            $anterieure = CarbonImmutable::parse($dateFin)->isPast();
        }

        return [
            'libelle' => $libelle,
            'code' => $code !== '' ? $code : (string) $debut,
            'debut' => $dateDebut,
            'fin' => $dateFin,
            'cloturee' => $anterieure,
            'societe' => $reference?->getRawOriginal('CODESOCIETE'),
        ];
    }

    /**
     * Niveaux et classes à reprendre, dans les établissements visibles : ceux de l'année
     * de travail, et si elle n'en a aucun, ceux de l'année la plus récente qui en a.
     *
     * Pourquoi pas l'année de travail seule : deux sociétés peuvent avoir chacune leur
     * « 2026-2027 », et les classes d'un établissement être rangées sous celle de l'autre
     * (cas réel : ETAB001, société AURIAK, a ses classes sous « Année Scolaire 2026-2027 »
     * de SOC00001). L'en-tête affiche les deux « 2026-2027 » : on ne peut pas demander à
     * l'utilisateur de deviner laquelle porte ses classes.
     *
     * @return array{annee: ?string, niveaux: array<string, array>, classes: array<string, array>}|null
     */
    private function structure(int $debut, ?AnneeScolaire $reference): ?array
    {
        try {
            $etablissements = PerimetreEtablissement::etablissementsVisibles();
            $annees = collect([$reference])->filter()->merge(
                AnneeScolaire::all()
                    ->reject(fn ($a) => $reference && $a->getKey() === $reference->getKey())
                    ->sortByDesc(fn ($a) => (string) $a->date_debut)
            );

            foreach ($annees as $annee) {
                $variantes = array_values(array_filter(
                    [(string) $annee->libelle, (string) $annee->code_annee],
                    fn ($v) => trim($v) !== '',
                ));
                $lignes = DB::connection('economat')->table('T_CLASSE')
                    ->select('CodeClasse', 'LibelleClasse', 'CodN', 'CODEETABLISSEMENT')
                    ->whereIn('ANNEE', $variantes)
                    ->when($etablissements !== null, fn ($q) => $q->whereIn('CODEETABLISSEMENT', $etablissements ?: ['']))
                    ->get();

                if ($lignes->isNotEmpty()) {
                    return $this->structureDe($lignes, $variantes, $debut, (string) $annee->libelle);
                }
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    private function structureDe($lignes, array $variantes, int $debut, string $annee): array
    {
        $pris = DB::connection('economat')->table('T_CLASSE')->pluck('CodeClasse')
            ->mapWithKeys(fn ($c) => [trim((string) $c) => true])->all();

        $classes = [];
        foreach ($lignes as $c) {
            $origine = trim((string) $c->CodeClasse);
            $code = $this->codeLibre($origine, $debut, $pris);
            $pris[$code] = true;
            $classes[$code] = [
                'code' => $code,
                'origine' => $origine,
                'nom' => $c->LibelleClasse,
                'niveau' => trim((string) $c->CodN),
                'etablissement' => $c->CODEETABLISSEMENT,
            ];
        }

        $codesNiveaux = array_values(array_unique(array_filter(array_column($classes, 'niveau'))));
        $niveaux = $codesNiveaux === [] ? [] : DB::connection('economat')->table('T_NIVEAU')
            ->select('CodeNiveau', 'LibelleNiveau', 'Ordre')
            ->whereIn('ANNEE', $variantes)
            ->whereIn('CodeNiveau', $codesNiveaux)
            ->get()
            ->mapWithKeys(fn ($n) => [trim((string) $n->CodeNiveau) => [
                'libelle' => $n->LibelleNiveau,
                'ordre' => $n->Ordre,
            ]])
            ->all();

        return ['annee' => $annee, 'niveaux' => $niveaux, 'classes' => $classes];
    }

    /** « CE1 A » pris -> « CE1 A-2025 », puis « CE1 A-2025-2 »… ; 20 caractères au plus. */
    private function codeLibre(string $origine, int $debut, array $pris): string
    {
        $candidat = mb_substr($origine.'-'.$debut, 0, 20);
        for ($i = 2; isset($pris[$candidat]); $i++) {
            $suffixe = "-{$debut}-{$i}";
            $candidat = mb_substr($origine, 0, 20 - mb_strlen($suffixe)).$suffixe;
        }

        return $candidat;
    }

    /** Écrit la structure reprise dans l'année neuve — sans doublon si elle y est déjà. */
    private function ecrireStructure(array $structure, string $annee): void
    {
        $niveaux = new ReferentielEcrivain('T_NIVEAU', 'Num', [
            'code' => 'CodeNiveau', 'libelle' => 'LibelleNiveau', 'ordre' => 'Ordre', 'annee' => 'ANNEE',
        ]);
        $classes = new ReferentielEcrivain('T_CLASSE', 'num', [
            'code' => 'CodeClasse', 'nom' => 'LibelleClasse', 'niveau_code' => 'CodN',
            'annee' => 'ANNEE', 'etablissement' => 'CODEETABLISSEMENT',
        ]);
        $dansLAnnee = fn ($ecrivain, $colonne, $code) => $ecrivain->requete()
            ->where($colonne, $code)
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
            ->exists();
        // Un code de classe est unique toutes années confondues (clé primaire).
        $classePrise = fn ($code) => $classes->requete()->where('CodeClasse', $code)->exists();

        foreach ($structure['niveaux'] as $code => $n) {
            if (! $dansLAnnee($niveaux, 'CodeNiveau', $code)) {
                $niveaux->creer(['code' => $code, 'libelle' => $n['libelle'], 'ordre' => $n['ordre'], 'annee' => $annee]);
            }
        }

        foreach ($structure['classes'] as $code => $c) {
            if (! $classePrise($code)) {
                $classes->creer([
                    'code' => $code, 'nom' => $c['nom'], 'niveau_code' => $c['niveau'],
                    'annee' => $annee, 'etablissement' => $c['etablissement'],
                ]);
            }
        }
    }

    private function normaliser(string $texte): string
    {
        $texte = mb_strtolower(trim($texte));

        return preg_replace('/[^a-z0-9]/', '', strtr($texte, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u']));
    }
}
