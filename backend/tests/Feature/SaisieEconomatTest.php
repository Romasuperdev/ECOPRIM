<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Saisie NEXORA dans les tables métier ECONOMAT :
 *  - Inscriptions (T_ETUDIANT), les quatre mouvements portés par Inscription/Reinscription/Transfert
 *  - Enseignants (T_PROFESSEUR)
 * Création + modification, jamais de suppression ; financier et secrets jamais écrits.
 */
class SaisieEconomatTest extends TestCase
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
    }

    private function eleveValide(array $extra = []): array
    {
        return array_merge([
            'mouvement' => 'inscription', 'nom' => 'Koné', 'prenom' => 'Aya',
            'annee' => '2025-2026', 'sexe' => 'F',
        ], $extra);
    }

    // --- Inscriptions (T_ETUDIANT) ---

    public function test_inscrire_un_eleve_ecrit_dans_t_etudiant(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleveValide([
            'matricule' => 'M001', 'classe_code' => 'CP1A', 'pere_nom' => 'Koné', 'pere_telephone' => '0700',
        ]))->assertCreated()->assertJsonPath('nom', 'Koné')->assertJsonPath('prenom', 'Aya');

        $this->assertDatabaseHas('T_ETUDIANT', [
            'Matricule' => 'M001', 'Nom' => 'Koné', 'Prenom' => 'Aya',
            'AnneeAcad' => '2025-2026', 'Inscription' => 1, 'Reinscription' => 0, 'Etat' => 1,
        ], 'economat');
    }

    public function test_nom_prenom_et_annee_sont_obligatoires(): void
    {
        $this->postJson('/api/v1/inscriptions', ['mouvement' => 'inscription'])
            ->assertStatus(422)->assertJsonValidationErrors(['nom', 'prenom', 'annee']);
    }

    public function test_les_quatre_mouvements_positionnent_les_bons_indicateurs(): void
    {
        $attendus = [
            'inscription' => ['Inscription' => 1, 'Reinscription' => 0, 'Transfert' => 0],
            'reinscription' => ['Inscription' => 0, 'Reinscription' => 1, 'Transfert' => 0],
            'transfert_entrant' => ['Inscription' => 0, 'Reinscription' => 0, 'Transfert' => 1],
            'transfert_sortant' => ['Inscription' => 0, 'Reinscription' => 0, 'Transfert' => 1],
        ];

        foreach ($attendus as $mouvement => $indicateurs) {
            $r = $this->postJson('/api/v1/inscriptions', $this->eleveValide([
                'mouvement' => $mouvement, 'prenom' => ucfirst($mouvement),
            ]))->assertCreated();

            $this->assertDatabaseHas('T_ETUDIANT', ['Code' => $r->json('id')] + $indicateurs, 'economat');
        }
    }

    public function test_filtrer_les_inscriptions_par_mouvement(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleveValide(['prenom' => 'Neuf']))->assertCreated();
        $this->postJson('/api/v1/inscriptions', $this->eleveValide(['mouvement' => 'reinscription', 'prenom' => 'Ancien']))->assertCreated();

        $r = $this->getJson('/api/v1/inscriptions?mouvement=reinscription')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame('Ancien', $r->json('data.0.prenom'));
    }

    public function test_matricule_eleve_unique(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleveValide(['matricule' => 'M001']))->assertCreated();

        $this->postJson('/api/v1/inscriptions', $this->eleveValide(['matricule' => 'M001', 'prenom' => 'Autre']))
            ->assertStatus(422)->assertJsonValidationErrors('matricule');
    }

    public function test_modifier_une_inscription_sans_toucher_au_financier(): void
    {
        $r = $this->postJson('/api/v1/inscriptions', $this->eleveValide())->assertCreated();
        $code = $r->json('id');

        // On pose une valeur financière côté ECONOMAT : NEXORA ne doit jamais l'écraser.
        DB::connection('economat')->table('T_ETUDIANT')->where('Code', $code)->update(['Scolarite' => 150000]);

        $this->putJson("/api/v1/inscriptions/{$code}", $this->eleveValide(['prenom' => 'Ayaba']))
            ->assertOk()->assertJsonPath('prenom', 'Ayaba');

        $this->assertDatabaseHas('T_ETUDIANT', ['Code' => $code, 'Prenom' => 'Ayaba', 'Scolarite' => 150000], 'economat');
    }

    public function test_aucune_route_de_suppression_d_inscription(): void
    {
        $r = $this->postJson('/api/v1/inscriptions', $this->eleveValide())->assertCreated();

        $this->deleteJson("/api/v1/inscriptions/{$r->json('id')}")->assertStatus(405);
        $this->assertDatabaseCount('T_ETUDIANT', 1, 'economat');
    }

    // --- Enseignants (T_PROFESSEUR) ---

    public function test_enregistrer_un_enseignant_dans_t_professeur(): void
    {
        $this->postJson('/api/v1/enseignants', [
            'matricule' => 'P001', 'nom' => 'Traoré', 'prenom' => 'Moussa',
            'statut' => 'TITULAIRE', 'matiere' => 'Mathématiques', 'email' => 'm.traore@ecole.ci',
        ])->assertCreated()->assertJsonPath('nom', 'Traoré');

        $this->assertDatabaseHas('T_PROFESSEUR', [
            'MatriculeProfesseur' => 'P001', 'NomProfesseur' => 'Traoré',
            'PrenomProfesseur' => 'Moussa', 'Matiere' => 'Mathématiques',
            // NomComplet est dérivé automatiquement pour les listes ECONOMAT.
            'NomComplet' => 'Moussa Traoré',
        ], 'economat');
    }

    public function test_le_salaire_de_l_enseignant_n_est_jamais_ecrit(): void
    {
        $r = $this->postJson('/api/v1/enseignants', ['nom' => 'Traoré', 'prenom' => 'Moussa'])->assertCreated();
        $code = $r->json('id');

        DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $code)->update(['SalaireMensuel' => 250000]);

        // Même en tentant de le passer, le salaire n'est pas dans la liste blanche.
        $this->putJson("/api/v1/enseignants/{$code}", [
            'nom' => 'Traoré', 'prenom' => 'Moussa', 'salaire' => 1, 'SalaireMensuel' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('T_PROFESSEUR', ['Code' => $code, 'SalaireMensuel' => 250000], 'economat');
    }

    public function test_depart_d_un_enseignant_au_lieu_d_une_suppression(): void
    {
        $r = $this->postJson('/api/v1/enseignants', ['nom' => 'Traoré', 'prenom' => 'Moussa'])->assertCreated();
        $code = $r->json('id');

        $this->putJson("/api/v1/enseignants/{$code}", [
            'nom' => 'Traoré', 'prenom' => 'Moussa',
            'date_depart' => '30/06/2026', 'motif_depart' => 'Mutation', 'etab_accueil' => 'EPP Bouaké',
        ])->assertOk();

        $this->assertDatabaseHas('T_PROFESSEUR', [
            'Code' => $code, 'DateDepart' => '30/06/2026', 'Motif' => 'Mutation',
        ], 'economat');

        // La ligne existe toujours : pas de route de suppression.
        $this->deleteJson("/api/v1/enseignants/{$code}")->assertStatus(405);
        $this->assertDatabaseCount('T_PROFESSEUR', 1, 'economat');
    }

    public function test_matricule_enseignant_unique(): void
    {
        $this->postJson('/api/v1/enseignants', ['matricule' => 'P001', 'nom' => 'A', 'prenom' => 'B'])->assertCreated();

        $this->postJson('/api/v1/enseignants', ['matricule' => 'P001', 'nom' => 'C', 'prenom' => 'D'])
            ->assertStatus(422)->assertJsonValidationErrors('matricule');
    }
}
