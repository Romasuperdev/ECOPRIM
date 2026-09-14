<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Tests\TestCase;

/**
 * Garde-fou du socle : aucun modèle ne doit hériter de la connexion par défaut.
 *
 * `DB_CONNECTION=economat` fait pointer le défaut de Laravel sur la base de production
 * partagée. Un modèle sans `$connection` explicite y atterrit donc silencieusement — et
 * s'il vise une table qui n'y existe pas, la page répond 500 sans qu'on comprenne pourquoi.
 * C'est exactement ce qui est arrivé à dix endpoints du projet.
 *
 * Ce test rend la faute impossible à réintroduire : tout nouveau modèle doit dire où il vit.
 */
class SocleConnexionsTest extends TestCase
{
    /** @return array<int, class-string<Model>> */
    private function modeles(): array
    {
        $racine = app_path('Models');
        $classes = [];

        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));
        foreach ($fichiers as $fichier) {
            if ($fichier->isDir() || $fichier->getExtension() !== 'php') {
                continue;
            }

            $relatif = str_replace([$racine.DIRECTORY_SEPARATOR, '.php'], '', $fichier->getPathname());
            $classe = 'App\\Models\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relatif);

            if (! class_exists($classe)) {
                continue;
            }
            $reflexion = new ReflectionClass($classe);
            if ($reflexion->isAbstract() || ! $reflexion->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $classe;
        }

        sort($classes);

        return $classes;
    }

    public function test_tous_les_modeles_declarent_leur_connexion(): void
    {
        $modeles = $this->modeles();

        // Si ce compte tombe à zéro, c'est le test qui est cassé, pas le code.
        $this->assertGreaterThan(10, count($modeles), 'Aucun modèle trouvé : le parcours est fautif.');

        $implicites = [];
        foreach ($modeles as $classe) {
            if ((new $classe)->getConnectionName() === null) {
                $implicites[] = $classe;
            }
        }

        $this->assertSame([], $implicites,
            "Ces modèles héritent de la connexion par défaut (économat en production) :\n  "
            .implode("\n  ", $implicites)
            ."\nDéclarez `protected \$connection` : 'economat' (partagé), 'master' (dbmasterbacou) ou 'ecoprim' (base propre).");
    }

    public function test_chaque_modele_vise_une_connexion_connue(): void
    {
        $connues = ['economat', 'master', 'ecoprim'];

        $inconnues = [];
        foreach ($this->modeles() as $classe) {
            $connexion = (new $classe)->getConnectionName();
            if ($connexion !== null && ! in_array($connexion, $connues, true)) {
                $inconnues[$classe] = $connexion;
            }
        }

        $this->assertSame([], $inconnues,
            'Connexion inconnue : '.json_encode($inconnues, JSON_UNESCAPED_SLASHES));
    }

    public function test_les_tables_propres_sont_bien_sur_la_base_propre(): void
    {
        // Ce qui appartient à NEXORA ne doit jamais être créé dans ECONOMAT.
        foreach ($this->modeles() as $classe) {
            $modele = new $classe;
            $table = $modele->getTable();

            if (str_starts_with($table, 'console_') || str_starts_with($table, 'EP_')) {
                $this->assertSame('ecoprim', $modele->getConnectionName(),
                    "{$classe} porte une table propre ({$table}) mais vit sur "
                    .var_export($modele->getConnectionName(), true).'.');
            }
        }

        $this->assertTrue(true);
    }
}
