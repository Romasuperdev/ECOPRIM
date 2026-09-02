<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Règle de gestion : une année scolaire CLÔTURÉE est en consultation seule.
 * Aucun ajout, aucune modification, aucune suppression — sans exception.
 * Le verrou est renvoyé en 423 Locked : la donnée existe mais elle est verrouillée.
 */
class AnneeClotureeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');

        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
                'Activer' => false, 'ClotureDefinitive' => true],
            ['CODE' => 2, 'CodeAnnee' => '2025', 'LibelleAnnee' => '2025-2026',
                'Activer' => true, 'ClotureDefinitive' => false],
        ]);
    }

    private const CLOTUREE = '2024-2025';
    private const OUVERTE = '2025-2026';

    private function eleve(array $extra = []): array
    {
        return array_merge([
            'mouvement' => 'inscription', 'matricule' => 'M001',
            'nom' => 'Koné', 'prenom' => 'Aya', 'annee' => self::OUVERTE,
        ], $extra);
    }

    // --- Inscriptions ---

    public function test_impossible_d_inscrire_dans_une_annee_cloturee(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['annee' => self::CLOTUREE]))
            ->assertStatus(423);

        $this->assertDatabaseCount('T_ETUDIANT', 0, 'economat');
    }

    public function test_inscription_possible_dans_une_annee_ouverte(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve())->assertCreated();
        $this->assertDatabaseCount('T_ETUDIANT', 1, 'economat');
    }

    public function test_un_dossier_d_annee_cloturee_ne_peut_pas_etre_modifie(): void
    {
        // Le dossier est créé sur l'année ouverte puis rattaché à l'année clôturée en base,
        // comme le ferait une clôture survenue après coup.
        $code = $this->postJson('/api/v1/inscriptions', $this->eleve())->assertCreated()->json('id');
        DB::connection('economat')->table('T_ETUDIANT')->where('Code', $code)
            ->update(['AnneeAcad' => self::CLOTUREE]);

        $this->putJson("/api/v1/inscriptions/{$code}", $this->eleve(['annee' => self::CLOTUREE, 'prenom' => 'Modifie']))
            ->assertStatus(423);

        $this->assertDatabaseHas('T_ETUDIANT', ['Code' => $code, 'Prenom' => 'Aya'], 'economat');
    }

    public function test_impossible_de_deplacer_un_dossier_vers_une_annee_cloturee(): void
    {
        $code = $this->postJson('/api/v1/inscriptions', $this->eleve())->assertCreated()->json('id');

        $this->putJson("/api/v1/inscriptions/{$code}", $this->eleve(['annee' => self::CLOTUREE]))
            ->assertStatus(423);

        $this->assertDatabaseHas('T_ETUDIANT', ['Code' => $code, 'AnneeAcad' => self::OUVERTE], 'economat');
    }

    public function test_pas_de_photo_sur_un_dossier_d_annee_cloturee(): void
    {
        config(['nexora.photos_eleves.chemin' => sys_get_temp_dir().'/nexora-verrou-'.getmypid()]);

        $code = $this->postJson('/api/v1/inscriptions', $this->eleve())->assertCreated()->json('id');
        DB::connection('economat')->table('T_ETUDIANT')->where('Code', $code)
            ->update(['AnneeAcad' => self::CLOTUREE]);

        $this->postJson("/api/v1/inscriptions/{$code}/photo", ['photo' => UploadedFile::fake()->image('p.jpg')])
            ->assertStatus(423);
    }

    public function test_la_consultation_reste_possible_sur_une_annee_cloturee(): void
    {
        $code = $this->postJson('/api/v1/inscriptions', $this->eleve())->assertCreated()->json('id');
        DB::connection('economat')->table('T_ETUDIANT')->where('Code', $code)
            ->update(['AnneeAcad' => self::CLOTUREE]);

        // Lecture : autorisée, c'est tout l'intérêt d'une année clôturée.
        $this->getJson("/api/v1/inscriptions/{$code}")->assertOk()->assertJsonPath('nom', 'Koné');
        $this->getJson('/api/v1/inscriptions?annee='.self::CLOTUREE)->assertOk()->assertJsonCount(1, 'data');
    }

    // --- Documents élèves (T_PREREQUIS) ---

    public function test_pas_de_document_ajoute_sur_une_annee_cloturee(): void
    {
        $this->postJson('/api/v1/parametres/prerequis', ['libelle' => 'Extrait', 'annee' => self::CLOTUREE])
            ->assertStatus(423);

        $this->assertDatabaseCount('T_PREREQUIS', 0, 'economat');
    }

    public function test_un_document_d_annee_cloturee_ne_peut_pas_etre_modifie(): void
    {
        $id = $this->postJson('/api/v1/parametres/prerequis', ['libelle' => 'Extrait', 'annee' => self::OUVERTE])
            ->assertCreated()->json('id');
        DB::connection('economat')->table('T_PREREQUIS')->where('CODES', $id)
            ->update(['ANNEE' => self::CLOTUREE]);

        $this->putJson("/api/v1/parametres/prerequis/{$id}", ['libelle' => 'Modifié', 'annee' => self::CLOTUREE])
            ->assertStatus(423);

        $this->assertDatabaseHas('T_PREREQUIS', ['CODES' => $id, 'LIBELLE' => 'Extrait'], 'economat');
    }

    // --- Enseignants ---

    public function test_pas_d_enseignant_rattache_a_une_annee_cloturee(): void
    {
        $this->postJson('/api/v1/enseignants', ['nom' => 'Traoré', 'prenom' => 'M', 'annee_code' => '2024'])
            ->assertStatus(423);

        $this->assertDatabaseCount('T_PROFESSEUR', 0, 'economat');
    }

    public function test_une_fiche_enseignant_d_annee_cloturee_ne_peut_pas_etre_modifiee(): void
    {
        $code = $this->postJson('/api/v1/enseignants', ['nom' => 'Traoré', 'prenom' => 'M', 'annee_code' => '2025'])
            ->assertCreated()->json('id');
        DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $code)
            ->update(['CodeAnnee' => '2024']);

        $this->putJson("/api/v1/enseignants/{$code}", ['nom' => 'Autre', 'prenom' => 'M', 'annee_code' => '2024'])
            ->assertStatus(423);

        $this->assertDatabaseHas('T_PROFESSEUR', ['Code' => $code, 'NomProfesseur' => 'Traoré'], 'economat');
    }

    // --- Robustesse du verrou ---

    public function test_le_verrou_reconnait_le_code_comme_le_libelle(): void
    {
        // T_ETUDIANT.AnneeAcad peut contenir le libellé ; T_PROFESSEUR.CodeAnnee le code.
        $this->postJson('/api/v1/inscriptions', $this->eleve(['annee' => '2024']))->assertStatus(423);
        $this->postJson('/api/v1/inscriptions', $this->eleve(['annee' => '2024-2025']))->assertStatus(423);
    }

    public function test_une_annee_inconnue_du_referentiel_ne_bloque_pas(): void
    {
        // Ni clôturée ni connue : on ne verrouille pas sur une incertitude.
        $this->postJson('/api/v1/inscriptions', $this->eleve(['annee' => '1999-2000']))->assertCreated();
    }

    public function test_le_message_du_verrou_nomme_l_annee(): void
    {
        $r = $this->postJson('/api/v1/inscriptions', $this->eleve(['annee' => self::CLOTUREE]))->assertStatus(423);

        $this->assertStringContainsString(self::CLOTUREE, $r->json('message'));
        $this->assertStringContainsString('clôturée', $r->json('message'));
    }
}
