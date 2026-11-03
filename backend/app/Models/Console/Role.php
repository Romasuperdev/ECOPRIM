<?php

namespace App\Models\Console;

use App\Models\RhUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Catalogue de rôles ECOPRIM (affectés au niveau utilisateur↔établissement). */
class Role extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'console_roles';

    protected $fillable = ['code', 'nom', 'societe_code'];

    /**
     * Rôle technique d'une affectation SANS rôle : console_affectations.role_id est
     * obligatoire en base, on y range donc ce rôle-là. Il ne figure dans aucun catalogue
     * (RoleController::index) et ne porte aucune permission : le compte reçoit les droits
     * par défaut d'un compte sans rôle (Permissions::SANS_ROLE).
     */
    public const CODE_SANS_ROLE = 'sans-role';

    public static function sansRole(): self
    {
        return self::firstOrCreate(['code' => self::CODE_SANS_ROLE], ['nom' => 'Sans rôle', 'societe_code' => null]);
    }

    public function estSansRole(): bool
    {
        return (string) $this->code === self::CODE_SANS_ROLE;
    }

    /**
     * Rôle d'administration de la console, reconnu par son CODE exact et lui seul
     * (liste fermée RhUser::ROLES_ADMINISTRATION). Le nom n'entre jamais en compte.
     */
    public function estRoleAdministration(): bool
    {
        return in_array((string) $this->code, RhUser::ROLES_ADMINISTRATION, true);
    }

    public function estRoleAdminSociete(): bool
    {
        return (string) $this->code === RhUser::ROLE_ADMIN_SOCIETE;
    }

    /**
     * Un code ou un nom de rôle qui se ferait passer pour un rôle d'administration :
     * « ADMIN SOCIÉTÉ », « admin_societe », « Administrateur de l'établissement »,
     * « Super-Admin »... La comparaison se fait sur une forme normalisée — minuscules,
     * sans accents, sans espaces, tirets ni ponctuation — pour qu'aucune variante de
     * graphie ne passe.
     *
     * Ces rôles-là ne se créent pas depuis l'écran : ils sont semés une fois pour toutes
     * (php artisan console:importer) et reconnus par leur code. Un homonyme créé à côté ne
     * donnerait aucun droit, mais tromperait celui qui lit la fiche d'un compte.
     */
    public static function ressembleARoleAdministration(?string ...$libelles): bool
    {
        foreach ($libelles as $libelle) {
            $forme = self::normaliser($libelle);
            if ($forme === '') {
                continue;
            }

            foreach (RhUser::ROLES_ADMINISTRATION as $code) {
                if ($forme === self::normaliser($code)) {
                    return true;
                }
            }

            $motifs = [
                '/^super(admin|administrateur|administration)/',
                '/^(admin|administrateur|administration)(de|du|des|dela|del|d|l|la)*(societe|soc|etablissement|etab|ecole|general|generale|global|globale|supreme)/',
                '/^(societe|soc|etablissement|etab|ecole)(admin|administrateur|administration)$/',
            ];
            foreach ($motifs as $motif) {
                if (preg_match($motif, $forme)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normaliser(?string $libelle): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $libelle))) ?? '';
    }

    /**
     * Codes de permission accordés à ce rôle (App\Support\Permissions::CATALOGUE).
     * Pas de relation Eloquent : seul le code de permission (une chaîne, pas un modèle)
     * porte du sens côté pivot `console_role_permissions`.
     */
    public function permissionCodes(): array
    {
        return $this->newQuery()->getConnection()
            ->table('console_role_permissions')
            ->where('role_id', $this->id)
            ->pluck('permission_code')
            ->all();
    }
}
