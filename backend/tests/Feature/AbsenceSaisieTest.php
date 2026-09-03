<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Saisie des absences — ECONOMAT.T_ABSENCEELEVE.
 *
 * La page était en consultation seule, alors que c'est un suivi quotidien. Les règles
 * découlent du fait qu'une absence est un constat : elle porte sur un élève réellement
 * inscrit, dans sa classe, à une date passée, une seule fois par jour et par heure.
 */
class AbsenceSaisieTest extends TestCase
{
    private const ANNEE = '2025-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($rh, 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        $eco('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
            ['CODE' => 2, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
                'Activer' => false, 'ClotureDefinitive' => true, 'DEBUT' => '2024-09-01'],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 11, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa',
                'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            // Inscrit sur l'année précédente seulement.
            ['Code' => 12, 'Matricule' => 'E-12', 'Nom' => 'Bamba', 'Prenom' => 'Ali',
                'CodeClasse' => 'CP1A', 'AnneeAcad' => '2024-2025'],
        ]);
    }

    private function saisir(array $extra = [])
    {
        return $this->postJson('/api/v1/absences', array_merge([
            'matricule' => 'E-11', 'date' => '2025-10-06', 'heure' => '08:00', 'motif' => 'Maladie',
        ], $extra));
    }

    public function test_saisir_une_absence(): void
    {
        $r = $this->saisir()->assertCreated()
            ->assertJsonPath('matricule', 'E-11')
            ->assertJsonPath('motif', 'Maladie')
            ->assertJsonPath('justifiee', false);

        // La classe et le code interne suivent l'élève ; l'année vient du contexte.
        $this->assertDatabaseHas('T_ABSENCEELEVE', [
            'Code' => $r->json('id'), 'Matricule' => 'E-11', 'CodeClasse' => 'CP1A',
            'CodeEleve' => 11, 'AnneeCour' => self::ANNEE,
        ], 'economat');
    }

    public function test_un_eleve_non_inscrit_cette_annee_est_refuse(): void
    {
        $this->saisir(['matricule' => 'E-12'])
            ->assertStatus(422)->assertJsonValidationErrors('matricule');
        $this->saisir(['matricule' => 'FANTOME'])
            ->assertStatus(422)->assertJsonValidationErrors('matricule');
    }

    public function test_pas_deux_absences_le_meme_jour_a_la_meme_heure(): void
    {
        $this->saisir()->assertCreated();

        $r = $this->saisir(['motif' => 'Autre'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->assertStringContainsString('déjà une absence', $r->json('errors.date.0'));

        // Une autre heure du même jour reste possible : ce sont deux absences distinctes.
        $this->saisir(['heure' => '10:00'])->assertCreated();
    }

    public function test_une_absence_dans_le_futur_est_refusee(): void
    {
        $this->saisir(['date' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_corriger_puis_justifier_une_absence(): void
    {
        $id = $this->saisir()->assertCreated()->json('id');

        $this->putJson("/api/v1/absences/{$id}", ['motif' => 'Rendez-vous médical', 'justifiee' => true])
            ->assertOk()
            ->assertJsonPath('motif', 'Rendez-vous médical')
            ->assertJsonPath('justifiee', true);
    }

    public function test_retirer_une_absence_saisie_par_erreur(): void
    {
        $id = $this->saisir()->assertCreated()->json('id');

        $this->deleteJson("/api/v1/absences/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('T_ABSENCEELEVE', ['Code' => $id], 'economat');
    }

    public function test_les_absences_suivent_l_annee_de_travail(): void
    {
        $this->saisir()->assertCreated();

        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->assertCount(0, $this->getJson('/api/v1/absences')->json('data'));
    }

    public function test_une_annee_cloturee_est_en_consultation_seule(): void
    {
        $id = $this->saisir()->assertCreated()->json('id');
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->saisir(['matricule' => 'E-12'])->assertStatus(423);

        // La ligne appartient à une année ouverte : elle reste corrigeable.
        $this->putJson("/api/v1/absences/{$id}", ['justifiee' => true])->assertOk();
    }

    public function test_on_filtre_les_absences_par_classe_et_par_date(): void
    {
        $this->saisir()->assertCreated();
        $this->saisir(['date' => '2025-10-07', 'heure' => '08:00'])->assertCreated();

        $this->assertCount(2, $this->getJson('/api/v1/absences?classe_code=CP1A')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/absences?date=2025-10-07')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/absences?classe_code=AUTRE')->json('data'));
    }

    public function test_l_effectif_d_une_classe_est_filtrable_pour_la_saisie(): void
    {
        // La saisie part de l'effectif de la classe : ce filtre le lui fournit.
        $r = $this->getJson('/api/v1/eleves?classe_code=CP1A')->assertOk();

        $this->assertSame(['E-11'], collect($r->json('data'))->pluck('matricule')->all());
    }
}
