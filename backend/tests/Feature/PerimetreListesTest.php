<?php

namespace Tests\Feature;

use App\Models\RhUser;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Le périmètre établissement appliqué aux écrans, et plus seulement au tableau de bord.
 *
 * Règle : une société compte un ou plusieurs établissements ; chaque établissement
 * n'affiche que SES informations ; une société choisie sans établissement n'en affiche
 * aucune ; la vue générale du Super Admin (ni société ni établissement) montre tout.
 *
 * Jeu de données : CA appartient à E1, CB à E2 (tous deux de la société ABN), CX à
 * aucun établissement. Le professeur 10 enseigne en CA, le 20 en CB, le 30 nulle part.
 */
class PerimetreListesTest extends TestCase
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

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($rh, 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        $eco('T_ANNEEACADEMIQUE')->insert([[
            'CODE' => 2, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
            'Activer' => true, 'ClotureDefinitive' => false,
            'DEBUT' => now()->subMonth()->toDateString(), 'FIN' => now()->addMonths(6)->toDateString(),
        ]]);

        $eco('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'École Bêta', 'CodeSociete' => 'ABN'],
        ]);

        $eco('T_NIVEAU')->insert([
            ['Num' => 1, 'CodeNiveau' => 'N1', 'LibelleNiveau' => 'CP1', 'ANNEE' => self::ANNEE, 'Ordre' => 1],
        ]);

        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CA', 'LibelleClasse' => 'Alpha 1', 'CodN' => 'N1', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E1'],
            ['num' => 2, 'CodeClasse' => 'CB', 'LibelleClasse' => 'Bêta 1', 'CodN' => 'N1', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E2'],
            ['num' => 3, 'CodeClasse' => 'CX', 'LibelleClasse' => 'Orpheline', 'CodN' => 'N1', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => null],
        ]);

        $eco('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'Nom' => 'Un', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CA', 'CodeNiveau' => 'N1'],
            ['Code' => 2, 'Matricule' => 'A2', 'Nom' => 'Deux', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CA', 'CodeNiveau' => 'N1'],
            ['Code' => 3, 'Matricule' => 'B1', 'Nom' => 'Trois', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CB', 'CodeNiveau' => 'N1'],
            ['Code' => 4, 'Matricule' => 'X1', 'Nom' => 'Quatre', 'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CX', 'CodeNiveau' => 'N1'],
        ]);

        $eco('T_PROFESSEUR')->insert([
            ['Code' => 10, 'MatriculeProfesseur' => 'P10', 'NomProfesseur' => 'Alpha', 'CodeAnnee' => self::ANNEE],
            ['Code' => 20, 'MatriculeProfesseur' => 'P20', 'NomProfesseur' => 'Bêta', 'CodeAnnee' => self::ANNEE],
            ['Code' => 30, 'MatriculeProfesseur' => 'P30', 'NomProfesseur' => 'Gamma', 'CodeAnnee' => self::ANNEE],
        ]);

        $eco('T_CORPROFCLASSE')->insert([
            ['Code' => 1, 'CodeClasse' => 'CA', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 10, 'ANNEE' => self::ANNEE],
            ['Code' => 2, 'CodeClasse' => 'CB', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 20, 'ANNEE' => self::ANNEE],
        ]);

        $eco('T_ABSENCEELEVE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'CodeClasse' => 'CA', 'Date' => now()->toDateString(), 'AnneeCour' => self::ANNEE, 'Justifier' => false],
            ['Code' => 2, 'Matricule' => 'B1', 'CodeClasse' => 'CB', 'Date' => now()->toDateString(), 'AnneeCour' => self::ANNEE, 'Justifier' => false],
            ['Code' => 3, 'Matricule' => 'X1', 'CodeClasse' => 'CX', 'Date' => now()->toDateString(), 'AnneeCour' => self::ANNEE, 'Justifier' => true],
        ]);

        $eco('V_NOTECLASSE')->insert([
            ['Code' => 1, 'Matricule' => 'A1', 'Nom' => 'Un', 'Note' => 12, 'CodeMatiere' => 'MATH', 'CodeClasse' => 'CA', 'CodeAnnee' => '2025'],
            ['Code' => 2, 'Matricule' => 'B1', 'Nom' => 'Trois', 'Note' => 15, 'CodeMatiere' => 'MATH', 'CodeClasse' => 'CB', 'CodeAnnee' => '2025'],
            ['Code' => 3, 'Matricule' => 'X1', 'Nom' => 'Quatre', 'Note' => 9, 'CodeMatiere' => 'MATH', 'CodeClasse' => 'CX', 'CodeAnnee' => '2025'],
        ]);
    }

    private function choisirEtablissement(string $code): void
    {
        $this->postJson('/api/v1/contexte/etablissement', ['code' => $code])->assertOk();
    }

    /**
     * Le périmètre est mémorisé le temps d'une requête (propriété statique) : entre deux
     * appels d'un même test, on l'oublie, comme le ferait une nouvelle requête PHP.
     */
    private function lire(string $url, array $session = [])
    {
        PerimetreEtablissement::oublier();

        return ($session ? $this->withSession($session) : $this)->getJson($url)->assertOk();
    }

    /** Codes d'une liste paginée ou non, triés pour comparer sans dépendre de l'ordre. */
    private function codes($reponse, string $champ, ?string $chemin = 'data'): array
    {
        $codes = collect($reponse->json($chemin))->pluck($champ)->map(fn ($c) => (string) $c)->sort()->values()->all();

        return $codes;
    }

    public function test_la_vue_generale_du_super_admin_montre_tout(): void
    {
        $this->assertSame(['CA', 'CB', 'CX'], $this->codes($this->lire('/api/v1/classes'), 'code'));
        $this->assertSame(['A1', 'A2', 'B1', 'X1'], $this->codes($this->lire('/api/v1/eleves'), 'matricule'));
        $this->assertSame(['A1', 'A2', 'B1', 'X1'], $this->codes($this->lire('/api/v1/inscriptions'), 'matricule'));
        $this->assertSame(['10', '20', '30'], $this->codes($this->lire('/api/v1/enseignants'), 'id'));
        $this->assertSame(3, $this->lire('/api/v1/absences')->json('total'));
        $this->assertSame(3, $this->lire('/api/v1/notes')->json('total'));
    }

    public function test_un_etablissement_choisi_n_affiche_que_ses_donnees(): void
    {
        $this->choisirEtablissement('E1');

        $this->assertSame(['CA'], $this->codes($this->lire('/api/v1/classes'), 'code'));
        $this->assertSame(['A1', 'A2'], $this->codes($this->lire('/api/v1/eleves'), 'matricule'));
        $this->assertSame(['A1', 'A2'], $this->codes($this->lire('/api/v1/inscriptions'), 'matricule'));
        // Seul le professeur affecté en CA ; ni celui de CB, ni celui sans affectation.
        $this->assertSame(['10'], $this->codes($this->lire('/api/v1/enseignants'), 'id'));

        $absences = $this->lire('/api/v1/absences');
        $this->assertSame(1, $absences->json('total'));
        $this->assertSame('A1', $absences->json('data.0.matricule'));

        $notes = $this->lire('/api/v1/notes');
        $this->assertSame(1, $notes->json('total'));
        $this->assertEqualsWithDelta(12, $notes->json('data.0.note'), 0.01);

        // Les évaluations restituées (agrégat des notes) suivent la même règle.
        $this->assertSame(['CA'], $this->codes($this->lire('/api/v1/evaluations'), 'classe_code', null));
    }

    public function test_un_element_hors_perimetre_est_introuvable(): void
    {
        $this->choisirEtablissement('E1');

        PerimetreEtablissement::oublier();
        $this->getJson('/api/v1/eleves/3')->assertNotFound();
        $this->getJson('/api/v1/inscriptions/3')->assertNotFound();
        $this->getJson('/api/v1/classes/CB')->assertNotFound();
        $this->getJson('/api/v1/enseignants/20')->assertNotFound();
        $this->getJson('/api/v1/absences/2')->assertNotFound();
        $this->getJson('/api/v1/classes/CB/moyennes')->assertNotFound();
        $this->getJson('/api/v1/notes/feuille?classe=CB&matiere=MATH&session=S1')->assertNotFound();

        // Et ce qui est dans le périmètre reste accessible.
        $this->getJson('/api/v1/eleves/1')->assertOk();
        $this->getJson('/api/v1/classes/CA')->assertOk();
        $this->getJson('/api/v1/enseignants/10')->assertOk();
    }

    public function test_une_societe_sans_etablissement_n_affiche_aucune_liste(): void
    {
        $vide = ['console_societe' => 'VIDE'];

        $this->assertSame([], $this->lire('/api/v1/classes', $vide)->json('data'));
        $this->assertSame([], $this->lire('/api/v1/eleves', $vide)->json('data'));
        $this->assertSame([], $this->lire('/api/v1/inscriptions', $vide)->json('data'));
        $this->assertSame([], $this->lire('/api/v1/enseignants', $vide)->json('data'));
        $this->assertSame(0, $this->lire('/api/v1/absences', $vide)->json('total'));
        $this->assertSame(0, $this->lire('/api/v1/notes', $vide)->json('total'));
        $this->assertSame([], $this->lire('/api/v1/niveaux', $vide)->json());
        $this->assertSame([], $this->lire('/api/v1/affectations-enseignants/referentiels', $vide)->json('classes'));
        $this->assertSame([], $this->lire('/api/v1/affectations-enseignants/referentiels', $vide)->json('enseignants'));
    }

    public function test_une_societe_couvre_ses_etablissements_mais_pas_les_classes_orphelines(): void
    {
        $abn = ['console_societe' => 'ABN'];

        $this->assertSame(['CA', 'CB'], $this->codes($this->lire('/api/v1/classes', $abn), 'code'));
        $this->assertSame(['A1', 'A2', 'B1'], $this->codes($this->lire('/api/v1/eleves', $abn), 'matricule'));
        $this->assertSame(['10', '20'], $this->codes($this->lire('/api/v1/enseignants', $abn), 'id'));
        $this->assertSame(2, $this->lire('/api/v1/absences', $abn)->json('total'));
    }

    /** Tables propres à NEXORA (devoirs, événements) : rattachées par leur classe. */
    public function test_devoirs_et_evenements_suivent_leur_classe(): void
    {
        $maintenant = now();
        DB::connection('ecoprim')->table('devoirs')->insert([
            ['titre' => 'Devoir A', 'classe_code' => 'CA', 'matiere_code' => 'MATH', 'date_remise' => $maintenant->toDateString(),
                'annee' => self::ANNEE, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            ['titre' => 'Devoir B', 'classe_code' => 'CB', 'matiere_code' => 'MATH', 'date_remise' => $maintenant->toDateString(),
                'annee' => self::ANNEE, 'created_at' => $maintenant, 'updated_at' => $maintenant],
        ]);
        DB::connection('ecoprim')->table('evenements')->insert([
            ['titre' => 'Sortie A', 'type' => 'sortie', 'classe_code' => 'CA', 'date_debut' => $maintenant->toDateString(),
                'date_fin' => $maintenant->toDateString(), 'annee' => self::ANNEE, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            ['titre' => 'Sortie B', 'type' => 'sortie', 'classe_code' => 'CB', 'date_debut' => $maintenant->toDateString(),
                'date_fin' => $maintenant->toDateString(), 'annee' => self::ANNEE, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            ['titre' => 'Vacances', 'type' => 'vacances', 'classe_code' => null, 'date_debut' => $maintenant->toDateString(),
                'date_fin' => $maintenant->toDateString(), 'annee' => self::ANNEE, 'created_at' => $maintenant, 'updated_at' => $maintenant],
        ]);

        $this->choisirEtablissement('E1');

        $this->assertSame(['Devoir A'], collect($this->lire('/api/v1/devoirs')->json())->pluck('titre')->all());
        $ref = $this->lire('/api/v1/devoirs/referentiels');
        $this->assertSame(['CA'], collect($ref->json('classes'))->pluck('code')->all());
        $this->assertSame([10], collect($ref->json('enseignants'))->pluck('code')->all());

        // Un événement sans classe ne peut être rattaché à aucun établissement : il reste visible.
        $this->assertSame(['Sortie A', 'Vacances'],
            collect($this->lire('/api/v1/evenements')->json())->pluck('titre')->sort()->values()->all());
        $this->assertSame([], $this->lire('/api/v1/evenements', ['console_societe' => 'VIDE'])->json());

        PerimetreEtablissement::oublier();
        $idB = DB::connection('ecoprim')->table('devoirs')->where('titre', 'Devoir B')->value('id');
        $this->deleteJson("/api/v1/devoirs/{$idB}")->assertNotFound();
        $this->assertSame(2, DB::connection('ecoprim')->table('devoirs')->count());
    }

    /**
     * Les niveaux restent le référentiel commun de l'année : un établissement doit pouvoir
     * ouvrir sa première classe. Et l'écran d'affectation propose tous les enseignants,
     * sinon un enseignant jamais affecté ne pourrait jamais l'être.
     */
    public function test_les_referentiels_communs_ne_sont_pas_bornes(): void
    {
        $this->choisirEtablissement('E1');

        $this->assertCount(1, $this->lire('/api/v1/niveaux')->json());

        $ref = $this->lire('/api/v1/affectations-enseignants/referentiels');
        $this->assertSame(['CA'], collect($ref->json('classes'))->pluck('code')->all());
        $this->assertCount(3, $ref->json('enseignants'));

        $affectations = $this->lire('/api/v1/affectations-enseignants');
        $this->assertSame(['CA'], collect($affectations->json('affectations'))->pluck('classe')->all());
    }
}
