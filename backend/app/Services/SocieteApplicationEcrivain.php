<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Société - Applications : dbmasterbacou.US_SOCIETE_APPLICATION, la liste des applications
 * de la suite ouvertes à chaque société.
 *
 * Une ligne = (société, application). On l'écrit comme parcauto : CODESOCIETE = le code de
 * la société, CodeApp = le code de l'application, et en plus `societe_id` = US_SOCIETE.id
 * (la clé étrangère de la table). Comme pour toute liaison, détacher SUPPRIME la ligne :
 * elle n'a pas d'existence propre, et ni la société ni l'application ne sont touchées.
 *
 * LECTURE TOLÉRANTE. Des lignes plus anciennes désignent la société autrement que par son
 * code (CODESOCIETE = '1', c'est-à-dire son NUMAUTO). Une ligne est donc rattachée à la
 * société par son code, sinon par son NUMAUTO, sinon par son identifiant — et ces lignes
 * se détachent comme les autres.
 */
class SocieteApplicationEcrivain
{
    /**
     * Toutes les sociétés de US_SOCIETE, chacune avec ses applications — y compris celles
     * qui n'en ont aucune, comme dans parcauto.
     */
    public function lister(): array
    {
        $societes = $this->societes();
        $index = $this->indexer($societes);
        $catalogue = $this->catalogue();

        $parSociete = [];
        foreach ($this->liens() as $l) {
            $code = $this->resoudre($l, $index);
            $app = trim((string) $l->CodeApp);
            if ($code === null || $app === '') {
                continue;
            }
            $parSociete[$code][$app] = [
                'code' => $app,
                'libelle' => $catalogue[$app] ?? $app,
            ];
        }

        return $societes->map(fn ($s) => [
            'code' => $s['code'],
            'nom' => $s['nom'],
            'applications' => collect($parSociete[$s['code']] ?? [])->sortKeys()->values()->all(),
        ])->sortBy('code', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** Le catalogue, pour les cases à cocher du formulaire. */
    public function applications(): array
    {
        return collect($this->catalogue())
            ->map(fn ($libelle, $code) => ['code' => (string) $code, 'libelle' => $libelle])
            ->values()->all();
    }

    /**
     * Enregistre la liste COMPLÈTE des applications d'une société, telle que cochée :
     * les applications décochées sont détachées, les nouvelles rattachées.
     *
     * @param  string[]  $codesApps
     * @return array{ajoutees: int, retirees: int}
     */
    public function synchroniser(string $codeSociete, array $codesApps): array
    {
        $societe = $this->societe($codeSociete);
        $voulues = collect($codesApps)->map(fn ($c) => trim((string) $c))->filter()->unique()->values();

        $catalogue = $this->catalogue();
        $inconnues = $voulues->reject(fn ($c) => array_key_exists($c, $catalogue));
        if ($inconnues->isNotEmpty()) {
            throw ValidationException::withMessages([
                'applications' => ['Application inconnue : '.$inconnues->implode(', ').'.'],
            ]);
        }

        $ajoutees = 0;
        $retirees = 0;

        DB::connection('master')->transaction(function () use ($societe, $voulues, &$ajoutees, &$retirees) {
            $presentes = [];
            foreach ($this->lignesDe($societe['code']) as $l) {
                $app = trim((string) $l->CodeApp);
                // Décochée, ou doublon d'une ligne déjà gardée : on la retire.
                if (! $voulues->contains($app) || isset($presentes[$app])) {
                    $this->table()->where('ID', $l->ID)->delete();
                    $retirees++;

                    continue;
                }
                $presentes[$app] = true;
            }

            foreach ($voulues as $app) {
                if (isset($presentes[$app])) {
                    continue;
                }
                $this->table()->insert([
                    'CODESOCIETE' => $societe['code'],
                    'CodeApp' => $app,
                    'societe_id' => $societe['id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $ajoutees++;
            }
        });

        return ['ajoutees' => $ajoutees, 'retirees' => $retirees];
    }

    /** Détache une application d'une société (le × de la pastille). */
    public function detacher(string $codeSociete, string $codeApp): int
    {
        $societe = $this->societe($codeSociete);
        $ids = $this->lignesDe($societe['code'])
            ->filter(fn ($l) => trim((string) $l->CodeApp) === trim($codeApp))
            ->pluck('ID');

        abort_if($ids->isEmpty(), 404, 'Cette application n’est pas rattachée à cette société.');

        return $this->table()->whereIn('ID', $ids->all())->delete();
    }

    /** « Retirer tout » : détache la société de toutes ses applications. */
    public function retirerTout(string $codeSociete): int
    {
        $societe = $this->societe($codeSociete);
        $ids = $this->lignesDe($societe['code'])->pluck('ID');

        return $ids->isEmpty() ? 0 : $this->table()->whereIn('ID', $ids->all())->delete();
    }

    // --- Lecture ------------------------------------------------------------------------

    /** Les lignes de liaison d'une société, quelle que soit la façon dont elles la désignent. */
    private function lignesDe(string $code): Collection
    {
        $index = $this->indexer($this->societes());

        return $this->liens()->filter(fn ($l) => $this->resoudre($l, $index) === $code)->values();
    }

    /** Code de la société désignée par une ligne : par code, sinon NUMAUTO, sinon id. */
    private function resoudre(object $lien, array $index): ?string
    {
        $c = trim((string) $lien->CODESOCIETE);
        $sid = strtolower(trim((string) ($lien->societe_id ?? '')));

        return $index['code'][$c]
            ?? (ctype_digit($c) ? ($index['numauto'][(int) $c] ?? null) : null)
            ?? ($sid !== '' ? ($index['id'][$sid] ?? null) : null)
            ?? ($index['id'][strtolower($c)] ?? null);
    }

    private function indexer(Collection $societes): array
    {
        $index = ['code' => [], 'numauto' => [], 'id' => []];
        foreach ($societes as $s) {
            $index['code'][$s['code']] = $s['code'];
            if ($s['numauto'] !== null) {
                $index['numauto'][$s['numauto']] = $s['code'];
            }
            if ($s['id'] !== null) {
                $index['id'][strtolower($s['id'])] = $s['code'];
            }
        }

        return $index;
    }

    private function societes(): Collection
    {
        return DB::connection('master')->table('US_SOCIETE')->get()
            ->map(fn ($s) => [
                'code' => trim((string) $s->CODESOCIETE),
                'nom' => trim((string) ($s->NOMSOCIETE ?? '')) ?: trim((string) $s->CODESOCIETE),
                'numauto' => isset($s->NUMAUTO) ? (int) $s->NUMAUTO : null,
                'id' => isset($s->id) && trim((string) $s->id) !== '' ? trim((string) $s->id) : null,
            ])
            ->filter(fn ($s) => $s['code'] !== '')
            ->values();
    }

    private function societe(string $code): array
    {
        $societe = $this->societes()->firstWhere('code', trim($code));
        abort_unless($societe, 404, 'Société introuvable.');

        return $societe;
    }

    /** CodeApp => Intitulé, triés par code. */
    private function catalogue(): array
    {
        return DB::connection('master')->table('US_APPLICATIONS')->orderBy('CodeApp')->get()
            ->mapWithKeys(fn ($a) => [trim((string) $a->CodeApp) => trim((string) $a->Intitule) ?: trim((string) $a->CodeApp)])
            ->filter(fn ($libelle, $code) => $code !== '')
            ->all();
    }

    private function liens(): Collection
    {
        return $this->table()->get();
    }

    private function table()
    {
        return DB::connection('master')->table('US_SOCIETE_APPLICATION');
    }
}
