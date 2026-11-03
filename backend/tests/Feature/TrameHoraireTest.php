<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Trame horaire (jours + plages) saisie depuis l'application — TrameHoraireController. */
class TrameHoraireTest extends TestCase
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
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($rh, 'sanctum');
    }

    private function eco(string $t)
    {
        return DB::connection('economat')->table($t);
    }

    private function envoyer(array $jours, array $heures)
    {
        return $this->putJson('/api/v1/emplois-du-temps/trame', ['jours' => $jours, 'heures' => $heures]);
    }

    public function test_une_trame_vide_se_cree_depuis_l_application(): void
    {
        $r = $this->envoyer(
            [['libelle' => 'Lundi'], ['libelle' => 'Mardi'], ['libelle' => 'Mercredi']],
            [['debut' => '08:00', 'fin' => '09:00'], ['debut' => '07:30', 'fin' => '08:00']],
        )->assertOk();

        $this->assertSame(['Lundi', 'Mardi', 'Mercredi'], $this->eco('T_EMPJOUR')->orderBy('Code')->pluck('Libelle')->all());
        $this->assertSame(2, $this->eco('T_HORAIRE')->count());
        // La grille lit les plages dans l'ordre des heures, quel que soit l'ordre de saisie.
        $this->assertSame('07:30 - 08:00', $r->json('heures.0.libelle'));
        $this->assertSame('00:30', $r->json('heures.0.duree'));
    }

    public function test_les_lignes_connues_gardent_leur_code(): void
    {
        $this->eco('T_EMPJOUR')->insert(['Code' => 5, 'Libelle' => 'Lundi']);
        $this->eco('T_HORAIRE')->insert(['COD_HORAIRE' => 9, 'HEUR_DEBUT' => '08:00', 'HEUR_FIN' => '09:00', 'DUREEE' => '1h']);

        $this->envoyer(
            [['code' => 5, 'libelle' => 'LUNDI'], ['libelle' => 'Mardi']],
            [['code' => 9, 'debut' => '08:00', 'fin' => '09:30'], ['debut' => '10:00', 'fin' => '11:00']],
        )->assertOk();

        $this->assertSame('LUNDI', $this->eco('T_EMPJOUR')->where('Code', 5)->value('Libelle'));
        $this->assertSame('09:30', $this->eco('T_HORAIRE')->where('COD_HORAIRE', 9)->value('HEUR_FIN'));
        $this->assertSame(2, $this->eco('T_HORAIRE')->count());
    }

    public function test_des_plages_qui_se_chevauchent_sont_refusees(): void
    {
        $this->envoyer([['libelle' => 'Lundi']], [
            ['debut' => '08:00', 'fin' => '09:00'], ['debut' => '08:30', 'fin' => '09:30'],
        ])->assertStatus(422)->assertJsonValidationErrors('heures');

        $this->envoyer([['libelle' => 'Lundi']], [['debut' => '10:00', 'fin' => '09:00']])
            ->assertStatus(422);

        $this->assertSame(0, $this->eco('T_HORAIRE')->count());
    }

    public function test_une_ligne_qui_porte_des_cours_ne_peut_pas_etre_retiree(): void
    {
        $this->eco('T_EMPJOUR')->insert([['Code' => 1, 'Libelle' => 'Lundi'], ['Code' => 2, 'Libelle' => 'Mardi']]);
        $this->eco('T_HORAIRE')->insert(['COD_HORAIRE' => 1, 'HEUR_DEBUT' => '08:00', 'HEUR_FIN' => '09:00']);
        $this->eco('T_EMPLOIDUTEMPS')->insert(['CODE' => 1, 'CODEJOUR' => 1, 'CODEHEURE' => 1, 'CODECLASSE' => 'CP1A', 'CODEMATIERE' => 'MATH', 'ANNEE' => '2025-2026']);

        // Retirer Lundi (porte un cours) : refusé, rien ne change.
        $this->envoyer([['code' => 2, 'libelle' => 'Mardi']], [['code' => 1, 'debut' => '08:00', 'fin' => '09:00']])
            ->assertStatus(422)->assertJsonValidationErrors('jours');
        $this->assertSame(2, $this->eco('T_EMPJOUR')->count());

        // Retirer Mardi (libre) : accepté.
        $this->envoyer([['code' => 1, 'libelle' => 'Lundi']], [['code' => 1, 'debut' => '08:00', 'fin' => '09:00']])
            ->assertOk();
        $this->assertSame(['Lundi'], $this->eco('T_EMPJOUR')->pluck('Libelle')->all());
    }
}
