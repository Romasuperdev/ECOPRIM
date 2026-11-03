<?php

namespace Tests\Feature;

use App\Services\CorProfClasseEcrivain;
use App\Services\EconomatTable;
use App\Services\MatieresEnseignantEcrivain;
use App\Services\RhUserEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\CoherenceScolaire;
use App\Support\ConflitsEmploiDuTemps;
use App\Support\DependancesAffectation;
use App\Support\DependancesReferentiel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Écritures qui réussissaient EN SILENCE alors que la lecture de contrôle avait échoué :
 * zéro dépendance, aucun doublon, année ouverte, login libre, Id 1… Désormais l'écriture
 * est refusée (503 clair) ou l'erreur remonte — et seule une colonne VRAIMENT absente de
 * l'installation reste tolérée.
 */
class LecturesControleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();
    }

    /** Fait échouer, sur une connexion, les requêtes dont le SQL contient ce fragment. */
    private function fairEchouer(string $connexion, string $fragment): void
    {
        DB::connection($connexion)->beforeExecuting(function ($sql) use ($fragment) {
            if (stripos($sql, $fragment) !== false) {
                throw new RuntimeException('Panne simulée : '.$sql);
            }
        });
    }

    private function attendre503(callable $operation): HttpException
    {
        try {
            $operation();
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());

            return $e;
        }

        $this->fail('L’opération aurait dû être refusée (503).');
    }

    // --- Suppressions : un comptage en échec ne vaut plus zéro ---

    public function test_la_suppression_est_refusee_quand_le_comptage_des_dependances_echoue(): void
    {
        $this->fairEchouer('economat', 'from "T_ETUDIANT"');

        $e = $this->attendre503(fn () => DependancesReferentiel::assertRetraitPossible(
            [['table' => 'T_ETUDIANT', 'colonne' => 'CodeClasse', 'libelle' => 'élève']], 'CP1A', 'La classe'
        ));

        $this->assertStringContainsString('suppression refusée', $e->getMessage());
        $this->assertStringNotContainsString('T_ETUDIANT', $e->getMessage());
    }

    public function test_une_table_vraiment_absente_ne_bloque_pas_la_suppression(): void
    {
        DependancesReferentiel::assertRetraitPossible(
            [['table' => 'T_TABLE_INEXISTANTE', 'colonne' => 'CodeClasse', 'libelle' => 'ligne']], 'CP1A', 'La classe'
        );

        $this->assertTrue(true);
    }

    public function test_le_retrait_d_une_affectation_est_refuse_quand_le_comptage_echoue(): void
    {
        $this->fairEchouer('economat', 'from "T_EMPLOIDUTEMPS"');

        $this->attendre503(fn () => DependancesAffectation::assertRetraitPossible('CP1A', 'MATH', '2025-2026'));

        // À l'affichage, le comptage reste tolérant.
        $this->assertSame(0, DependancesAffectation::compter('CP1A', 'MATH', '2025-2026')['creneaux']);
    }

    // --- Année clôturée : un verdict impossible n'ouvre plus l'année ---

    public function test_une_colonne_de_cloture_absente_refuse_l_ecriture(): void
    {
        Schema::connection('economat')->table('T_ANNEEACADEMIQUE', fn ($t) => $t->dropColumn('ClotureDefinitive'));
        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert(['CODE' => 1, 'LibelleAnnee' => '2025-2026']);

        $this->attendre503(fn () => AnneeScolaireGuard::assertModifiable('2025-2026', 'La saisie'));

        // L'indicateur d'écran, lui, ne casse pas.
        $this->assertFalse(AnneeScolaireGuard::estCloturee('2025-2026'));
        $this->assertNull(AnneeScolaireGuard::verdict('2025-2026'));
    }

    public function test_une_annee_ouverte_reste_modifiable(): void
    {
        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 1, 'LibelleAnnee' => '2025-2026', 'ClotureDefinitive' => false,
        ]);

        AnneeScolaireGuard::assertModifiable('2025-2026');
        $this->assertFalse(AnneeScolaireGuard::estCloturee('2025-2026'));
    }

    // --- Emploi du temps et cohérence : contrôles indispensables ---

    public function test_un_creneau_est_refuse_quand_les_conflits_ne_peuvent_pas_etre_lus(): void
    {
        $this->fairEchouer('economat', 'from "T_EMPLOIDUTEMPS"');

        $this->attendre503(fn () => ConflitsEmploiDuTemps::assertLibre([
            'annee' => '2025-2026', 'jour' => 1, 'heure' => 1, 'classe' => 'CP1A', 'matiere' => 'MATH',
        ]));
    }

    public function test_un_creneau_est_refuse_quand_les_affectations_ne_peuvent_pas_etre_lues(): void
    {
        $this->fairEchouer('economat', 'from "T_CORPROFCLASSE"');

        $this->attendre503(fn () => ConflitsEmploiDuTemps::assertLibre([
            'annee' => '2025-2026', 'jour' => 1, 'heure' => 1, 'classe' => 'CP1A', 'matiere' => 'MATH',
        ]));
    }

    public function test_une_classe_invérifiable_refuse_l_inscription(): void
    {
        Schema::connection('economat')->drop('T_CLASSE');

        $this->attendre503(fn () => CoherenceScolaire::assertClasse('CP1A', 'CP1', '2025-2026'));
    }

    // --- Services d'écriture : l'erreur remonte ---

    public function test_login_existe_leve_une_exception_si_la_lecture_echoue(): void
    {
        Schema::connection('master')->drop('RH_USER');

        $this->expectException(QueryException::class);

        (new RhUserEcrivain)->loginExiste('nouveau');
    }

    public function test_un_compte_n_est_pas_cree_avec_l_id_1_si_le_max_est_illisible(): void
    {
        $this->fairEchouer('master', 'max(');

        try {
            (new RhUserEcrivain)->creer(['login' => 'nouveau', 'nom' => 'N', 'prenom' => 'P', 'mot_de_passe' => 'secret']);
            $this->fail('La création aurait dû échouer.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Panne simulée', $e->getMessage());
        }

        $this->assertSame(0, DB::connection('master')->table('RH_USER')->count());
    }

    public function test_les_comptes_crees_recoivent_des_ids_successifs(): void
    {
        $ecrivain = new RhUserEcrivain;
        $a = $ecrivain->creer(['login' => 'a', 'mot_de_passe' => 'x']);
        $b = $ecrivain->creer(['login' => 'b', 'mot_de_passe' => 'x']);

        $this->assertSame([1, 2], [$a, $b]);
    }

    public function test_economat_table_n_insere_rien_si_le_max_est_illisible(): void
    {
        $table = EconomatTable::pour('T_MATIERE', 'Code');
        $this->assertSame(1, $table->inserer(['CodeMatiere' => 'MATH']));
        $this->assertSame(2, $table->inserer(['CodeMatiere' => 'FR']));

        $this->fairEchouer('economat', 'max(');

        try {
            $table->inserer(['CodeMatiere' => 'ANG']);
            $this->fail('L’insertion aurait dû échouer.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Panne simulée', $e->getMessage());
        }

        $this->assertSame(2, DB::connection('economat')->table('T_MATIERE')->count());
    }

    public function test_les_matieres_d_un_enseignant_ne_sont_pas_reinserees_si_la_lecture_echoue(): void
    {
        $ecrivain = new MatieresEnseignantEcrivain;
        $ecrivain->definir('P-1', '2025-2026', ['MATH', 'FR']);

        $this->fairEchouer('economat', 'select "CodeMatiere" from "T_CORPROFMAT"');

        try {
            $ecrivain->definir('P-1', '2025-2026', ['MATH', 'FR']);
            $this->fail('La mise à jour aurait dû échouer.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Panne simulée', $e->getMessage());
        }

        $this->assertSame(2, DB::connection('economat')->table('T_CORPROFMAT')->count());
        // L'affichage, lui, reste tolérant.
        $this->assertSame([], $ecrivain->pour('P-1', '2025-2026'));
    }

    public function test_designer_un_titulaire_ne_reussit_pas_en_silence(): void
    {
        $ecrivain = new CorProfClasseEcrivain;
        $code = $ecrivain->creer(['classe' => 'CP1A', 'matiere' => 'MATH', 'enseignant' => 1, 'annee' => '2025-2026']);

        $this->fairEchouer('economat', 'update "T_CORPROFCLASSE"');

        $this->expectException(RuntimeException::class);
        $ecrivain->definirTitulaire('CP1A', '2025-2026', $code);
    }

    public function test_sans_colonne_principale_designer_un_titulaire_est_sans_objet(): void
    {
        Schema::connection('economat')->table('T_CORPROFCLASSE', fn ($t) => $t->dropColumn('Principale'));
        $ecrivain = new CorProfClasseEcrivain;
        $code = $ecrivain->creer(['classe' => 'CP1A', 'matiere' => 'MATH', 'enseignant' => 1, 'annee' => '2025-2026']);

        $ecrivain->definirTitulaire('CP1A', '2025-2026', $code);

        $this->assertTrue(true);
    }

    // --- Commande d'import des coefficients ---

    public function test_l_import_des_coefficients_echoue_si_le_referentiel_est_illisible(): void
    {
        Schema::connection('economat')->drop('T_MATIERE');

        $chemin = tempnam(sys_get_temp_dir(), 'coef').'.csv';
        file_put_contents($chemin, "matiere;niveau;coefficient;note_max\nMATH;CP1;2;20\n");

        $code = Artisan::call('coefficients:import', ['fichier' => $chemin, '--simulation' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('rien n\'a été importé', Artisan::output());
        @unlink($chemin);
    }
}
