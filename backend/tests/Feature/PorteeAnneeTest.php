<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * L'année choisie dans l'en-tête doit borner TOUTES les informations rattachées à une
 * année : élèves, classes, niveaux, enseignants, absences, notes, moyennes, documents,
 * rapports et tableau de bord.
 *
 * Difficulté couverte ici : ECONOMAT n'a pas une convention unique — certaines tables
 * stockent le libellé (AnneeAcad, ANNEE), d'autres le code (CodeAnnee). Le filtre porte
 * donc sur les deux formes.
 */
class PorteeAnneeTest extends TestCase
{
    private const A = '2024-2025';   // libellé de l'année passée
    private const CODE_A = '2024';
    private const B = '2025-2026';   // libellé de l'année en cours
    private const CODE_B = '2025';

    private array $spa = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->withHeaders($this->spa);
        $this->actingAs($rh, 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        $eco('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => self::CODE_A, 'LibelleAnnee' => self::A,
                'Activer' => false, 'ClotureDefinitive' => false, 'DEBUT' => '2024-09-01', 'FIN' => '2025-07-31'],
            ['CODE' => 2, 'CodeAnnee' => self::CODE_B, 'LibelleAnnee' => self::B,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01', 'FIN' => '2026-07-31'],
        ]);

        // Un jeu complet par année, pour vérifier qu'on ne voit jamais les deux à la fois.
        $eco('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'Nom' => 'Passe', 'Prenom' => 'Ancien', 'AnneeAcad' => self::A, 'CodeClasse' => 'CA'],
            ['Code' => 2, 'Matricule' => 'B1', 'Nom' => 'Cours', 'Prenom' => 'Actuel', 'AnneeAcad' => self::B, 'CodeClasse' => 'CB'],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CA', 'LibelleClasse' => 'Classe passée', 'CodN' => 'N1', 'ANNEE' => self::A],
            ['num' => 2, 'CodeClasse' => 'CB', 'LibelleClasse' => 'Classe actuelle', 'CodN' => 'N1', 'ANNEE' => self::B],
        ]);
        $eco('T_NIVEAU')->insert([
            ['Num' => 1, 'CodeNiveau' => 'NA', 'LibelleNiveau' => 'Niveau passé', 'CodeCycle' => 'PRIM', 'ANNEE' => self::A, 'Ordre' => 1],
            ['Num' => 2, 'CodeNiveau' => 'NB', 'LibelleNiveau' => 'Niveau actuel', 'CodeCycle' => 'PRIM', 'ANNEE' => self::B, 'Ordre' => 1],
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 1, 'MatriculeProfesseur' => 'PA', 'NomProfesseur' => 'ProfPasse', 'CodeAnnee' => self::CODE_A],
            ['Code' => 2, 'MatriculeProfesseur' => 'PB', 'NomProfesseur' => 'ProfActuel', 'CodeAnnee' => self::CODE_B],
        ]);
        $eco('T_ABSENCEELEVE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'CodeClasse' => 'CA', 'Date' => now()->toDateString(), 'AnneeCour' => self::A, 'Justifier' => false],
            ['Code' => 2, 'Matricule' => 'B1', 'CodeClasse' => 'CB', 'Date' => now()->toDateString(), 'AnneeCour' => self::B, 'Justifier' => false],
        ]);
        $eco('V_NOTECLASSE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'Nom' => 'Passe', 'Note' => 8, 'CodeMatiere' => 'M1',
                'LibelleMatiere' => 'Maths', 'CodeClasse' => 'CA', 'CodeAnnee' => self::CODE_A, 'Coefficient' => 2],
            ['Code' => 2, 'Matricule' => 'B1', 'Nom' => 'Cours', 'Note' => 16, 'CodeMatiere' => 'M1',
                'LibelleMatiere' => 'Maths', 'CodeClasse' => 'CB', 'CodeAnnee' => self::CODE_B, 'Coefficient' => 2],
        ]);
        $eco('V_MOYENNE_ELEVE_CLASSE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'Nom' => 'Passe', 'Moyenne' => 8, 'Rang' => '1',
                'CodeEleve' => 1, 'CodeClasse' => 'CA', 'CodeAnnee' => self::CODE_A],
            ['Code' => 2, 'Matricule' => 'B1', 'Nom' => 'Cours', 'Moyenne' => 16, 'Rang' => '1',
                'CodeEleve' => 2, 'CodeClasse' => 'CB', 'CodeAnnee' => self::CODE_B],
        ]);
        $eco('T_PREREQUIS')->insert([
            ['CODES' => 1, 'LIBELLE' => 'Doc passé', 'ANNEE' => self::A],
            ['CODES' => 2, 'LIBELLE' => 'Doc actuel', 'ANNEE' => self::B],
        ]);
    }

    private function choisir(string $annee): void
    {
        $this->postJson('/api/v1/contexte/annee', ['annee' => $annee])->assertOk();
    }

    /** Ce que chaque écran doit montrer, année par année. */
    private static function attendus(): array
    {
        return [
            // [url, chemin JSON, valeur année A, valeur année B]
            ['/api/v1/eleves', 'data.0.nom', 'Passe', 'Cours'],
            ['/api/v1/inscriptions', 'data.0.nom', 'Passe', 'Cours'],
            ['/api/v1/classes', 'data.0.nom', 'Classe passée', 'Classe actuelle'],
            ['/api/v1/enseignants', 'data.0.nom', 'ProfPasse', 'ProfActuel'],
            ['/api/v1/absences', 'data.0.matricule', 'A1', 'B1'],
            ['/api/v1/notes', 'data.0.matricule', 'A1', 'B1'],
            ['/api/v1/parametres/prerequis', 'data.0.libelle', 'Doc passé', 'Doc actuel'],
        ];
    }

    public function test_par_defaut_seules_les_donnees_de_l_annee_active_sont_visibles(): void
    {
        foreach (self::attendus() as [$url, $chemin, $_, $attenduB]) {
            $r = $this->getJson($url)->assertOk();
            $this->assertCount(1, $r->json('data'), "Deux années visibles sur {$url}");
            $this->assertSame($attenduB, $r->json($chemin), "Mauvaise année sur {$url}");
        }
    }

    public function test_changer_d_annee_deplace_toutes_les_donnees(): void
    {
        $this->choisir(self::A);

        foreach (self::attendus() as [$url, $chemin, $attenduA, $_]) {
            $r = $this->getJson($url)->assertOk();
            $this->assertCount(1, $r->json('data'), "Deux années visibles sur {$url}");
            $this->assertSame($attenduA, $r->json($chemin), "L'année choisie n'est pas appliquée sur {$url}");
        }
    }

    public function test_les_niveaux_suivent_aussi_l_annee(): void
    {
        // Les niveaux ne sont pas paginés : la réponse est une liste simple.
        $this->assertSame('Niveau actuel', $this->getJson('/api/v1/niveaux')->assertOk()->json('0.libelle'));

        $this->choisir(self::A);
        $this->assertSame('Niveau passé', $this->getJson('/api/v1/niveaux')->assertOk()->json('0.libelle'));
    }

    public function test_le_tableau_de_bord_suit_l_annee(): void
    {
        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();
        $this->assertSame(self::B, $r->json('annee_scolaire_active'));
        $this->assertSame(1, $r->json('effectifs.total_eleves'));
        $this->assertEqualsWithDelta(16, $r->json('moyenne_generale'), 0.01);
        $this->assertSame('Classe actuelle', $r->json('effectif_par_classe.0.classe'));

        $this->choisir(self::A);

        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();
        $this->assertSame(self::A, $r->json('annee_scolaire_active'));
        $this->assertEqualsWithDelta(8, $r->json('moyenne_generale'), 0.01);
        $this->assertSame('Classe passée', $r->json('effectif_par_classe.0.classe'));
    }

    public function test_les_rapports_suivent_l_annee(): void
    {
        // La classe est adressée par sa clé de route ; ses moyennes viennent de l'année.
        $r = $this->getJson('/api/v1/classes/CB/moyennes')->assertOk();
        $this->assertSame(self::B, $r->json('annee'));
        $this->assertEqualsWithDelta(16, $r->json('classement.0.moyenne'), 0.01);

        $r = $this->getJson('/api/v1/classes/CB/assiduite')->assertOk();
        $this->assertSame('B1', $r->json('eleves.0.matricule'));

        $r = $this->getJson('/api/v1/evaluations')->assertOk();
        $this->assertCount(1, $r->json());
        $this->assertSame('CB', $r->json('0.classe_code'));

        $this->choisir(self::A);

        $r = $this->getJson('/api/v1/evaluations')->assertOk();
        $this->assertSame('CA', $r->json('0.classe_code'));
    }

    public function test_un_filtre_explicite_prime_sur_l_annee_de_l_en_tete(): void
    {
        // L'en-tête est sur l'année en cours, mais on demande explicitement l'autre.
        $r = $this->getJson('/api/v1/inscriptions?annee='.self::A)->assertOk();

        $this->assertCount(1, $r->json('data'));
        $this->assertSame('Passe', $r->json('data.0.nom'));
    }

    public function test_le_choix_de_l_annee_persiste_entre_les_appels(): void
    {
        $this->choisir(self::A);

        $this->getJson('/api/v1/contexte')->assertOk()->assertJsonPath('annee', self::A);
        $this->assertSame('Passe', $this->getJson('/api/v1/eleves')->assertOk()->json('data.0.nom'));
    }
}
