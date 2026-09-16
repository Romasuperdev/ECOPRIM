<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tableau de bord du personnel.
 *
 * Deux comportements valent d'être figés ici :
 *
 * 1. Le périmètre établissement. T_ETUDIANT n'a pas de colonne établissement : le
 *    rattachement passe par la classe. Une classe sans CODEETABLISSEMENT n'appartient
 *    donc à personne, et ses élèves sortent des totaux.
 * 2. Le repli quand AUCUNE classe de l'année ne porte le code de l'établissement
 *    choisi. Restreindre donnerait zéro partout — un écran qui paraît en panne alors
 *    que le problème est dans les données. On garde les chiffres de la société et on
 *    signale que le filtre n'a pas pu s'appliquer.
 *
 * Le taux d'assiduité est également couvert : il se rapporte aux journées-élèves
 * réellement écoulées, et non plus au seul effectif comme dans la première version,
 * où 300 absences cumulées faisaient tomber une école de 300 élèves à 0 %.
 */
class TableauDeBordTest extends TestCase
{
    private const ANNEE = '2025-2026';
    private const CODE_ANNEE = '2025';

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

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($rh, 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        // L'année a commencé il y a exactement 4 semaines : 20 jours ouvrés.
        $debut = now()->subWeeks(4)->startOfWeek();
        $eco('T_ANNEEACADEMIQUE')->insert([[
            'CODE' => 2, 'CodeAnnee' => self::CODE_ANNEE, 'LibelleAnnee' => self::ANNEE,
            'Activer' => true, 'ClotureDefinitive' => false,
            'DEBUT' => $debut->toDateString(), 'FIN' => now()->addMonths(6)->toDateString(),
        ]]);

        $eco('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'École Bêta', 'CodeSociete' => 'ABN'],
        ]);

