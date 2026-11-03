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
 * Import Excel en mode « Tout importer » — le mode par défaut.
 *
 * Une ligne n'est écartée que si l'écrire est réellement impossible ; tout ce qui se
 * rattrape l'est, et la ligne le DIT dans son motif : un rattrapage silencieux vaudrait
 * une erreur cachée. Le mode strict (case décochée) reste éprouvé par EchangeTest.
 */
class EchangeToutImporterTest extends TestCase
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

    private function entetesEleves(): array
    {
        return ['Matricule', 'Nom', 'Prénom', 'Sexe', 'Date de naissance', 'Classe'];
    }

    private function entetesNotes(): array
    {
        return ['Matricule', 'Classe', 'Matière', 'Session', 'Type', 'Note'];
    }

    private function analyser(string $jeu, UploadedFile $fichier, array $options = []): array
    {
        return $this->postJson("/api/v1/echanges/import/{$jeu}/analyse", ['fichier' => $fichier] + $options)
            ->assertOk()->json();
    }

    private function appliquer(string $jeton): array
    {
        return $this->postJson('/api/v1/echanges/import', ['jeton' => $jeton])->assertOk()->json();
    }

    /** La ligne n° $n de l'aperçu d'une feuille du rapport. */
    private function ligne(array $rapport, int $n, int $feuille = 0): array
    {
        return collect($rapport['feuilles'][$feuille]['apercu'])->firstWhere('ligne', $n);
    }

    private function eleve(string $matricule)
    {
        return DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', $matricule)->first();
    }

    private function niveau(string $code, int $num = 1): void
    {
        DB::connection('economat')->table('T_NIVEAU')->insert([
            'Num' => $num, 'CodeNiveau' => $code, 'LibelleNiveau' => $code, 'ANNEE' => self::ANNEE,
        ]);
    }

    // --- Mode par défaut -------------------------------------------------------------

    public function test_tout_importer_est_le_mode_par_defaut_et_se_decoche(): void
    {
        $fichier = fn () => $this->fichier($this->entetesEleves(), [
            ['E-99', 'Nouveau', 'Venu', 'M', 'le 3 du mois', 'CP1A'],
        ]);

        $rapport = $this->analyser('eleves', $fichier());
        $this->assertTrue($rapport['tout_importer']);
        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(1, $rapport['ajustees']);
        $this->assertSame(1, $rapport['feuilles'][0]['ajustees']);

        $strict = $this->analyser('eleves', $fichier(), ['tout_importer' => '0']);
        $this->assertFalse($strict['tout_importer']);
        $this->assertSame(1, $strict['rejets']);
        $this->assertSame(0, $strict['ajustees']);
    }

    // --- Élèves ---------------------------------------------------------------------

    public function test_une_classe_inconnue_partout_est_creee_dans_l_etablissement_de_travail(): void
    {
        $this->niveau('CE1');

        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['E-98', 'Premier', 'Eleve', 'F', '01/09/2019', 'CE1Z'],
            ['E-99', 'Second', 'Eleve', 'M', '02/09/2019', 'CE1Z'],
        ]));

        $this->assertSame(2, $rapport['creations']);
        $this->assertSame(0, $rapport['rejets']);
        $this->assertStringContainsString('« CE1Z » inconnue : créée', $this->ligne($rapport, 2)['motif']);

        // L'analyse n'écrit rien, pas même la classe.
        $classes = fn () => DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CE1Z');
        $this->assertFalse($classes()->exists());

        $bilan = $this->appliquer($rapport['jeton'])['bilan'];
        $this->assertSame(['creees' => 2, 'modifiees' => 0, 'echecs' => []], $bilan);

        // Une seule classe pour deux lignes, dans l'année et l'établissement de travail,
        // sur le niveau déduit de son code.
        $this->assertSame(1, $classes()->count());
        $classe = $classes()->first();
        $this->assertSame(self::ANNEE, $classe->ANNEE);
        $this->assertSame('E1', $classe->CODEETABLISSEMENT);
        $this->assertSame('CE1', $classe->CodN);
        $this->assertSame('CE1Z', $this->eleve('E-99')->CodeClasse);
    }

    public function test_une_classe_dont_le_niveau_ne_se_devine_pas_est_creee_sans_niveau(): void
    {
        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'XYZ'],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertStringContainsString('niveau à renseigner', $this->ligne($rapport, 2)['motif']);

        $this->appliquer($rapport['jeton']);
        $this->assertTrue(DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'XYZ')->exists());
        $this->assertSame('XYZ', trim($this->eleve('E-99')->CodeClasse));
    }

    public function test_un_eleve_inscrit_une_autre_annee_recoit_une_fiche_pour_l_annee_visee(): void
    {
        DB::connection('economat')->table('T_ETUDIANT')->insert([
            'Code' => 500, 'Matricule' => 'E-77', 'Nom' => 'Ancien', 'Prenom' => 'Eleve',
            'CodeClasse' => 'CP1A', 'AnneeAcad' => '2019-2020',
        ]);

        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['E-77', 'Ancien', 'Eleve', 'M', '01/09/2019', 'CP1A'],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(1, $rapport['creations']);
        $this->assertStringContainsString('déjà inscrit en 2019-2020', $this->ligne($rapport, 2)['motif']);

        $this->appliquer($rapport['jeton']);
        $fiches = DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-77')->get();
        $this->assertCount(2, $fiches);
        // L'autre année reste intacte.
        $this->assertSame('2019-2020', trim(DB::connection('economat')->table('T_ETUDIANT')->where('Code', 500)->value('AnneeAcad')));
    }

    public function test_une_valeur_trop_longue_est_coupee_et_dite(): void
    {
        $long = str_repeat('0', 60);
        $entetes = [...$this->entetesEleves(), 'Téléphone du pere ou tuteur'];

        $rapport = $this->analyser('eleves', $this->fichier($entetes, [
            ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A', $long],
        ]));

        $this->assertSame(1, $rapport['creations']);
        $motif = $this->ligne($rapport, 2)['motif'];
        $this->assertStringContainsString('« Téléphone du pere ou tuteur » coupé à 50', $motif);

        $this->appliquer($rapport['jeton']);
        $this->assertSame(50, strlen($this->eleve('E-99')->TelephonePereTuteur));

        $strict = $this->analyser('eleves', $this->fichier($entetes, [
            ['E-98', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A', $long],
        ]), ['tout_importer' => '0']);
        $this->assertSame(1, $strict['rejets']);
    }

    public function test_une_ligne_repetee_dans_le_fichier_n_est_pas_une_erreur(): void
    {
        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
            ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CP1A'],
            // Même enfant, ligne qui corrige la précédente : la dernière valeur l'emporte.
            ['E-99', 'Nouveau', 'Venu', 'F', '01/09/2019', 'CP1A'],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(2, $rapport['modifications']);
        $this->assertStringContainsString('identique à la ligne 2', $this->ligne($rapport, 3)['motif']);
        $this->assertStringContainsString('même élève que la ligne 2', $this->ligne($rapport, 4)['motif']);

        $bilan = $this->appliquer($rapport['jeton'])['bilan'];
        $this->assertSame(['creees' => 1, 'modifiees' => 2, 'echecs' => []], $bilan);
        $this->assertSame(1, DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', 'E-99')->count());
        $this->assertSame('F', $this->eleve('E-99')->Sexe);
    }

    public function test_un_matricule_absent_est_attribue(): void
    {
        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['', 'Sans', 'Matricule', 'F', '05/05/2019', 'CP1A'],
            ['', '', '', 'F', '', 'CP1A'],
        ]));

        $this->assertSame(1, $rapport['creations']);
        // Rien n'identifie la seconde : ni matricule, ni nom, ni prénom.
        $this->assertSame(1, $rapport['rejets']);
        $this->assertStringContainsString('rien n’identifie', $this->ligne($rapport, 3)['motif']);

        $ligne = $this->ligne($rapport, 2);
        $this->assertStringContainsString('« IMP2025-0002 » attribué', $ligne['motif']);

        $this->appliquer($rapport['jeton']);
        $this->assertSame('Sans', $this->eleve('IMP2025-0002')->Nom);
    }

    public function test_un_eleve_sans_matricule_deja_inscrit_est_reconnu(): void
    {
        DB::connection('economat')->table('T_ETUDIANT')->where('Code', 11)->update(['DateNaiss' => '2018-03-12']);

        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['', 'Kouassi', 'Awa', 'F', '12/03/2018', 'CP1A'],
        ]));

        $this->assertSame(1, $rapport['modifications']);
        $this->assertStringContainsString('« E-11 »', $this->ligne($rapport, 2)['motif']);
    }

    public function test_une_date_illisible_est_laissee_vide_et_un_nom_absent_est_provisoire(): void
    {
        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['E-99', '', 'Venu', 'M', 'le 3 du mois', 'CP1A'],
        ]));

        $this->assertSame(1, $rapport['creations']);
        $motif = $this->ligne($rapport, 2)['motif'];
        $this->assertStringContainsString('illisible : laissée vide', $motif);
        $this->assertStringContainsString('provisoire', $motif);

        $this->appliquer($rapport['jeton']);
        $this->assertNull($this->eleve('E-99')->DateNaiss);
        $this->assertSame('A COMPLETER', $this->eleve('E-99')->Nom);
    }

    public function test_un_eleve_a_creer_sans_classe_est_range_a_classer(): void
    {
        $rapport = $this->analyser('eleves', $this->fichier($this->entetesEleves(), [
            ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', ''],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertStringContainsString('A-CLASSER', $this->ligne($rapport, 2)['motif']);

        $this->appliquer($rapport['jeton']);
        $this->assertSame('A-CLASSER', trim($this->eleve('E-99')->CodeClasse));
    }

    // --- Référentiels : niveaux, matières, classes -----------------------------------

    public function test_un_niveau_sans_libelle_ou_repete_passe(): void
    {
        $rapport = $this->analyser('niveaux', $this->fichier(['Code', 'Libellé', 'Ordre'], [
            ['CE1', '', 'trois'],
            ['CE1', 'Cours élémentaire 1', '3'],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $this->assertStringContainsString('libellé absent', $this->ligne($rapport, 2)['motif']);
        $this->assertStringContainsString('ordre « trois » illisible', $this->ligne($rapport, 2)['motif']);
        $this->assertStringContainsString('même code que la ligne 2', $this->ligne($rapport, 3)['motif']);

        $this->assertSame(['creees' => 1, 'modifiees' => 1, 'echecs' => []], $this->appliquer($rapport['jeton'])['bilan']);
        $niveau = DB::connection('economat')->table('T_NIVEAU')->where('CodeNiveau', 'CE1')->get();
        $this->assertCount(1, $niveau);
        $this->assertSame('Cours élémentaire 1', $niveau->first()->LibelleNiveau);
        $this->assertSame(3, (int) $niveau->first()->Ordre);
    }

    public function test_une_matiere_repetee_ou_trop_longue_passe(): void
    {
        $rapport = $this->analyser('matieres', $this->fichier(['Code', 'Libellé'], [
            ['FRAN', str_repeat('Français ', 10)],
            ['FRAN', 'Français'],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertStringContainsString('coupé à 60', $this->ligne($rapport, 2)['motif']);

        $this->assertSame(['creees' => 1, 'modifiees' => 1, 'echecs' => []], $this->appliquer($rapport['jeton'])['bilan']);
        $this->assertSame('Français', DB::connection('economat')->table('T_MATIERE')->where('CodeMatiere', 'FRAN')->value('LibelleMatiere'));
    }

    public function test_une_classe_au_niveau_inconnu_ou_au_code_pris_est_creee_quand_meme(): void
    {
        $rapport = $this->analyser('classes', $this->fichier(['Code', 'Libellé', 'Niveau'], [
            // Niveau inconnu de l'année : créé.
            ['CE1A', 'CE1 A', 'CE1'],
            // Niveau absent : déduit du code (le CE1 que la ligne précédente crée).
            ['CE1B', '', ''],
            // CM2A est pris par 2024-2025 : ouverte sous un code libre.
            ['CM2A', 'CM2 A', 'CM2'],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(3, $rapport['creations']);
        $this->assertStringContainsString('niveau « CE1 » inconnu', $this->ligne($rapport, 2)['motif']);
        $this->assertStringContainsString('« CE1 » déduit du code', $this->ligne($rapport, 3)['motif']);
        $this->assertStringContainsString('« CM2A-2025 »', $this->ligne($rapport, 4)['motif']);

        $bilan = $this->appliquer($rapport['jeton'])['bilan'];
        $this->assertSame([], $bilan['echecs']);

        $eco = fn (string $t) => DB::connection('economat')->table($t);
        $this->assertSame(1, $eco('T_NIVEAU')->where('CodeNiveau', 'CE1')->where('ANNEE', self::ANNEE)->count());
        $this->assertSame(1, $eco('T_NIVEAU')->where('CodeNiveau', 'CM2')->where('ANNEE', self::ANNEE)->count());
        $this->assertSame('CE1', $eco('T_CLASSE')->where('CodeClasse', 'CE1B')->value('CodN'));
        $this->assertSame(self::ANNEE, $eco('T_CLASSE')->where('CodeClasse', 'CM2A-2025')->value('ANNEE'));
        // L'ancienne classe n'est pas touchée.
        $this->assertSame('2024-2025', $eco('T_CLASSE')->where('CodeClasse', 'CM2A')->value('ANNEE'));
    }

    public function test_les_eleves_suivent_une_classe_ouverte_sous_un_autre_code(): void
    {
        $rapport = $this->analyser('annee', $this->classeurMultiFeuilles([
            'Classes' => [['Code', 'Libellé', 'Niveau'], ['CM2A', 'CM2 A', 'CM2']],
            'Eleves' => [$this->entetesEleves(), ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2014', 'CM2A']],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->appliquer($rapport['jeton']);
        $this->assertSame('CM2A-2025', $this->eleve('E-99')->CodeClasse);
    }

    // --- Enseignants ----------------------------------------------------------------

    public function test_un_enseignant_est_rattrape_partout_ou_c_est_possible(): void
    {
        $rapport = $this->analyser('enseignants', $this->fichier(['Matricule', 'Nom', 'Prénom', 'Sexe', 'Email'], [
            ['P-1', '', 'Issa', 'X', 'pas-un-email'],
            ['', 'Koné', 'Ali', 'M', 'ali@ecole.ci'],
            ['P-1', 'Traoré', 'Issa', 'M', ''],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(2, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $motif = $this->ligne($rapport, 2)['motif'];
        $this->assertStringContainsString('Nom absent', $motif);
        $this->assertStringContainsString('sexe « X » illisible', $motif);
        $this->assertStringContainsString('email « pas-un-email » invalide', $motif);
        $this->assertStringContainsString('« ENS2025-0003 » attribué', $this->ligne($rapport, 3)['motif']);

        $this->assertSame(['creees' => 2, 'modifiees' => 1, 'echecs' => []], $this->appliquer($rapport['jeton'])['bilan']);
        $prof = DB::connection('economat')->table('T_PROFESSEUR')->where('MatriculeProfesseur', 'P-1')->get();
        $this->assertCount(1, $prof);
        $this->assertSame('Traoré', $prof->first()->NomProfesseur);
        $this->assertNull($prof->first()->EmailProfesseur);
    }

    public function test_le_matricule_d_un_enseignant_d_un_autre_etablissement_n_est_pas_reecrit(): void
    {
        DB::connection('economat')->table('T_PROFESSEUR')->insert([
            'Code' => 7, 'MatriculeProfesseur' => 'P-E2', 'NomProfesseur' => 'Ailleurs', 'Site' => 'E2',
        ]);

        $rapport = $this->analyser('enseignants', $this->fichier(['Matricule', 'Nom', 'Prénom'], [
            ['P-E2', 'Ici', 'Nouveau'],
        ]));

        $this->assertSame(1, $rapport['creations']);
        $this->assertStringContainsString('« P-E2-2 »', $this->ligne($rapport, 2)['motif']);

        $this->appliquer($rapport['jeton']);
        $this->assertSame('Ailleurs', DB::connection('economat')->table('T_PROFESSEUR')->where('Code', 7)->value('NomProfesseur'));
        $this->assertSame('Ici', DB::connection('economat')->table('T_PROFESSEUR')->where('MatriculeProfesseur', 'P-E2-2')->value('NomProfesseur'));
    }

    // --- Notes ----------------------------------------------------------------------

    public function test_une_note_suit_la_classe_de_l_eleve_et_une_repetition_ne_compte_qu_une_fois(): void
    {
        $rapport = $this->analyser('notes', $this->fichier($this->entetesNotes(), [
            ['E-11', 'CP2A', 'MATH', 'S1', 'Composition', '12'],
            ['E-11', '', 'MATH', 'S1', 'Composition', '14'],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(1, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $this->assertStringContainsString('rangée dans CP1A', $this->ligne($rapport, 2)['motif']);
        $this->assertStringContainsString('remplace la précédente', $this->ligne($rapport, 3)['motif']);

        $this->assertSame(['creees' => 1, 'modifiees' => 1, 'echecs' => []], $this->appliquer($rapport['jeton'])['bilan']);
        $details = DB::connection('economat')->table('T_NOTEDETAILS')->where('Matricule', 'E-11')->get();
        $this->assertCount(1, $details);
        $this->assertEqualsWithDelta(14.0, $details->first()->Note, 0.001);
    }

    public function test_une_note_sans_eleve_hors_bareme_ou_d_un_autre_etablissement_reste_ecartee(): void
    {
        $rapport = $this->analyser('notes', $this->fichier($this->entetesNotes(), [
            ['E-404', 'CP1A', 'MATH', 'S1', 'Composition', '12'],
            ['E-11', 'CP1A', 'MATH', 'S1', 'Composition', '25'],
            // E-12 est en CP2A, classe de E2 : ce compte n'y a pas accès.
            ['E-12', 'CP2A', 'MATH', 'S1', 'Composition', '12'],
        ]));

        $this->assertSame(3, $rapport['rejets']);
        $this->assertStringContainsString('E-404', $this->ligne($rapport, 2)['motif']);
        $this->assertStringContainsString('barème', $this->ligne($rapport, 3)['motif']);
        $this->assertStringContainsString('hors de l’établissement', $this->ligne($rapport, 4)['motif']);
    }

    // --- Absences -------------------------------------------------------------------

    public function test_une_absence_mal_classee_ou_repetee_passe_mais_pas_dans_le_futur(): void
    {
        $rapport = $this->analyser('absences', $this->fichier(['Matricule', 'Classe', 'Date', 'Motif'], [
            ['E-11', 'CP2A', '06/10/2025', 'Maladie'],
            ['E-11', 'CP1A', '06/10/2025', 'Maladie, certificat fourni'],
            ['E-11', 'CP1A', now()->addDays(3)->format('d/m/Y'), ''],
            ['E-11', 'CP1A', '07/10/2025', str_repeat('m', 250)],
        ]));

        $this->assertSame(1, $rapport['rejets']);
        $this->assertSame(2, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $this->assertStringContainsString('rangée dans CP1A', $this->ligne($rapport, 2)['motif']);
        $this->assertStringContainsString('même absence que la ligne 2', $this->ligne($rapport, 3)['motif']);
        $this->assertStringContainsString('futur', $this->ligne($rapport, 4)['motif']);
        $this->assertStringContainsString('coupé à 200', $this->ligne($rapport, 5)['motif']);

        $this->assertSame(['creees' => 2, 'modifiees' => 1, 'echecs' => []], $this->appliquer($rapport['jeton'])['bilan']);
        $absence = DB::connection('economat')->table('T_ABSENCEELEVE')->where('Matricule', 'E-11')
            ->whereDate('Date', '2025-10-06')->get();
        $this->assertCount(1, $absence);
        $this->assertSame('Maladie, certificat fourni', $absence->first()->Cause);
    }

    // --- Évaluations ----------------------------------------------------------------

    public function test_une_evaluation_est_rattrapee_partout_ou_c_est_possible(): void
    {
        $this->niveau('CE1');
        $entetes = ['Titre', 'Classe', 'Matière', 'Type', 'Date', 'Coefficient', 'Note maximale'];

        $rapport = $this->analyser('evaluations', $this->fichier($entetes, [
            ['Compo 1', 'CP1A', 'MATH', 'composition', '15/12/2025', '50', '20'],
            ['Interro', 'CP1A', 'MATH', 'Interrogation', '16/12/2025', '1', '20'],
            ['', 'CE1Z', 'FRAN', 'Examen blanc', '17/12/2025', '', ''],
            ['Compo 1', 'CP1A', 'MATH', 'Composition', '15/12/2025', '3', '20'],
            ['Sans date', 'CP1A', 'MATH', 'Composition', '', '1', '20'],
        ]));

        $this->assertSame(1, $rapport['rejets']);
        $this->assertSame(3, $rapport['creations']);
        $this->assertSame(1, $rapport['modifications']);
        $this->assertStringContainsString('ramené à 20', $this->ligne($rapport, 2)['motif']);
        $this->assertStringContainsString('« Composition » retenu', $this->ligne($rapport, 3)['motif']);
        $this->assertStringContainsString('matière « FRAN » inconnue', $this->ligne($rapport, 4)['motif']);
        $this->assertStringContainsString('« CE1Z » inconnue', $this->ligne($rapport, 4)['motif']);
        $this->assertStringContainsString('ligne 2', $this->ligne($rapport, 5)['motif']);
        $this->assertStringContainsString('Date', $this->ligne($rapport, 6)['motif']);

        $this->assertSame(['creees' => 3, 'modifiees' => 1, 'echecs' => []], $this->appliquer($rapport['jeton'])['bilan']);
        $this->assertSame(3, DB::connection('ecoprim')->table('evaluations')->count());
        $this->assertEqualsWithDelta(3.0, (float) DB::connection('ecoprim')->table('evaluations')
            ->where('titre', 'Compo 1')->value('coefficient'), 0.001);
        $this->assertTrue(DB::connection('economat')->table('T_MATIERE')->where('CodeMatiere', 'FRAN')->exists());
        $this->assertTrue(DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CE1Z')->exists());
    }

    // --- Année entière --------------------------------------------------------------

    public function test_les_notes_d_une_classe_creee_par_la_feuille_eleves_passent(): void
    {
        $this->niveau('CE1');

        $rapport = $this->analyser('annee', $this->classeurMultiFeuilles([
            'Eleves' => [$this->entetesEleves(), ['E-99', 'Nouveau', 'Venu', 'M', '01/09/2019', 'CE1Z']],
            'Notes' => [$this->entetesNotes(), ['E-99', 'CE1Z', 'MATH', 'S1', 'Composition', '15']],
        ]));

        $this->assertSame(0, $rapport['rejets']);
        $this->assertSame(2, $rapport['creations']);

        $reponse = $this->appliquer($rapport['jeton']);
        $this->assertSame([], $reponse['bilan']['echecs']);
        $this->assertSame(1, DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', 'CE1Z')->count());
        $this->assertEqualsWithDelta(15.0, DB::connection('economat')->table('T_NOTEDETAILS')
            ->where('Matricule', 'E-99')->value('Note'), 0.001);
    }
}
