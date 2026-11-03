<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EconomatTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trame horaire des emplois du temps : les jours de cours (ECONOMAT.T_EMPJOUR) et les
 * plages horaires (ECONOMAT.T_HORAIRE).
 *
 * Jusqu'ici, la trame ne se saisissait que dans le logiciel ECONOMAT : sur un serveur dont
 * la base n'en avait pas, l'écran Emplois du temps restait bloqué sur « La trame horaire
 * est vide ». Elle se saisit maintenant ici, en un seul formulaire : on envoie la liste
 * complète des jours et des plages, le serveur ajoute, modifie et retire ce qui diffère.
 *
 * Une ligne qui porte déjà des créneaux (T_EMPLOIDUTEMPS) ne peut pas être retirée : elle
 * laisserait des cours orphelins. Le refus nomme la ligne en cause.
 *
 * La trame est COMMUNE à tous les établissements : ces deux tables n'ont pas de colonne
 * établissement dans ECONOMAT.
 */
class TrameHoraireController extends Controller
{
    public function enregistrer(Request $request)
    {
        $d = $request->validate([
            'jours' => ['present', 'array', 'max:7'],
            'jours.*.code' => ['nullable', 'integer'],
            'jours.*.libelle' => ['required', 'string', 'max:30'],
            'heures' => ['present', 'array', 'max:20'],
            'heures.*.code' => ['nullable', 'integer'],
            'heures.*.debut' => ['required', 'date_format:H:i'],
            'heures.*.fin' => ['required', 'date_format:H:i'],
        ], [
            'jours.*.libelle.required' => 'Chaque jour doit avoir un nom.',
            'heures.*.debut.date_format' => 'Heure de début invalide : utilisez le format HH:MM (ex. 07:30).',
            'heures.*.fin.date_format' => 'Heure de fin invalide : utilisez le format HH:MM (ex. 08:30).',
        ]);

        $this->verifierJours($d['jours']);
        $heures = $this->verifierHeures($d['heures']);

        DB::connection('economat')->transaction(function () use ($d, $heures) {
            $this->synchroniser(
                'T_EMPJOUR', 'Code', 'CODEJOUR', $d['jours'],
                fn ($j) => ['Libelle' => trim($j['libelle'])],
                fn ($ligne) => 'le jour « '.trim((string) $ligne->Libelle).' »',
            );
            $this->synchroniser(
                'T_HORAIRE', 'COD_HORAIRE', 'CODEHEURE', $heures,
                fn ($h) => ['HEUR_DEBUT' => $h['debut'], 'HEUR_FIN' => $h['fin'], 'DUREEE' => $this->duree($h['debut'], $h['fin'])],
                fn ($ligne) => 'la plage '.trim($ligne->HEUR_DEBUT.' - '.$ligne->HEUR_FIN),
            );
        });

        return app(EmploiDuTempsController::class)->referentiels();
    }

    private function verifierJours(array $jours): void
    {
        $vus = [];
        foreach ($jours as $i => $j) {
            $cle = mb_strtolower(trim($j['libelle']));
            if (isset($vus[$cle])) {
                throw ValidationException::withMessages(["jours.$i.libelle" => "Le jour « {$j['libelle']} » figure deux fois."]);
            }
            $vus[$cle] = true;
        }
    }

    /** Plages triées par heure de début ; refuse une fin avant le début et un chevauchement. */
    private function verifierHeures(array $heures): array
    {
        foreach ($heures as $i => $h) {
            if ($h['fin'] <= $h['debut']) {
                throw ValidationException::withMessages(["heures.$i.fin" => "La plage {$h['debut']} - {$h['fin']} finit avant de commencer."]);
            }
        }

        usort($heures, fn ($a, $b) => strcmp($a['debut'], $b['debut']));

        for ($i = 1; $i < count($heures); $i++) {
            if ($heures[$i]['debut'] < $heures[$i - 1]['fin']) {
                throw ValidationException::withMessages(['heures' => "Les plages {$heures[$i - 1]['debut']} - {$heures[$i - 1]['fin']} "
                    ."et {$heures[$i]['debut']} - {$heures[$i]['fin']} se chevauchent."]);
            }
        }

        return $heures;
    }

    /**
     * Aligne une table sur la liste reçue : modifie les lignes connues, ajoute les nouvelles,
     * retire les absentes — sauf si des créneaux s'y rattachent.
     */
    private function synchroniser(string $table, string $pk, string $colonneCreneau, array $lignes, callable $valeurs, callable $nommer): void
    {
        $db = DB::connection('economat');
        $existantes = $db->table($table)->get()->keyBy(fn ($l) => (int) $l->{$pk});
        $gardees = [];

        foreach ($lignes as $ligne) {
            $code = isset($ligne['code']) ? (int) $ligne['code'] : null;
            if ($code !== null && $existantes->has($code)) {
                $db->table($table)->where($pk, $code)->update($valeurs($ligne));
                $gardees[] = $code;
            } else {
                $gardees[] = (int) EconomatTable::pour($table, $pk)->inserer($valeurs($ligne));
            }
        }

        foreach ($existantes as $code => $ligne) {
            if (in_array($code, $gardees, true)) {
                continue;
            }
            $utilisee = $db->table('T_EMPLOIDUTEMPS')->where($colonneCreneau, $code)->count();
            if ($utilisee > 0) {
                throw ValidationException::withMessages([
                    $table === 'T_EMPJOUR' ? 'jours' : 'heures' => 'Impossible de retirer '.$nommer($ligne)
                        ." : {$utilisee} cours y sont placés dans les emplois du temps. Videz d'abord ces cases.",
                ]);
            }
            $db->table($table)->where($pk, $code)->delete();
        }
    }

    private function duree(string $debut, string $fin): string
    {
        [$h1, $m1] = array_map('intval', explode(':', $debut));
        [$h2, $m2] = array_map('intval', explode(':', $fin));
        $minutes = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
