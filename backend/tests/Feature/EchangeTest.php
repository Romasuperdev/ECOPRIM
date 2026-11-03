<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Import et export Excel.
 *
 * Deux exigences sont éprouvées ici, et la seconde compte plus que la première :
 *   - l'export rend ce qu'il doit rendre, et RIEN de plus — même année, même établissement
 *     que les écrans. Un export qui déborderait du périmètre serait une porte dérobée ;
 *   - l'import ANNONCE avant d'écrire, écrit ce qu'il a annoncé, et ne détruit jamais rien.
 *     C'est le seul geste de l'application qui touche des centaines de lignes d'un coup et
 *     dont on ne revient pas : NEXORA ne supprime pas dans ECONOMAT.
 */
class EchangeTest extends TestCase
{
    private const ANNEE = '2025-2026';

    /** Les onglets d'un classeur d'année entière, dans l'ordre où ils s'importent. */
    private const FEUILLES_ANNEE = [
        'Niveaux', 'Classes', 'Matieres', 'Enseignants', 'Eleves', 'Notes', 'Absences', 'Evaluations',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        config(['database.connections.ecoprim' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('ecoprim');
        Artisan::call('migrate', [
            '--database' => 'ecoprim', '--path' => 'database/migrations/console',
            '--realpath' => false, '--force' => true,
        ]);

        $this->compte(1, 'secretaire', ['exporter_donnees', 'importer_donnees']);

        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs(RhUser::on('master')->find(1), 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        $eco('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1',
                'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E1'],
            ['num' => 2, 'CodeClasse' => 'CP2A', 'LibelleClasse' => 'CP2 A', 'CodN' => 'CP2',
                'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E2'],
            ['num' => 3, 'CodeClasse' => 'CM2A', 'LibelleClasse' => 'CM2 A', 'CodN' => 'CM2',
                'ANNEE' => '2024-2025', 'CODEETABLISSEMENT' => 'E1'],
        ]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques'],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 11, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa',
                'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 12, 'Matricule' => 'E-12', 'Nom' => 'Bamba', 'Prenom' => 'Ali',
                'CodeClasse' => 'CP2A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 13, 'Matricule' => 'E-13', 'Nom' => 'Ancien', 'Prenom' => 'Eleve',
                'CodeClasse' => 'CM2A', 'AnneeAcad' => '2024-2025'],
        ]);
    }

    /** Un compte du personnel porteur des permissions demandées. */
    private function compte(int $id, string $role, array $permissions): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $role.$id, 'Nom' => 'N', 'Prenom' => 'P', 'Email' => $role.$id.'@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false,
        ]);

        $eco = fn (string $t) => DB::connection('ecoprim')->table($t);
        $roleId = $eco('console_roles')->insertGetId(['code' => $role, 'nom' => ucfirst($role)]);
        foreach ($permissions as $code) {
            $eco('console_role_permissions')->insert(['role_id' => $roleId, 'permission_code' => $code]);
        }
        $eco('console_affectations')->insert([
            'rh_user_id' => $id, 'societe_code' => 'S1', 'etablissement_code' => 'E1',
            'role_id' => $roleId, 'actif' => true,
        ]);

        return $rh;
    }

    /**
     * Un fichier CSV déposé. Le format importe peu — le lecteur passe par IOFactory, qui
     * reconnaît xlsx comme csv — et un CSV se relit à l'œil quand un test tombe.
     */
    private function fichier(array $entetes, array $lignes, string $nom = 'import.csv'): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'nx').'.csv';
        $sortie = fopen($chemin, 'w');
        fputcsv($sortie, $entetes);
        foreach ($lignes as $ligne) {
            fputcsv($sortie, $ligne);
        }
        fclose($sortie);

        return new UploadedFile($chemin, $nom, 'text/csv', null, true);
    }

    /**
     * Le classeur qu'une réponse d'export renvoie. `Excel::download` rend un
     * BinaryFileResponse — un fichier déjà écrit sur le disque — et non un flux : on le
     * relit là où il est plutôt que d'en recopier le contenu.
     */
    private function classeur($reponse): Spreadsheet
    {
        return IOFactory::load($reponse->baseResponse->getFile()->getPathname());
    }

    private function entetesEleves(): array
    {
        return ['Matricule', 'Nom', 'Prénom', 'Sexe', 'Date de naissance', 'Classe'];
    }

    // --- Catalogue et export ---------------------------------------------------------

    /**
     * Ouvre aussi E2 au compte : sans établissement choisi, il voit alors E1 et E2, ce qui
     * laisse ces tests éprouver la borne de l'ANNÉE. Sans cette affectation, l'élève de E2
     * sortirait du périmètre — un compte ne voit que ses établissements.
     */
    private function affecterAussiE2(): void
    {
        $eco = fn (string $t) => DB::connection('ecoprim')->table($t);
        $eco('console_affectations')->insert([
            'rh_user_id' => 1, 'societe_code' => 'S1', 'etablissement_code' => 'E2',
            'role_id' => $eco('console_roles')->where('code', 'secretaire')->value('id'), 'actif' => true,
        ]);
    }

    public function test_le_catalogue_compte_les_lignes_de_l_annee_de_travail(): void
    {
        $this->affecterAussiE2();
        $reponse = $this->getJson('/api/v1/echanges/catalogue')->assertOk();

        $this->assertSame(self::ANNEE, $reponse->json('annee'));

        $jeux = collect($reponse->json('jeux'))->keyBy('code');

        // 2 élèves sur 3 : le troisième est sur l'année précédente.
        $this->assertSame(2, $jeux['eleves']['lignes']);
        // 2 classes sur 3, pour la même raison.
        $this->assertSame(2, $jeux['classes']['lignes']);
    }

    public function test_les_huit_jeux_s_importent(): void
    {
        $jeux = collect($this->getJson('/api/v1/echanges/catalogue')->json('jeux'))->keyBy('code');

        $this->assertCount(8, $jeux);
        foreach ($jeux as $code => $jeu) {
            $this->assertTrue($jeu['importable'], "Le jeu {$code} devrait s'importer.");
            $this->assertNull($jeu['pourquoi_pas']);
        }
    }

    public function test_l_export_rend_un_classeur_borne_a_l_annee(): void
    {
        $this->affecterAussiE2();
        $reponse = $this->get('/api/v1/echanges/export?jeux=eleves')->assertOk();

        $feuille = $this->classeur($reponse)->getActiveSheet()->toArray();

        $this->assertSame('Matricule', $feuille[0][0]);
        // En-tête + 2 élèves de l'année : l'élève de 2024-2025 n'y est pas.
        $this->assertCount(3, $feuille);
        // Triés par nom, comme la liste à l'écran : Bamba avant Kouassi.
        $this->assertSame(['E-12', 'E-11'], [$feuille[1][0], $feuille[2][0]]);
    }

    public function test_l_export_complet_porte_une_feuille_par_jeu(): void
    {
        $reponse = $this->get('/api/v1/echanges/export')->assertOk();

        $classeur = $this->classeur($reponse);

        $this->assertSame(8, $classeur->getSheetCount());
        $this->assertContains('Eleves', $classeur->getSheetNames());
        $this->assertContains('Notes', $classeur->getSheetNames());
    }

    public function test_l_export_se_borne_a_l_etablissement_de_travail(): void
    {
        $reponse = $this->withSession(['etablissement_code' => 'E1'])
            ->get('/api/v1/echanges/export?jeux=eleves')->assertOk();

        $feuille = $this->classeur($reponse)->getActiveSheet()->toArray();

        // E-12 est en CP2A, rattachée à E2 : elle sort du périmètre.
        $this->assertCount(2, $feuille);
        $this->assertSame('E-11', $feuille[1][0]);
    }

    public function test_un_oui_non_sort_en_clair_et_non_en_chiffre(): void
    {
        DB::connection('economat')->table('T_ABSENCEELEVE')->insert([
            ['Code' => 1, 'Matricule' => 'E-11', 'CodeClasse' => 'CP1A', 'Date' => '2025-10-02',
                'Cause' => 'Maladie', 'Justifier' => true, 'AnneeCour' => self::ANNEE],
            ['Code' => 2, 'Matricule' => 'E-11', 'CodeClasse' => 'CP1A', 'Date' => '2025-10-03',
                'Cause' => null, 'Justifier' => false, 'AnneeCour' => self::ANNEE],
        ]);

        $reponse = $this->get('/api/v1/echanges/export?jeux=absences')->assertOk();
        $feuille = $this->classeur($reponse)->getActiveSheet()->toArray();

        // SQL Server rend un `bit` en 1/0 : sur une feuille faite pour être lue, c'est
        // « Oui » et « Non » qu'on attend.
        $this->assertSame('Justifiée', $feuille[0][5]);
        $this->assertSame(['Oui', 'Non'], [$feuille[1][5], $feuille[2][5]]);
    }

    public function test_une_colonne_absente_de_la_base_part_vide_et_est_signalee(): void
    {
        // ECONOMAT est une base de production qu'on ne contrôle pas : une colonne renommée
        // ne doit pas vider la feuille entière sans explication.
        DB::connection('economat')->statement('ALTER TABLE T_MATIERE DROP COLUMN CodeCycle');
        \App\Services\Echange\CatalogueDonnees::oublierLeSchema();

        DB::connection('economat')->table('T_MATIERE')->where('Code', 1)->update(['LibelleMatiere' => 'Maths']);

        $jeux = collect($this->getJson('/api/v1/echanges/catalogue')->json('jeux'))->keyBy('code');
        $this->assertSame(['Cycle'], $jeux['matieres']['colonnes_introuvables']);

        $reponse = $this->get('/api/v1/echanges/export?jeux=matieres')->assertOk();
        $feuille = $this->classeur($reponse)->getActiveSheet()->toArray();

        // L'en-tête reste — sinon le fichier exporté et le modèle d'import n'auraient plus
        // les mêmes colonnes — et la matière est bien là.
        $this->assertSame(['Code', 'Libellé', 'Cycle'], $feuille[0]);
        $this->assertSame(['MATH', 'Maths', null], $feuille[1]);
    }

    public function test_le_modele_vierge_ne_contient_que_les_entetes(): void
    {
        $reponse = $this->get('/api/v1/echanges/modele?jeux=eleves')->assertOk();

        $feuille = $this->classeur($reponse)->getActiveSheet()->toArray();

        $this->assertCount(1, $feuille);
        $this->assertSame('Matricule', $feuille[0][0]);
    }

    public function test_le_modele_refuse_un_jeu_inconnu(): void
    {
        $this->getJson('/api/v1/echanges/modele?jeux=comptabilite')->assertStatus(422);
    }

    // --- Import : l'analyse n'écrit rien ---------------------------------------------

    public function test_l_analyse_annonce_sans_rien_ecrire(): void
    {
        $avant = DB::connection('economat')->table('T_ETUDIANT')->count();

        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-11', 'Kouassi', 'Awa', 'F', '12/03/2018', 'CP1A'],   // existe
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],  // à créer
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $this->assertSame(0, $rapport['rejets']);
        $this->assertNotEmpty($rapport['jeton']);

        $this->assertSame($avant, DB::connection('economat')->table('T_ETUDIANT')->count());
    }

    public function test_l_import_ecrit_ce_que_l_analyse_avait_annonce(): void
    {
        $jeton = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-11', 'Kouassi', 'Awa Corrigée', 'F', '12/03/2018', 'CP1A'],
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
            ]),
        ])->assertOk()->json('jeton');

        $bilan = $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])
            ->assertOk()->json('bilan');

        $this->assertSame(1, $bilan['creees']);
        $this->assertSame(1, $bilan['modifiees']);
        $this->assertSame([], $bilan['echecs']);

        // Un builder neuf à chaque fois : celui de Laravel est mutable, et le `where`
        // précédent resterait accroché à la requête suivante.
        $eleve = fn (string $matricule) => DB::connection('economat')->table('T_ETUDIANT')
            ->where('Matricule', $matricule);

        $this->assertSame('Awa Corrigée', $eleve('E-11')->value('Prenom'));

        $cree = $eleve('E-99')->first();
        $this->assertSame('CP1A', $cree->CodeClasse);
        // L'année vient du contexte de travail, jamais du fichier.
        $this->assertSame(self::ANNEE, $cree->AnneeAcad);
        $this->assertSame('2019-09-01', substr((string) $cree->DateNaiss, 0, 10));
    }

    public function test_un_eleve_absent_du_fichier_reste_inscrit(): void
    {
        $jeton = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-11', 'Kouassi', 'Awa', 'F', '12/03/2018', 'CP1A'],
            ]),
        ])->assertOk()->json('jeton');

        $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])->assertOk();

        // E-12 n'était pas dans le fichier : un import n'est pas une remise à plat.
        $this->assertTrue(
            DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-12')->exists()
        );
    }

    public function test_une_classe_inconnue_est_rejetee_et_dite(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'tout_importer' => '0', // mode strict
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CE1Z'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertSame(0, $rapport['creations']);
        $this->assertStringContainsString('CE1Z', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    public function test_un_matricule_en_double_dans_le_fichier_est_rejete(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            // Mode strict : « Tout importer » décoché.
            'tout_importer' => '0',
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
                ['E-99', 'Doublon', 'Fichier', 'F', '02/09/2019', 'CP1A'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('ligne 2', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    public function test_un_eleve_cree_sans_classe_est_rejete(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'tout_importer' => '0', // mode strict
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', ''],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
    }

    public function test_un_fichier_sans_aucune_colonne_reconnue_est_refuse(): void
    {
        $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier(['Colonne A', 'Colonne B'], [['x', 'y']]),
        ])->assertStatus(422)->assertJsonValidationErrors('fichier');
    }

    public function test_les_entetes_tolerent_la_casse_et_les_accents(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier(['MATRICULE', 'nom', 'Prenom', 'classe'], [
                ['E-99', 'Nouveau', 'Venu', 'CP1A'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame([], $rapport['feuilles'][0]['colonnes_inconnues']);
    }

    public function test_une_colonne_inconnue_est_signalee_sans_bloquer(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier(['Matricule', 'Nom', 'Prénom', 'Classe', 'Scolarité payée'], [
                ['E-99', 'Nouveau', 'Venu', 'CP1A', '150000'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(['Scolarité payée'], $rapport['feuilles'][0]['colonnes_inconnues']);
    }

    // --- Import : notes ---------------------------------------------------------------

    private function entetesNotes(): array
    {
        return ['Matricule', 'Classe', 'Matière', 'Session', 'Type', 'Note'];
    }

    public function test_les_notes_s_importent_puis_se_remplacent(): void
    {
        $poser = function (float $note) {
            $jeton = $this->postJson('/api/v1/echanges/import/notes/analyse', [
                'fichier' => $this->fichier($this->entetesNotes(), [
                    ['E-11', 'CP1A', 'MATH', 'S1', 'Composition', (string) $note],
                ]),
            ])->assertOk()->json('jeton');

            return $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])
                ->assertOk()->json('bilan');
        };

        $this->assertSame(1, $poser(12.5)['creees']);
        $this->assertSame(1, $poser(14)['modifiees']);

        // Une seule ligne, pas deux : ECONOMAT compterait les deux dans la moyenne.
        $details = DB::connection('economat')->table('T_NOTEDETAILS')
            ->where('Matricule', 'E-11')->get();
        $this->assertCount(1, $details);
        $this->assertEqualsWithDelta(14.0, $details->first()->Note, 0.001);
    }

    /**
     * ECONOMAT note l'année tantôt en code (« 2025 »), tantôt en libellé
     * (« 2025-2026 ») — les deux coexistent dans les mêmes tables. Une recherche stricte
     * manquait l'entête déjà saisie et en créait une SECONDE, qu'ECONOMAT aurait comptée en
     * plus de la première dans la moyenne. Défaut relevé en réimportant un export : les 16
     * notes qui venaient d'en sortir revenaient toutes en « création ».
     */
    public function test_une_entete_ecrite_avec_le_code_de_l_annee_est_retrouvee(): void
    {
        $eco = fn (string $t) => DB::connection('economat')->table($t);

        // Entête posée par ECONOMAT, avec le CODE de l'année et non son libellé.
        $eco('T_NOTEENTETE')->insert([
            'Code' => 500, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH',
            'CodeSession' => 'S1', 'CodeAnnee' => '2025', 'TypeNote' => 'Composition',
        ]);
        $eco('T_NOTEDETAILS')->insert([
            'Code' => 900, 'CodeNote' => 500, 'Matricule' => 'E-11', 'Note' => 8,
        ]);

        $rapport = $this->postJson('/api/v1/echanges/import/notes/analyse', [
            'fichier' => $this->fichier($this->entetesNotes(), [
                ['E-11', 'CP1A', 'MATH', 'S1', 'Composition', '16'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(0, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);

        $this->postJson('/api/v1/echanges/import', ['jeton' => $rapport['jeton']])->assertOk();

        // Une seule entête, une seule note : rien n'a été dupliqué.
        $this->assertSame(1, $eco('T_NOTEENTETE')->count());
        $this->assertSame(1, $eco('T_NOTEDETAILS')->count());
        $this->assertEqualsWithDelta(16.0, $eco('T_NOTEDETAILS')->value('Note'), 0.001);
    }

    public function test_une_note_portee_sur_un_eleve_d_une_autre_classe_est_rejetee(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/notes/analyse', [
            'tout_importer' => '0', // mode strict
            'fichier' => $this->fichier($this->entetesNotes(), [
                ['E-12', 'CP1A', 'MATH', 'S1', 'Composition', '15'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('CP2A', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    public function test_une_note_hors_bareme_est_rejetee(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/notes/analyse', [
            'fichier' => $this->fichier($this->entetesNotes(), [
                ['E-11', 'CP1A', 'MATH', 'S1', 'Composition', '25'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);

        // Sur 100, la même note passe : le barème est celui qu'on déclare.
        $surCent = $this->postJson('/api/v1/echanges/import/notes/analyse', [
            'fichier' => $this->fichier($this->entetesNotes(), [
                ['E-11', 'CP1A', 'MATH', 'S1', 'Composition', '25'],
            ]),
            'bareme' => 100,
        ])->assertOk()->json();

        $this->assertSame(1, $surCent['creations']);
    }

    public function test_une_note_sans_session_est_rejetee(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/notes/analyse', [
            'fichier' => $this->fichier($this->entetesNotes(), [
                ['E-11', 'CP1A', 'MATH', '', 'Composition', '15'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('Session', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    // --- Import : évaluations ---------------------------------------------------------

    public function test_une_evaluation_au_type_inconnu_est_rejetee(): void
    {
        $entetes = ['Titre', 'Classe', 'Matière', 'Type', 'Date', 'Coefficient', 'Note maximale'];

        $rapport = $this->postJson('/api/v1/echanges/import/evaluations/analyse', [
            // Mode strict : « Tout importer » décoché.
            'tout_importer' => '0',
            'fichier' => $this->fichier($entetes, [
                ['Compo 1', 'CP1A', 'MATH', 'Composition', '15/12/2025', '2', '20'],
                ['Interro', 'CP1A', 'MATH', 'Interrogation', '16/12/2025', '1', '20'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('Interrogation', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    public function test_reimporter_le_meme_fichier_met_a_jour_au_lieu_de_dupliquer(): void
    {
        $entetes = ['Titre', 'Classe', 'Matière', 'Type', 'Date', 'Coefficient', 'Note maximale'];
        $lignes = [['Compo 1', 'CP1A', 'MATH', 'Composition', '15/12/2025', '2', '20']];

        $importer = function () use ($entetes, $lignes) {
            $jeton = $this->postJson('/api/v1/echanges/import/evaluations/analyse', [
                'fichier' => $this->fichier($entetes, $lignes),
            ])->assertOk()->json('jeton');

            return $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])
                ->assertOk()->json('bilan');
        };

        $this->assertSame(1, $importer()['creees']);
        $this->assertSame(1, $importer()['modifiees']);
        $this->assertSame(1, DB::connection('ecoprim')->table('evaluations')->count());
    }

    // --- Import d'une année entière ---------------------------------------------------

    /** Un classeur à plusieurs feuilles, comme celui que l'export produit. */
    private function classeurMultiFeuilles(array $feuilles, string $nom = 'annee.xlsx'): UploadedFile
    {
        $classeur = new Spreadsheet;
        $classeur->removeSheetByIndex(0);

        foreach ($feuilles as $titre => $contenu) {
            $feuille = $classeur->createSheet();
            $feuille->setTitle($titre);
            $feuille->fromArray($contenu, null, 'A1');
        }

        $chemin = tempnam(sys_get_temp_dir(), 'nx').'.xlsx';
        IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function classeurAnnee(): UploadedFile
    {
        return $this->classeurMultiFeuilles([
            'Eleves' => [
                $this->entetesEleves(),
                ['E-11', 'Kouassi', 'Awa Corrigée', 'F', '12/03/2018', 'CP1A'],
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
            ],
            'Notes' => [
                $this->entetesNotes(),
                ['E-11', 'CP1A', 'MATH', 'S1', 'Composition', '12'],
                // Élève créé par la feuille d'à côté : il n'existe pas encore en base.
                ['E-99', 'CP1A', 'MATH', 'S1', 'Composition', '15'],
            ],
            'Evaluations' => [
                ['Titre', 'Classe', 'Matière', 'Type', 'Date', 'Coefficient', 'Note maximale'],
                ['Compo 1', 'CP1A', 'MATH', 'Composition', '15/12/2025', '2', '20'],
            ],
        ]);
    }

    public function test_l_annee_entiere_s_analyse_feuille_par_feuille(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurAnnee(),
        ])->assertOk()->json();

        $this->assertSame('annee', $rapport['jeu']);
        $this->assertCount(3, $rapport['feuilles']);
        $this->assertSame(
            ['eleves', 'notes', 'evaluations'],
            array_column($rapport['feuilles'], 'jeu'),
        );

        // Totaux : 1 élève créé + 1 mis à jour, 2 notes, 1 évaluation.
        $this->assertSame(5, $rapport['lignes']);
        $this->assertSame(4, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $this->assertSame(0, $rapport['rejets']);
    }

    /**
     * Le cas qui rend l'année entière utilisable : sans lui, la feuille Notes serait
     * analysée avant que les élèves n'existent, et toutes ses lignes seraient écartées.
     */
    public function test_une_note_portant_sur_un_eleve_cree_par_la_feuille_d_a_cote_passe(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurAnnee(),
        ])->assertOk()->json();

        $notes = collect($rapport['feuilles'])->firstWhere('jeu', 'notes');
        $this->assertSame(0, $notes['rejets']);
        $this->assertSame(2, $notes['creations']);
    }

    public function test_l_annee_entiere_s_applique_dans_l_ordre_eleves_puis_notes(): void
    {
        $jeton = $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurAnnee(),
        ])->assertOk()->json('jeton');

        $reponse = $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])
            ->assertOk()->json();

        $this->assertSame([], $reponse['bilan']['echecs']);
        $this->assertSame(4, $reponse['bilan']['creees']);
        $this->assertSame(1, $reponse['bilan']['modifiees']);
        $this->assertSame(
            ['eleves', 'notes', 'evaluations'],
            array_column($reponse['bilan_par_jeu'], 'jeu'),
        );

        // L'élève créé par la première feuille porte bien la note de la seconde.
        $this->assertTrue(
            DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-99')->exists()
        );
        $this->assertEqualsWithDelta(15.0, DB::connection('economat')->table('T_NOTEDETAILS')
            ->where('Matricule', 'E-99')->value('Note'), 0.001);
    }

    public function test_une_feuille_absente_du_classeur_est_dite_sans_bloquer_les_autres(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurMultiFeuilles([
                'Eleves' => [
                    $this->entetesEleves(),
                    ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
                ],
            ]),
        ])->assertOk()->json();

        $this->assertCount(1, $rapport['feuilles']);
        $this->assertSame(
            ['Niveaux', 'Classes', 'Matieres', 'Enseignants', 'Notes', 'Absences', 'Evaluations'],
            $rapport['feuilles_absentes'],
        );
        $this->assertSame(1, $rapport['creations']);
    }

    public function test_le_nom_des_onglets_tolere_la_casse_et_les_accents(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurMultiFeuilles([
                'ÉLÈVES' => [
                    $this->entetesEleves(),
                    ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
                ],
            ]),
        ])->assertOk()->json();

        $this->assertSame('eleves', $rapport['feuilles'][0]['jeu']);
        $this->assertSame('ÉLÈVES', $rapport['feuilles'][0]['feuille']);
    }

    public function test_un_classeur_sans_aucune_feuille_reconnue_est_refuse(): void
    {
        $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurMultiFeuilles([
                'Comptabilité' => [['Compte', 'Montant'], ['411', '1000']],
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors('fichier');
    }

    public function test_le_modele_d_annee_porte_les_huit_feuilles_dans_l_ordre_d_import(): void
    {
        $reponse = $this->get('/api/v1/echanges/modele?jeux=annee')->assertOk();
        $classeur = $this->classeur($reponse);

        $this->assertSame(self::FEUILLES_ANNEE, $classeur->getSheetNames());
        // Vierge : les en-têtes, et rien d'autre.
        $this->assertCount(1, $classeur->getSheetByName('Notes')->toArray());
    }

    public function test_le_catalogue_annonce_l_import_d_annee_entiere(): void
    {
        $annee = $this->getJson('/api/v1/echanges/catalogue')->assertOk()->json('import_annee');

        $this->assertSame('annee', $annee['code']);
        $this->assertSame(self::FEUILLES_ANNEE, $annee['feuilles']);
    }

    // --- Année neuve : les huit feuilles d'un coup -------------------------------------

    /**
     * Un classeur qui ouvre une année de rien : niveau, classe, matière, enseignant, élève,
     * puis sa note, son absence, une évaluation. Rien de tout cela n'est en base au moment
     * de l'analyse — chaque feuille est jugée sur ce que les précédentes vont créer.
     */
    private function classeurAnneeNeuve(): UploadedFile
    {
        return $this->classeurMultiFeuilles([
            // Onglets volontairement dans le désordre : l'ordre d'import ne dépend pas d'eux.
            'Notes' => [
                $this->entetesNotes(),
                ['E-50', 'CE1A', 'FR', 'S1', 'Composition', '14'],
            ],
            'Eleves' => [
                $this->entetesEleves(),
                ['E-50', 'Nouvelle', 'Élève', 'F', '02/02/2018', 'CE1A'],
            ],
            'Niveaux' => [['Code', 'Libellé', 'Ordre', 'Année'], ['CE1', 'Cours élémentaire 1', '3', self::ANNEE]],
            'Classes' => [['Code', 'Libellé', 'Niveau', 'Année'], ['CE1A', 'CE1 A', 'CE1', self::ANNEE]],
            'Matieres' => [['Code', 'Libellé', 'Cycle'], ['FR', 'Français', '']],
            'Enseignants' => [
                ['Matricule', 'Nom', 'Prénom', 'Sexe', 'Email', 'Téléphone'],
                ['P-50', 'Traoré', 'Issa', 'Masculin', 'issa@ecole.ci', '0707070707'],
            ],
            'Absences' => [
                ['Matricule', 'Classe', 'Date', 'Heure', 'Motif', 'Justifiée'],
                ['E-50', 'CE1A', '06/10/2025', '08:00', 'Malade', 'Oui'],
            ],
            'Evaluations' => [
                ['Titre', 'Classe', 'Matière', 'Type', 'Date', 'Coefficient', 'Note maximale'],
                ['Dictée 1', 'CE1A', 'FR', 'Composition', '15/12/2025', '1', '20'],
            ],
        ]);
    }

    public function test_une_annee_neuve_s_analyse_sans_aucun_rejet(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurAnneeNeuve(),
        ])->assertOk()->json();

        $this->assertSame(
            ['niveaux', 'classes', 'matieres', 'enseignants', 'eleves', 'notes', 'absences', 'evaluations'],
            array_column($rapport['feuilles'], 'jeu'),
        );
        $this->assertSame(0, $rapport['rejets'], json_encode(array_column($rapport['feuilles'], 'apercu')));
        $this->assertSame(8, $rapport['creations']);
        $this->assertSame([], $rapport['feuilles_absentes']);
    }

    public function test_une_annee_neuve_s_applique_en_entier(): void
    {
        $comptes = RhUser::on('master')->count();
        $jeton = $this->postJson('/api/v1/echanges/import/annee/analyse', [
            'fichier' => $this->classeurAnneeNeuve(),
        ])->assertOk()->json('jeton');

        $bilan = $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])->assertOk()->json('bilan');

        $this->assertSame([], $bilan['echecs']);
        $this->assertSame(8, $bilan['creees']);

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        // L'année est celle que le classeur mentionne (colonne Année) : ici l'année de travail.
        $this->assertSame(self::ANNEE, $eco('T_NIVEAU')->where('CodeNiveau', 'CE1')->value('ANNEE'));
        $classe = $eco('T_CLASSE')->where('CodeClasse', 'CE1A')->first();
        $this->assertSame(self::ANNEE, $classe->ANNEE);
        $this->assertSame('E1', $classe->CODEETABLISSEMENT);
        $this->assertSame('Français', $eco('T_MATIERE')->where('CodeMatiere', 'FR')->value('LibelleMatiere'));

        $prof = $eco('T_PROFESSEUR')->where('MatriculeProfesseur', 'P-50')->first();
        $this->assertSame('M', $prof->Sexe);
        $this->assertSame('E1', $prof->Site);
        // La fiche, pas le compte : aucun mot de passe n'a été fabriqué en masse.
        $this->assertNull($prof->LOGIN);
        $this->assertSame($comptes, RhUser::on('master')->count());

        // L'absence porte le code interne de l'élève créé par la feuille Élèves.
        $eleve = $eco('T_ETUDIANT')->where('Matricule', 'E-50')->value('Code');
        $absence = $eco('T_ABSENCEELEVE')->where('Matricule', 'E-50')->first();
        $this->assertSame((int) $eleve, (int) $absence->CodeEleve);
        $this->assertSame('CE1A', $absence->CodeClasse);
        $this->assertTrue((bool) $absence->Justifier);

        $this->assertEqualsWithDelta(14.0, $eco('T_NOTEDETAILS')->where('Matricule', 'E-50')->value('Note'), 0.001);
        $this->assertTrue(DB::connection('ecoprim')->table('evaluations')->where('titre', 'Dictée 1')->exists());
    }

    public function test_reimporter_l_annee_met_a_jour_sans_dupliquer(): void
    {
        foreach ([1, 2] as $passage) {
            $jeton = $this->postJson('/api/v1/echanges/import/annee/analyse', [
                'fichier' => $this->classeurAnneeNeuve(),
            ])->assertOk()->json('jeton');
            $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])->assertOk();
        }

        $eco = fn (string $t) => DB::connection('economat')->table($t);
        $this->assertSame(1, $eco('T_NIVEAU')->where('CodeNiveau', 'CE1')->count());
        $this->assertSame(1, $eco('T_CLASSE')->where('CodeClasse', 'CE1A')->count());
        $this->assertSame(1, $eco('T_MATIERE')->where('CodeMatiere', 'FR')->count());
        $this->assertSame(1, $eco('T_PROFESSEUR')->where('MatriculeProfesseur', 'P-50')->count());
        $this->assertSame(1, $eco('T_ABSENCEELEVE')->where('Matricule', 'E-50')->count());
    }

    /**
     * T_PROFESSEUR ne porte pas d'établissement : sans le périmètre de l'écran Enseignants,
     * l'export rendait ceux de toute la société — et l'import les rejetait ensuite.
     */
    public function test_l_export_des_enseignants_se_borne_a_l_etablissement(): void
    {
        DB::connection('economat')->table('T_PROFESSEUR')->insert([
            ['Code' => 1, 'MatriculeProfesseur' => 'P-E1', 'NomProfesseur' => 'Ici', 'Site' => 'E1'],
            ['Code' => 2, 'MatriculeProfesseur' => 'P-E2', 'NomProfesseur' => 'Ailleurs', 'Site' => 'E2'],
            ['Code' => 3, 'MatriculeProfesseur' => 'P-00', 'NomProfesseur' => 'Nulle part', 'Site' => null],
        ]);

        $reponse = $this->get('/api/v1/echanges/export?jeux=enseignants')->assertOk();
        $feuille = $this->classeur($reponse)->getSheetByName('Enseignants')->toArray();

        $this->assertSame(['P-E1'], array_column(array_slice($feuille, 1), 0));
    }

    /** Comme l'écran Classes : les classes des établissements du compte, sans établissement choisi. */
    public function test_l_export_des_classes_se_borne_aux_etablissements_du_compte(): void
    {
        $reponse = $this->get('/api/v1/echanges/export?jeux=classes')->assertOk();
        $feuille = $this->classeur($reponse)->getSheetByName('Classes')->toArray();

        // CP2A est en E2, hors des affectations du compte.
        $this->assertSame(['CP1A'], array_column(array_slice($feuille, 1), 0));
    }

    /** Comme l'écran Niveaux : un référentiel de l'année, partagé par les établissements. */
    public function test_l_export_des_niveaux_rend_tous_ceux_de_l_annee(): void
    {
        DB::connection('economat')->table('T_NIVEAU')->insert([
            ['Num' => 1, 'CodeNiveau' => 'CP1', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E2'],
            ['Num' => 2, 'CodeNiveau' => 'CP2', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => null],
            ['Num' => 3, 'CodeNiveau' => 'CM2', 'ANNEE' => '2024-2025', 'CODEETABLISSEMENT' => 'E1'],
        ]);

        $reponse = $this->get('/api/v1/echanges/export?jeux=niveaux')->assertOk();
        $feuille = $this->classeur($reponse)->getSheetByName('Niveaux')->toArray();

        $this->assertEqualsCanonicalizing(['CP1', 'CP2'], array_column(array_slice($feuille, 1), 0));
    }

    // --- Référentiels, enseignants, absences : les règles des écrans ------------------

    /** Le niveau saisi à la place de la classe : on suggère, on ne corrige pas. */
    public function test_une_classe_saisie_par_son_niveau_suggere_la_bonne_classe(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'tout_importer' => '0', // mode strict
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-70', 'Niveau', 'Saisi', 'F', '01/01/2018', 'CP1'],
                ['E-71', 'Faute', 'Frappe', 'M', '01/01/2018', 'XYZ'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(2, $rapport['rejets']);
        $motifs = array_column($rapport['feuilles'][0]['apercu'], 'motif');
        $this->assertStringContainsString('Vouliez-vous dire « CP1A »', $motifs[0]);
        $this->assertStringContainsString('Classes de l’année : CP1A.', $motifs[1]);
        $this->assertFalse(DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-70')->exists());
    }

    public function test_une_classe_au_niveau_inconnu_est_rejetee(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/classes/analyse', [
            // Mode strict : « Tout importer » décoché.
            'tout_importer' => '0',
            'fichier' => $this->fichier(['Code', 'Libellé', 'Niveau'], [['CE1A', 'CE1 A', 'CE1']]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('Niveau « CE1 » inconnu', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    public function test_une_classe_d_un_autre_etablissement_n_est_pas_reecrite(): void
    {
        DB::connection('economat')->table('T_NIVEAU')->insert([
            'Num' => 1, 'CodeNiveau' => 'CP2', 'LibelleNiveau' => 'CP2', 'ANNEE' => self::ANNEE,
        ]);

        // CP2A appartient à E2 ; le compte travaille sur E1.
        $rapport = $this->postJson('/api/v1/echanges/import/classes/analyse', [
            // Mode strict : « Tout importer » décoché.
            'tout_importer' => '0',
            'fichier' => $this->fichier(['Code', 'Libellé', 'Niveau'], [['CP2A', 'Renommée', 'CP2']]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('autre établissement', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    /** Un code de classe ne sert qu'une fois dans T_CLASSE (clé primaire), toutes années confondues. */
    public function test_un_code_de_classe_pris_par_une_autre_annee_est_rejete(): void
    {
        DB::connection('economat')->table('T_NIVEAU')->insert([
            'Num' => 1, 'CodeNiveau' => 'CM2', 'LibelleNiveau' => 'CM2', 'ANNEE' => self::ANNEE,
        ]);

        // CM2A existe en 2024-2025.
        $rapport = $this->postJson('/api/v1/echanges/import/classes/analyse', [
            // Mode strict : « Tout importer » décoché.
            'tout_importer' => '0',
            'fichier' => $this->fichier(['Code', 'Libellé', 'Niveau'], [['CM2A', 'CM2 A', 'CM2']]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('déjà pris par une classe de 2024-2025', $rapport['feuilles'][0]['apercu'][0]['motif']);
        $this->assertStringContainsString('« CM2A-2025 »', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    public function test_une_matiere_connue_est_mise_a_jour(): void
    {
        $jeton = $this->postJson('/api/v1/echanges/import/matieres/analyse', [
            'fichier' => $this->fichier(['Code', 'Libellé'], [['MATH', 'Mathématiques et calcul']]),
        ])->assertOk()->assertJsonPath('modifications', 1)->json('jeton');

        $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])->assertOk();

        $this->assertSame(1, DB::connection('economat')->table('T_MATIERE')->count());
        $this->assertSame('Mathématiques et calcul', DB::connection('economat')->table('T_MATIERE')
            ->where('CodeMatiere', 'MATH')->value('LibelleMatiere'));
    }

    public function test_un_enseignant_sans_nom_ou_a_l_email_invalide_est_rejete(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/enseignants/analyse', [
            // Mode strict : « Tout importer » décoché.
            'tout_importer' => '0',
            'fichier' => $this->fichier(['Matricule', 'Nom', 'Prénom', 'Email'], [
                ['P-1', '', 'Issa', ''],
                ['P-2', 'Koné', 'Ali', 'pas-un-email'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(2, $rapport['rejets']);
    }

    public function test_une_absence_dans_le_futur_ou_dans_la_mauvaise_classe_est_rejetee(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/absences/analyse', [
            // Mode strict : « Tout importer » décoché.
            'tout_importer' => '0',
            'fichier' => $this->fichier(['Matricule', 'Classe', 'Date'], [
                ['E-11', 'CP1A', now()->addDays(3)->format('d/m/Y')],
                // E-11 est en CP1A : la ligne est décalée.
                ['E-11', 'CP2A', '06/10/2025'],
                // E-12 est en CP2A, classe de E2 : hors de l'établissement de travail.
                ['E-12', '', '06/10/2025'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(3, $rapport['rejets']);
    }

    // --- Refus ------------------------------------------------------------------------

    public function test_un_jeu_inconnu_est_refuse(): void
    {
        $this->postJson('/api/v1/echanges/import/comptabilite/analyse', [
            'fichier' => $this->fichier(['Compte'], [['411']]),
        ])->assertStatus(422)->assertJsonValidationErrors('jeu');
    }

    public function test_le_jeton_d_un_autre_utilisateur_est_refuse(): void
    {
        $jeton = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
            ]),
        ])->assertOk()->json('jeton');

        $autre = $this->compte(2, 'autre', ['exporter_donnees', 'importer_donnees']);
        $this->actingAs($autre, 'sanctum');

        $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])
            ->assertStatus(422)->assertJsonValidationErrors('jeton');

        $this->assertFalse(
            DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-99')->exists()
        );
    }

    public function test_un_jeton_inconnu_demande_de_redeposer_le_fichier(): void
    {
        $this->postJson('/api/v1/echanges/import', ['jeton' => str_repeat('a', 32)])
            ->assertStatus(422)->assertJsonValidationErrors('jeton');
    }

    public function test_une_annee_cloturee_refuse_l_import_mais_laisse_exporter(): void
    {
        DB::connection('economat')->table('T_ANNEEACADEMIQUE')
            ->where('CODE', 1)->update(['ClotureDefinitive' => true]);
        \App\Support\AnneeScolaireGuard::oublier();

        $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
            ]),
        ])->assertStatus(422);

        // Une année close est précisément celle qu'on veut archiver.
        $this->get('/api/v1/echanges/export?jeux=eleves')->assertOk();
    }

    public function test_exporter_demande_la_permission_d_exporter(): void
    {
        $this->actingAs($this->compte(3, 'sans-droit', ['consulter_eleves']), 'sanctum');

        $this->getJson('/api/v1/echanges/catalogue')->assertStatus(403);
        $this->getJson('/api/v1/echanges/export')->assertStatus(403);
    }

    public function test_exporter_ne_donne_pas_le_droit_d_importer(): void
    {
        $this->actingAs($this->compte(4, 'lecture-seule', ['exporter_donnees']), 'sanctum');

        $this->getJson('/api/v1/echanges/catalogue')->assertOk();

        $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
            ]),
        ])->assertStatus(403);
    }

    public function test_un_classeur_xlsx_se_lit_comme_un_csv(): void
    {
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet();
        $feuille->fromArray([$this->entetesEleves()], null, 'A1');
        $feuille->fromArray([['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A']], null, 'A2');

        $chemin = tempnam(sys_get_temp_dir(), 'nx').'.xlsx';
        IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);

        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => new UploadedFile($chemin, 'eleves.xlsx', null, null, true),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
    }

    // --- Lectures de contrôle en échec : l'analyse est refusée, pas faite à l'aveugle ---

    /** Matricules existants illisibles : tout serait passé en création — des doublons. */
    public function test_l_import_d_eleves_est_refuse_si_les_eleves_existants_sont_illisibles(): void
    {
        \Illuminate\Support\Facades\Schema::connection('economat')->drop('T_ETUDIANT');

        $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-11', 'Kouassi', 'Awa', 'F', '12/03/2018', 'CP1A'],
            ]),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('fichier')
            ->assertJsonPath('errors.fichier.0', fn ($m) => str_contains($m, 'Référentiel injoignable')
                && ! str_contains($m, 'T_ETUDIANT') && ! str_contains($m, 'SQL'));
    }

    /** Classes illisibles : aucune classe n'aurait été contrôlée. */
    public function test_l_import_d_eleves_est_refuse_si_les_classes_sont_illisibles(): void
    {
        \Illuminate\Support\Facades\Schema::connection('economat')->drop('T_CLASSE');

        $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'ZZZ'],
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors('fichier');
    }

    public function test_l_import_de_notes_est_refuse_si_les_eleves_sont_illisibles(): void
    {
        \Illuminate\Support\Facades\Schema::connection('economat')->drop('T_ETUDIANT');

        $this->postJson('/api/v1/echanges/import/notes/analyse', [
            'fichier' => $this->fichier($this->entetesNotes(), [
                ['E-11', 'CP1A', 'MATH', 'S1', 'Composition', '12'],
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors('fichier');

        $this->assertSame(0, DB::connection('economat')->table('T_NOTEDETAILS')->count());
    }

    public function test_une_note_portee_sur_une_classe_d_un_autre_etablissement_est_rejetee(): void
    {
        // E-12 est bien en CP2A, mais CP2A appartient à E2 : hors de l'établissement de travail.
        $rapport = $this->withSession(['etablissement_code' => 'E1'])
            ->postJson('/api/v1/echanges/import/notes/analyse', [
                'tout_importer' => '0', // mode strict
                'fichier' => $this->fichier($this->entetesNotes(), [
                    ['E-12', 'CP2A', 'MATH', 'S1', 'Composition', '15'],
                ]),
            ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertSame(0, $rapport['creations']);
        $this->assertStringContainsString('hors de l’établissement', $rapport['feuilles'][0]['apercu'][0]['motif']);
    }

    public function test_l_import_d_evaluations_est_refuse_si_les_matieres_sont_illisibles(): void
    {
        \Illuminate\Support\Facades\Schema::connection('economat')->drop('T_MATIERE');

        $this->postJson('/api/v1/echanges/import/evaluations/analyse', [
            'fichier' => $this->fichier(['Titre', 'Classe', 'Matière', 'Type', 'Date'], [
                ['Compo 1', 'CP1A', 'MATH', 'Composition', '15/12/2025'],
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors('fichier');
    }

    // --- Année scolaire choisie sur l'écran ---

    public function test_on_peut_importer_dans_une_autre_annee_que_celle_de_travail(): void
    {
        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 2, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
            'Activer' => false, 'ClotureDefinitive' => false, 'DEBUT' => '2024-09-01',
        ]);

        $this->getJson('/api/v1/echanges/catalogue?annee=2024-2025')->assertOk()
            ->assertJsonPath('annee', '2024-2025');

        $jeton = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'annee' => '2024-2025',
            'fichier' => $this->fichier($this->entetesEleves(), [['E-77', 'Ancien', 'Élève', 'M', '01/09/2016', 'CM2A']]),
        ])->assertOk()->json('jeton');

        // Appliqué sans repréciser l'année : celle de l'analyse est retenue.
        $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])->assertOk()
            ->assertJsonPath('bilan.creees', 1);

        $this->assertSame('2024-2025', DB::connection('economat')->table('T_ETUDIANT')
            ->where('Matricule', 'E-77')->value('AnneeAcad'));
    }

    public function test_une_annee_inconnue_est_refusee_a_l_import(): void
    {
        $this->getJson('/api/v1/echanges/catalogue?annee=1990-1991')
            ->assertStatus(422)->assertJsonValidationErrors('annee');
    }

    // --- « Tout importer » ------------------------------------------------------------

    /** CM2A n'existe qu'en 2024-2025 : repris dans l'année visée, l'élève y entre. */
    public function test_tout_importer_reprend_une_classe_ouverte_une_autre_annee(): void
    {
        $fichier = fn () => $this->fichier($this->entetesEleves(), [['E-91', 'Grand', 'Frère', 'M', '01/09/2014', 'CM2A']]);

        // Mode strict (case décochée) : la classe est inconnue de l'année.
        $this->postJson('/api/v1/echanges/import/eleves/analyse', ['fichier' => $fichier(), 'tout_importer' => '0'])
            ->assertOk()->assertJsonPath('rejets', 1);

        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $fichier(), 'tout_importer' => '1',
        ])->assertOk()->json();
        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(['CM2A → CM2A-2025 (de 2024-2025)'], $rapport['annee_cible']['classes_reprises']);
        // L'analyse n'a rien ouvert.
        $this->assertFalse(DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CM2A-2025')->exists());

        $this->postJson('/api/v1/echanges/import', ['jeton' => $rapport['jeton']])->assertOk()
            ->assertJsonPath('bilan.creees', 1);

        $classe = DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CM2A-2025')->first();
        $this->assertSame(self::ANNEE, $classe->ANNEE);
        $this->assertSame('CM2 A', $classe->LibelleClasse);
        $this->assertSame('CM2A-2025', DB::connection('economat')->table('T_ETUDIANT')
            ->where('Matricule', 'E-91')->value('CodeClasse'));
    }

    public function test_tout_importer_renumerote_un_matricule_porte_par_un_autre_enfant(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'tout_importer' => '1',
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-92', 'Premier', 'Enfant', 'F', '01/09/2018', 'CP1A'],
                ['E-92', 'Autre', 'Enfant', 'M', '01/09/2018', 'CP1A'],
                // Le même enfant une seconde fois : pas un doublon, ni une erreur — la
                // ligne est comptée comme une mise à jour de la fiche de la première.
                ['E-92', 'Premier', 'Enfant', 'F', '01/09/2018', 'CP1A'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(2, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $this->assertSame(0, $rapport['rejets']);
        $repetee = collect($rapport['feuilles'][0]['apercu'])->firstWhere('ligne', 4);
        $this->assertStringContainsString('ligne identique à la ligne 2', $repetee['motif']);
        $renumerote = collect($rapport['feuilles'][0]['apercu'])->firstWhere('ligne', 3);
        $this->assertStringContainsString('créé sous « E-92-2 »', $renumerote['motif']);

        $this->postJson('/api/v1/echanges/import', ['jeton' => $rapport['jeton']])->assertOk();
        $this->assertSame('Autre', DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-92-2')->value('Nom'));
        $this->assertSame('Premier', DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-92')->value('Nom'));
        $this->assertSame(1, DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-92')->count());
    }

    // --- L'année mentionnée par le fichier ---------------------------------------------

    private function anneePrecedente(bool $cloturee): void
    {
        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 2, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
            'Activer' => false, 'ClotureDefinitive' => $cloturee, 'DEBUT' => '2024-09-01',
        ]);
        \App\Support\AnneeScolaireGuard::oublier();
    }

    /** Le rôle du compte de test reçoit le droit d'écrire dans une année close ou à créer. */
    private function accorderAnneesCloturees(): void
    {
        $eco = fn (string $t) => DB::connection('ecoprim')->table($t);
        $eco('console_role_permissions')->insert([
            'role_id' => $eco('console_roles')->where('code', 'secretaire')->value('id'),
            'permission_code' => 'importer_annee_cloturee',
        ]);
    }

    private function eleveDeCm2(string $nom): UploadedFile
    {
        return $this->fichier($this->entetesEleves(), [['E-80', 'Histo', 'Rique', 'F', '01/09/2015', 'CM2A']], $nom);
    }

    /** Une fiche par élève dans ECONOMAT : celle d'une autre année n'est pas réécrite. */
    public function test_un_eleve_inscrit_dans_une_autre_annee_est_ecarte_sans_toucher_sa_fiche(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'tout_importer' => '0', // mode strict
            'fichier' => $this->fichier($this->entetesEleves(), [['E-13', 'Ancien', 'Déplacé', 'M', '01/09/2014', 'CP1A']]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('inscrit en 2024-2025', $rapport['feuilles'][0]['apercu'][0]['motif']);

        $this->postJson('/api/v1/echanges/import', ['jeton' => $rapport['jeton']])->assertOk();
        $fiche = DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-13')->first();
        $this->assertSame('CM2A', $fiche->CodeClasse);
        $this->assertSame('Eleve', $fiche->Prenom);
    }

    public function test_l_import_va_dans_l_annee_que_mentionne_le_nom_du_fichier(): void
    {
        $this->anneePrecedente(cloturee: false);

        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->eleveDeCm2('nexora-eleves-2024-2025.csv'),
        ])->assertOk()->json();

        $this->assertSame('2024-2025', $rapport['annee_cible']['libelle']);
        $this->assertSame('fichier', $rapport['annee_cible']['source']);
        $this->assertSame(0, $rapport['rejets']);

        $this->postJson('/api/v1/echanges/import', ['jeton' => $rapport['jeton']])->assertOk();
        $this->assertSame('2024-2025', DB::connection('economat')->table('T_ETUDIANT')
            ->where('Matricule', 'E-80')->value('AnneeAcad'));
    }

    public function test_une_annee_choisie_a_l_ecran_prime_sur_celle_du_fichier(): void
    {
        $this->anneePrecedente(cloturee: false);

        $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'annee' => self::ANNEE,
            'fichier' => $this->fichier($this->entetesEleves(), [['E-81', 'Choix', 'Écran', 'M', '01/09/2018', 'CP1A']], 'eleves-2024-2025.csv'),
        ])->assertOk()
            ->assertJsonPath('annee_cible.libelle', self::ANNEE)
            ->assertJsonPath('annee_cible.source', 'choisie');
    }

    public function test_une_annee_cloturee_n_est_ouverte_qu_avec_la_permission(): void
    {
        $this->anneePrecedente(cloturee: true);

        $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->eleveDeCm2('eleves-2024-2025.csv'),
        ])->assertStatus(422)->assertJsonValidationErrors('fichier');

        $this->accorderAnneesCloturees();

        $jeton = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->eleveDeCm2('eleves-2024-2025.csv'),
        ])->assertOk()->assertJsonPath('annee_cible.cloturee', true)->json('jeton');

        $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])->assertOk()
            ->assertJsonPath('bilan.creees', 1);
        $this->assertSame('2024-2025', DB::connection('economat')->table('T_ETUDIANT')
            ->where('Matricule', 'E-80')->value('AnneeAcad'));
    }

    /**
     * L'année de travail n'a aucune classe pour l'établissement (elles sont rangées sous une
     * autre année, cas réel de deux « 2026-2027 ») : la reprise prend celles de l'année la
     * plus récente qui en a, au lieu de ne rien reprendre et d'écarter tous les élèves.
     */
    public function test_la_reprise_cherche_les_classes_dans_l_annee_qui_les_porte(): void
    {
        $this->anneePrecedente(cloturee: false);
        $this->accorderAnneesCloturees();
        // Plus aucune classe de E1 dans l'année de travail ; CM2A (E1) reste en 2024-2025.
        DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CP1A')->update(['ANNEE' => '1999-2000']);

        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [['E-83', 'Repris', 'Ailleurs', 'M', '01/09/2013', 'CM2A']], 'eleves-2023-2024.csv'),
        ])->assertOk()->json();

        $this->assertSame('2024-2025', $rapport['annee_cible']['reference']);
        $this->assertSame(['CM2A → CM2A-2023'], $rapport['annee_cible']['classes_reprises']);
        $this->assertSame(0, $rapport['rejets']);
    }

    /**
     * L'année mentionnée n'existe pas : elle naît à l'application — clôturée, puisqu'elle
     * précède l'année de travail — avec les classes de l'année de travail, faute de
     * feuille Classes dans le fichier. L'analyse, elle, n'a rien écrit.
     */
    public function test_une_annee_absente_se_cree_cloturee_avec_ses_classes(): void
    {
        $fichier = fn () => $this->fichier($this->entetesEleves(), [
            ['E-82', 'Ancien', 'Temps', 'F', '01/09/2014', 'CP1A'],
        ], 'nexora-eleves-2023-2024.csv');
        $annees = fn () => DB::connection('economat')->table('T_ANNEEACADEMIQUE');

        $this->postJson('/api/v1/echanges/import/eleves/analyse', ['fichier' => $fichier()])
            ->assertStatus(422)->assertJsonValidationErrors('fichier');

        $this->accorderAnneesCloturees();
        // Le contexte d'année est mémorisé pour la durée du processus : en test, deux
        // requêtes le partagent, ce qu'elles ne font jamais en production.
        \App\Support\ContexteScolaire::oublier();
        \App\Support\PerimetreEtablissement::oublier();
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', ['fichier' => $fichier()])
            ->assertOk()->json();

        $this->assertSame('2023-2024', $rapport['annee_cible']['libelle']);
        $this->assertFalse($rapport['annee_cible']['existe']);
        $this->assertTrue($rapport['annee_cible']['cloturee']);
        // Seules les classes des établissements du compte (E1) sont reprises ; le code
        // CP1A étant pris (une fois toutes années confondues), la copie est CP1A-2023.
        $this->assertSame(['CP1A → CP1A-2023'], $rapport['annee_cible']['classes_reprises']);
        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(1, $annees()->count());

        $this->postJson('/api/v1/echanges/import', ['jeton' => $rapport['jeton']])->assertOk()
            ->assertJsonPath('bilan.creees', 1);

        $annee = $annees()->where('LibelleAnnee', '2023-2024')->first();
        $this->assertNotNull($annee);
        $this->assertSame('2023', $annee->CodeAnnee);
        $this->assertTrue((bool) $annee->ClotureDefinitive);
        $this->assertFalse((bool) $annee->Activer);
        $copie = DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CP1A-2023')->first();
        $this->assertSame('2023-2024', $copie->ANNEE);
        $this->assertSame('E1', $copie->CODEETABLISSEMENT);
        $this->assertSame('CP1 A', $copie->LibelleClasse);
        // La classe d'origine n'a pas bougé.
        $this->assertSame(self::ANNEE, DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CP1A')->value('ANNEE'));
        // Le « CP1A » du fichier a été rangé dans la copie.
        $eleve = DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-82')->first();
        $this->assertSame('2023-2024', $eleve->AnneeAcad);
        $this->assertSame('CP1A-2023', $eleve->CodeClasse);
        // L'année de travail reste celle de l'en-tête.
        $this->assertTrue((bool) $annees()->where('CODE', 1)->value('Activer'));
    }
}