        // CA appartient à E1, CB à E2, CX à personne.
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CA', 'LibelleClasse' => 'Alpha 1', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E1'],
            ['num' => 2, 'CodeClasse' => 'CB', 'LibelleClasse' => 'Bêta 1', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E2'],
            ['num' => 3, 'CodeClasse' => 'CX', 'LibelleClasse' => 'Orpheline', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => null],
        ]);

        $eco('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'Nom' => 'Un', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CA', 'CodeNiveau' => 'N1'],
            ['Code' => 2, 'Matricule' => 'A2', 'Nom' => 'Deux', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CA', 'CodeNiveau' => 'N1'],
            ['Code' => 3, 'Matricule' => 'B1', 'Nom' => 'Trois', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CB', 'CodeNiveau' => 'N1'],
            ['Code' => 4, 'Matricule' => 'X1', 'Nom' => 'Quatre', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CX', 'CodeNiveau' => 'N1'],
        ]);

        $eco('T_NIVEAU')->insert([
            ['Num' => 1, 'CodeNiveau' => 'N1', 'LibelleNiveau' => 'CP1', 'ANNEE' => self::ANNEE, 'Ordre' => 1],
        ]);

        $eco('V_MOYENNE_ELEVE_CLASSE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'Moyenne' => 14, 'CodeEleve' => 1, 'CodeClasse' => 'CA', 'CodeAnnee' => self::CODE_ANNEE],
            ['Code' => 2, 'Matricule' => 'A2', 'Moyenne' => 6, 'CodeEleve' => 2, 'CodeClasse' => 'CA', 'CodeAnnee' => self::CODE_ANNEE],
            ['Code' => 3, 'Matricule' => 'B1', 'Moyenne' => 18, 'CodeEleve' => 3, 'CodeClasse' => 'CB', 'CodeAnnee' => self::CODE_ANNEE],
        ]);
    }

    private function choisirEtablissement(string $code): void
    {
        $this->postJson('/api/v1/contexte/etablissement', ['code' => $code])->assertOk();
    }

    public function test_sans_etablissement_choisi_les_chiffres_couvrent_la_societe(): void
    {
        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

        $this->assertSame(4, $r->json('effectifs.total_eleves'));
        $this->assertFalse($r->json('perimetre.applique'));
        $this->assertSame('aucun_etablissement', $r->json('perimetre.raison'));
        // L'orpheline est signalée même en vue société : c'est une donnée à corriger.
        $this->assertSame(1, $r->json('perimetre.classes_sans_etablissement'));
    }

    public function test_choisir_un_etablissement_borne_les_chiffres_a_ses_classes(): void
    {
        $this->choisirEtablissement('E1');

        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

        // Seuls les 2 élèves de CA : ni CB (autre établissement), ni CX (orpheline).
        $this->assertSame(2, $r->json('effectifs.total_eleves'));
        $this->assertSame(1, $r->json('effectifs.total_classes'));
        $this->assertTrue($r->json('perimetre.applique'));
        $this->assertSame('Alpha 1', $r->json('effectif_par_classe.0.classe'));
        // Moyenne de CA seulement : (14 + 6) / 2 = 10, et non celle de B1 (18).
        $this->assertEqualsWithDelta(10, $r->json('moyenne_generale'), 0.01);
        $this->assertEqualsWithDelta(50, $r->json('taux_reussite.taux'), 0.01);
    }

    public function test_changer_d_etablissement_change_les_chiffres(): void
    {
        $this->choisirEtablissement('E2');

        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

        $this->assertSame(1, $r->json('effectifs.total_eleves'));
        $this->assertEqualsWithDelta(18, $r->json('moyenne_generale'), 0.01);
    }

    public function test_un_etablissement_sans_classe_rattachee_ne_vide_pas_l_ecran(): void
    {
        // Toutes les classes de l'année perdent leur rattachement : filtrer donnerait
        // zéro partout. On garde les chiffres de la société, et on dit pourquoi.
        DB::connection('economat')->table('T_CLASSE')->update(['CODEETABLISSEMENT' => null]);
        $this->choisirEtablissement('E1');

        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

        $this->assertSame(4, $r->json('effectifs.total_eleves'));
        $this->assertFalse($r->json('perimetre.applique'));
        $this->assertSame('aucune_classe_rattachee', $r->json('perimetre.raison'));
        $this->assertSame(3, $r->json('perimetre.classes_sans_etablissement'));
    }

    public function test_le_taux_d_assiduite_se_rapporte_aux_journees_ecoulees(): void
    {
        DB::connection('economat')->table('T_ABSENCEELEVE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'CodeClasse' => 'CA', 'Date' => now()->toDateString(),
                'AnneeCour' => self::ANNEE, 'Justifier' => true],
            ['Code' => 2, 'Matricule' => 'A2', 'CodeClasse' => 'CA', 'Date' => now()->toDateString(),
                'AnneeCour' => self::ANNEE, 'Justifier' => false],
        ]);

        $this->choisirEtablissement('E1');
        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

        $joursOuvres = $r->json('assiduite.jours_ouvres');
        $this->assertGreaterThan(15, $joursOuvres, 'Environ 4 semaines de jours ouvrés');
        $this->assertSame(2, $r->json('assiduite.absences'));
        $this->assertSame($joursOuvres * 2, $r->json('assiduite.journees_attendues'));

        // 2 absences sur (jours × 2 élèves) : très loin des 0 % que donnait l'ancien
        // calcul, où 2 absences pour 2 élèves valaient « 0 % d'assiduité ».
        $attendu = round(100 - (2 / ($joursOuvres * 2)) * 100, 1);
        $this->assertEqualsWithDelta($attendu, $r->json('assiduite.taux'), 0.05);
        $this->assertGreaterThan(90, $r->json('assiduite.taux'));
    }

    public function test_les_absences_du_mois_sont_ventilees_par_justification(): void
    {
        DB::connection('economat')->table('T_ABSENCEELEVE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'CodeClasse' => 'CA', 'Date' => now()->toDateString(),
                'AnneeCour' => self::ANNEE, 'Justifier' => true],
            ['Code' => 2, 'Matricule' => 'A2', 'CodeClasse' => 'CA', 'Date' => now()->toDateString(),
                'AnneeCour' => self::ANNEE, 'Justifier' => false],
            // Celle-ci est dans CB : elle ne doit pas apparaître pour E1.
            ['Code' => 3, 'Matricule' => 'B1', 'CodeClasse' => 'CB', 'Date' => now()->toDateString(),
                'AnneeCour' => self::ANNEE, 'Justifier' => false],
        ]);

        $this->choisirEtablissement('E1');
        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

        $this->assertSame(2, $r->json('absences_ce_mois'));
        $this->assertSame(1, $r->json('absences_par_mois.0.justifiees'));
        $this->assertSame(1, $r->json('absences_par_mois.0.non_justifiees'));
    }

    public function test_l_agenda_ne_montre_que_les_echeances_a_venir(): void
    {
        DB::connection('ecoprim')->table('evenements')->insert([
            ['titre' => 'Réunion passée', 'type' => 'reunion',
                'date_debut' => now()->subWeek()->toDateString(), 'date_fin' => now()->subWeek()->toDateString(),
                'annee' => self::ANNEE, 'created_at' => now(), 'updated_at' => now()],
            ['titre' => 'Sortie à venir', 'type' => 'sortie',
                'date_debut' => now()->addWeek()->toDateString(), 'date_fin' => now()->addWeek()->toDateString(),
                'annee' => self::ANNEE, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

        $this->assertCount(1, $r->json('agenda'));
        $this->assertSame('Sortie à venir', $r->json('agenda.0.titre'));
        $this->assertSame('evenement', $r->json('agenda.0.nature'));
    }
}
