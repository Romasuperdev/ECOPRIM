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
 *
 * Compteur manuel : MAX + 1 puis INSERT, dans UNE transaction, le MAX lu sous verrou
 * (UPDLOCK, HOLDLOCK) — deux enregistrements simultanés ne prennent plus le même numéro.
 * Une lecture du MAX en échec n'est plus « 1 » : l'erreur remonte, rien n'est écrit.
 */
class EconomatTable
{
    /** Verrou SQL Server posé sur la lecture du MAX ; ignoré par SQLite (tests). */
    public const VERROU_COMPTEUR = 'WITH (UPDLOCK, HOLDLOCK)';

    /** « La clé est-elle IDENTITY ? », mémorisé par connexion.table pour la durée du processus. */
    private static array $identites = [];

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

        return DB::connection($this->connexion)->transaction(function () use ($ligne) {
            $id = $this->prochainId();
            $this->requete()->insert($ligne + [$this->pk => $id]);

            return $id;
        });
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
        $connexion = DB::connection($this->connexion);

        // Hors SQL Server (SQLite des tests), COLUMNPROPERTY n'existe pas : compteur manuel.
        if ($connexion->getDriverName() !== 'sqlsrv') {
            return false;
        }

        $cle = $this->connexion.'.'.$this->table.'.'.$this->pk;
        if (array_key_exists($cle, self::$identites)) {
            return self::$identites[$cle];
        }

        // Une erreur ici remonte : deviner « compteur manuel » sur une colonne IDENTITY
        // ferait échouer l'INSERT, et l'inverse écrirait une clé nulle.
        $v = $connexion->selectOne(
            'SELECT COLUMNPROPERTY(OBJECT_ID(?), ?, \'IsIdentity\') AS est_identity',
            ['dbo.'.$this->table, $this->pk]
        );

        return self::$identites[$cle] = (bool) ($v->est_identity ?? false);
    }

    /** MAX + 1, lu sous verrou : à appeler DANS la transaction de l'INSERT. */
    private function prochainId(): int
    {
        return ((int) $this->requete()->lock(self::VERROU_COMPTEUR)->max($this->pk)) + 1;
    }
}
