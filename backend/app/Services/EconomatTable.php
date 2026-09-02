<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Accès en écriture CONTRÔLÉ à une table ECONOMAT.
 *
 * Règle commune à tout NEXORA : INSERT et UPDATE seulement, jamais de DELETE — ces
 * tables sont partagées avec les autres applications de la suite. Les clés primaires
 * de ces tables sont parfois IDENTITY, parfois des compteurs manuels : le cas est
 * détecté à l'exécution pour que le même code marche dans les deux configurations.
 */
class EconomatTable
{
    public function __construct(
        private string $table,
        private string $pk,
        private string $connexion = 'economat',
    ) {}

    public static function pour(string $table, string $pk, string $connexion = 'economat'): self
    {
        return new self($table, $pk, $connexion);
    }

    public function requete()
    {
        return DB::connection($this->connexion)->table($this->table);
    }

    /** Insère une ligne et renvoie sa clé primaire. */
    public function inserer(array $ligne)
    {
        if ($this->pkEstIdentity()) {
            return DB::connection($this->connexion)->table($this->table)->insertGetId($ligne, $this->pk);
        }

        $id = $this->prochainId();
        $this->requete()->insert($ligne + [$this->pk => $id]);

        return $id;
    }

    public function modifier($id, array $ligne): void
    {
        $this->requete()->where($this->pk, $id)->update($ligne);
    }

    public function trouver($id)
    {
        try {
            return $this->requete()->where($this->pk, $id)->first();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function pkEstIdentity(): bool
    {
        try {
            $v = DB::connection($this->connexion)->selectOne(
                "SELECT COLUMNPROPERTY(OBJECT_ID('dbo.{$this->table}'),'{$this->pk}','IsIdentity') AS est_identity"
            );

            return (bool) ($v->est_identity ?? false);
        } catch (Throwable $e) {
            return false; // SQLite des tests : compteur manuel
        }
    }

    private function prochainId(): int
    {
        try {
            return ((int) $this->requete()->max($this->pk)) + 1;
        } catch (Throwable $e) {
            return 1;
        }
    }
}
