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

    public function test_le_catalogue_compte_les_lignes_de_l_annee_de_travail(): void
    {
        $reponse = $this->getJson('/api/v1/echanges/catalogue')->assertOk();

        $this->assertSame(self::ANNEE, $reponse->json('annee'));

        $jeux = collect($reponse->json('jeux'))->keyBy('code');

        // 2 élèves sur 3 : le troisième est sur l'année précédente.
        $this->assertSame(2, $jeux['eleves']['lignes']);
        // 2 classes sur 3, pour la même raison.
        $this->assertSame(2, $jeux['classes']['lignes']);
    }

    public function test_le_catalogue_dit_ce_qui_ne_s_importe_pas_et_pourquoi(): void
    {
        $jeux = collect($this->getJson('/api/v1/echanges/catalogue')->json('jeux'))->keyBy('code');

        $this->assertTrue($jeux['eleves']['importable']);
        $this->assertFalse($jeux['enseignants']['importable']);
        // Un bouton absent sans explication envoie chercher ailleurs.
        $this->assertNotEmpty($jeux['enseignants']['pourquoi_pas']);
    }

    public function test_l_export_rend_un_classeur_borne_a_l_annee(): void
    {
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

    public function test_le_modele_ne_propose_pas_un_jeu_qui_ne_s_importe_pas(): void
    {
        $this->getJson('/api/v1/echanges/modele?jeux=enseignants')->assertStatus(422);
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
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CE1Z'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertSame(0, $rapport['creations']);
        $this->assertStringContainsString('CE1Z', $rapport['apercu'][0]['motif']);
    }

    public function test_un_matricule_en_double_dans_le_fichier_est_rejete(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier($this->entetesEleves(), [
                ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
                ['E-99', 'Doublon', 'Fichier', 'F', '02/09/2019', 'CP1A'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('ligne 2', $rapport['apercu'][0]['motif']);
    }

    public function test_un_eleve_cree_sans_classe_est_rejete(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
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
        $this->assertSame([], $rapport['colonnes_inconnues']);
    }

    public function test_une_colonne_inconnue_est_signalee_sans_bloquer(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/eleves/analyse', [
            'fichier' => $this->fichier(['Matricule', 'Nom', 'Prénom', 'Classe', 'Scolarité payée'], [
                ['E-99', 'Nouveau', 'Venu', 'CP1A', '150000'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(['Scolarité payée'], $rapport['colonnes_inconnues']);
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

    public function test_une_note_portee_sur_un_eleve_d_une_autre_classe_est_rejetee(): void
    {
        $rapport = $this->postJson('/api/v1/echanges/import/notes/analyse', [
            'fichier' => $this->fichier($this->entetesNotes(), [
                ['E-12', 'CP1A', 'MATH', 'S1', 'Composition', '15'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('CP2A', $rapport['apercu'][0]['motif']);
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
        $this->assertStringContainsString('Session', $rapport['apercu'][0]['motif']);
    }

    // --- Import : évaluations ---------------------------------------------------------

    public function test_une_evaluation_au_type_inconnu_est_rejetee(): void
    {
        $entetes = ['Titre', 'Classe', 'Matière', 'Type', 'Date', 'Coefficient', 'Note maximale'];

        $rapport = $this->postJson('/api/v1/echanges/import/evaluations/analyse', [
            'fichier' => $this->fichier($entetes, [
                ['Compo 1', 'CP1A', 'MATH', 'Composition', '15/12/2025', '2', '20'],
                ['Interro', 'CP1A', 'MATH', 'Interrogation', '16/12/2025', '1', '20'],
            ]),
        ])->assertOk()->json();

        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('Interrogation', $rapport['apercu'][0]['motif']);
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

    // --- Refus ------------------------------------------------------------------------

    public function test_un_jeu_non_importable_est_refuse(): void
    {
        $this->postJson('/api/v1/echanges/import/enseignants/analyse', [
            'fichier' => $this->fichier(['Matricule'], [['P-1']]),
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
}
