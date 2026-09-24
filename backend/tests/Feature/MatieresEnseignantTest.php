<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Matières enseignées par un professeur.
 *
 * Elles vivaient dans T_PROFESSEUR.Matiere : un varchar(50) de texte libre, une seule
 * valeur, non rattachée au référentiel des matières. Elles vivent désormais dans
 * T_CORPROFMAT, prévue pour cela et jusque-là inexploitée — l'ancienne colonne restant
 * alimentée avec la première matière, car ECONOMAT l'affiche de son côté.
 */
class MatieresEnseignantTest extends TestCase
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

        $eco('T_ANNEEACADEMIQUE')->insert([[
            'CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
            'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01',
        ]]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MAT', 'LibelleMatiere' => 'Mathématiques'],
            ['Code' => 2, 'CodeMatiere' => 'FR', 'LibelleMatiere' => 'Français'],
            ['Code' => 3, 'CodeMatiere' => 'SVT', 'LibelleMatiere' => 'Sciences'],
        ]);
    }

    private function fiche(array $extra = []): array
    {
        return array_merge([
            'matricule' => 'P100', 'nom' => 'Traoré', 'prenom' => 'Moussa',
            'annee_code' => self::ANNEE,
        ], $extra);
    }

    public function test_un_enseignant_peut_avoir_plusieurs_matieres(): void
    {
        $r = $this->postJson('/api/v1/enseignants', $this->fiche([
            'matieres' => ['MAT', 'SVT'],
        ]))->assertCreated();

        $this->assertSame(['MAT', 'SVT'], $r->json('matieres'));

        $this->assertDatabaseHas('T_CORPROFMAT', ['MatriculeProfesseur' => 'P100', 'CodeMatiere' => 'MAT'], 'economat');
        $this->assertDatabaseHas('T_CORPROFMAT', ['MatriculeProfesseur' => 'P100', 'CodeMatiere' => 'SVT'], 'economat');

        // L'ancienne colonne garde la première matière : ECONOMAT l'affiche telle quelle,
        // et y écrire une liste séparée par des virgules l'aurait polluée.
        $this->assertDatabaseHas('T_PROFESSEUR', ['MatriculeProfesseur' => 'P100', 'Matiere' => 'MAT'], 'economat');
    }

    public function test_decocher_une_matiere_la_retire(): void
    {
        $id = $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['MAT', 'FR', 'SVT']]))
            ->assertCreated()->json('id');

        $r = $this->putJson("/api/v1/enseignants/{$id}", $this->fiche(['matieres' => ['FR']]))->assertOk();

        $this->assertSame(['FR'], $r->json('matieres'));
        $this->assertDatabaseMissing('T_CORPROFMAT', ['MatriculeProfesseur' => 'P100', 'CodeMatiere' => 'MAT'], 'economat');
        $this->assertDatabaseCount('T_CORPROFMAT', 1, 'economat');
        // La colonne historique suit.
        $this->assertDatabaseHas('T_PROFESSEUR', ['MatriculeProfesseur' => 'P100', 'Matiere' => 'FR'], 'economat');
    }

    public function test_une_matiere_inconnue_est_refusee(): void
    {
        $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['FANTOME']]))
            ->assertStatus(422)->assertJsonValidationErrors('matieres.0');
    }

    /** Réenregistrer la même liste ne doit pas créer de doublons. */
    public function test_enregistrer_deux_fois_la_meme_liste_ne_duplique_rien(): void
    {
        $id = $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['MAT', 'FR']]))
            ->assertCreated()->json('id');

        $this->putJson("/api/v1/enseignants/{$id}", $this->fiche(['matieres' => ['MAT', 'FR']]))->assertOk();

        $this->assertDatabaseCount('T_CORPROFMAT', 2, 'economat');
    }

    /**
     * Le filtre de la liste doit retrouver un enseignant sur N'IMPORTE LAQUELLE de ses
     * matières, et pas seulement sur celle restée dans l'ancienne colonne.
     */
    public function test_le_filtre_trouve_une_matiere_secondaire(): void
    {
        $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['MAT', 'SVT']]))->assertCreated();

        // SVT n'est pas la première : elle n'est pas dans T_PROFESSEUR.Matiere.
        $r = $this->getJson('/api/v1/enseignants?matiere=SVT')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame('P100', $r->json('data.0.matricule'));

        $this->assertCount(0, $this->getJson('/api/v1/enseignants?matiere=FR')->json('data'));
    }

    public function test_une_fiche_sans_matiere_reste_enregistrable(): void
    {
        $r = $this->postJson('/api/v1/enseignants', $this->fiche())->assertCreated();

        $this->assertSame([], $r->json('matieres'));
        $this->assertDatabaseCount('T_CORPROFMAT', 0, 'economat');
    }
}
