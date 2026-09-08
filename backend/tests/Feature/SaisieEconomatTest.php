<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

        // Référentiel minimal : la cohérence classe/niveau/année est contrôlée à la saisie.
        DB::connection('economat')->table('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A',
                'CodN' => 'CP1', 'ANNEE' => '2025-2026'],
        ]);
    }

    private function eleveValide(array $extra = []): array
    {
        return array_merge([
            'mouvement' => 'inscription', 'matricule' => 'M001',
            'nom' => 'Koné', 'prenom' => 'Aya', 'annee' => '2025-2026', 'sexe' => 'F',
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

    public function test_les_mouvements_entrants_positionnent_les_bons_indicateurs(): void
    {
        // Inscription : élève nouveau.
        $r = $this->postJson('/api/v1/inscriptions', $this->eleveValide())->assertCreated();
        $this->assertDatabaseHas('T_ETUDIANT', [
            'Code' => $r->json('id'), 'Inscription' => 1, 'Reinscription' => 0, 'Transfert' => 0,
        ], 'economat');

        // Transfert entrant : élève nouveau venant d'un autre établissement.
        $r = $this->postJson('/api/v1/inscriptions', $this->eleveValide([
            'matricule' => 'M002', 'prenom' => 'Venu', 'mouvement' => 'transfert_entrant',
            'etab_origine' => 'EPP Bouaké',
        ]))->assertCreated();
        $this->assertDatabaseHas('T_ETUDIANT', [
            'Code' => $r->json('id'), 'Inscription' => 0, 'Reinscription' => 0, 'Transfert' => 1,
        ], 'economat');

        // Réinscription et transfert sortant portent sur un élève EXISTANT : ils mettent à
        // jour sa ligne au lieu d'en créer une. Couvert par RestrictionsInscriptionTest.
    }

    public function test_filtrer_les_inscriptions_par_mouvement(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleveValide(['prenom' => 'Neuf']))->assertCreated();

        // « Ancien » est d'abord inscrit sur une autre année, puis réinscrit : sa ligne
        // porte alors l'indicateur Reinscription.
        $this->postJson('/api/v1/inscriptions', $this->eleveValide([
            'matricule' => 'M002', 'prenom' => 'Ancien', 'annee' => '2024-2025',
        ]))->assertCreated();
        $this->postJson('/api/v1/inscriptions', $this->eleveValide([
            'matricule' => 'M002', 'prenom' => 'Ancien', 'mouvement' => 'reinscription',
        ]))->assertOk();

        $r = $this->getJson('/api/v1/inscriptions?mouvement=reinscription')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame('Ancien', $r->json('data.0.prenom'));
    }

    public function test_matricule_eleve_unique(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleveValide(['matricule' => 'M001']))->assertCreated();

        // Même matricule en « inscription » : refusé, l'élève existe déjà.
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
        $r = $this->postJson('/api/v1/enseignants', ['matricule' => 'P002', 'nom' => 'Traoré', 'prenom' => 'Moussa'])->assertCreated();
        $code = $r->json('id');

        DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $code)->update(['SalaireMensuel' => 250000]);

        // Même en tentant de le passer, le salaire n'est pas dans la liste blanche.
        $this->putJson("/api/v1/enseignants/{$code}", [
            'matricule' => 'P002', 'nom' => 'Traoré', 'prenom' => 'Moussa', 'salaire' => 1, 'SalaireMensuel' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('T_PROFESSEUR', ['Code' => $code, 'SalaireMensuel' => 250000], 'economat');
    }

    public function test_depart_d_un_enseignant_au_lieu_d_une_suppression(): void
    {
        $r = $this->postJson('/api/v1/enseignants', ['matricule' => 'P003', 'nom' => 'Traoré', 'prenom' => 'Moussa'])->assertCreated();
        $code = $r->json('id');

        $this->putJson("/api/v1/enseignants/{$code}", [
            'matricule' => 'P003', 'nom' => 'Traoré', 'prenom' => 'Moussa',
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

    public function test_matricule_enseignant_obligatoire(): void
    {
        $this->postJson('/api/v1/enseignants', ['nom' => 'A', 'prenom' => 'B'])
            ->assertStatus(422)->assertJsonValidationErrors('matricule');
    }

    // --- Photo de l'élève (dossier partagé + T_ETUDIANT.Photo) ---

    private function dossierPhotos(): string
    {
        $dossier = sys_get_temp_dir().'/nexora-photos-'.getmypid();
        config(['nexora.photos_eleves.chemin' => $dossier]);

        return $dossier;
    }

    public function test_televerser_une_photo_ecrit_le_fichier_et_son_nom_en_base(): void
    {
        $dossier = $this->dossierPhotos();
        File::deleteDirectory($dossier);

        $code = $this->postJson('/api/v1/inscriptions', $this->eleveValide(['matricule' => 'M001']))
            ->assertCreated()->json('id');

        $this->postJson("/api/v1/inscriptions/{$code}/photo", [
            'photo' => UploadedFile::fake()->image('portrait.jpg', 300, 400),
        ])->assertOk()->assertJsonPath('photo', 'M001.jpg');

        // Le nom seul est stocké : ECONOMAT lit le dossier partagé.
        $this->assertDatabaseHas('T_ETUDIANT', ['Code' => $code, 'Photo' => 'M001.jpg'], 'economat');
        $this->assertFileExists($dossier.'/M001.jpg');

        File::deleteDirectory($dossier);
    }

    public function test_une_nouvelle_photo_remplace_la_precedente(): void
    {
        $dossier = $this->dossierPhotos();
        File::deleteDirectory($dossier);

        $code = $this->postJson('/api/v1/inscriptions', $this->eleveValide(['matricule' => 'M001']))
            ->assertCreated()->json('id');

        $this->postJson("/api/v1/inscriptions/{$code}/photo", ['photo' => UploadedFile::fake()->image('a.png')])->assertOk();
        $this->assertFileExists($dossier.'/M001.png');

        $this->postJson("/api/v1/inscriptions/{$code}/photo", ['photo' => UploadedFile::fake()->image('b.jpg')])->assertOk();

        // Pas d'accumulation : l'ancienne extension disparaît.
        $this->assertFileExists($dossier.'/M001.jpg');
        $this->assertFileDoesNotExist($dossier.'/M001.png');
        $this->assertDatabaseHas('T_ETUDIANT', ['Code' => $code, 'Photo' => 'M001.jpg'], 'economat');

        File::deleteDirectory($dossier);
    }

    public function test_un_fichier_non_image_est_refuse(): void
    {
        $this->dossierPhotos();
        $code = $this->postJson('/api/v1/inscriptions', $this->eleveValide())->assertCreated()->json('id');

        $this->postJson("/api/v1/inscriptions/{$code}/photo", [
            'photo' => UploadedFile::fake()->create('dossier.pdf', 40, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_la_photo_est_servie_et_404_si_absente(): void
    {
        $dossier = $this->dossierPhotos();
        File::deleteDirectory($dossier);

        $code = $this->postJson('/api/v1/inscriptions', $this->eleveValide(['matricule' => 'M001']))
            ->assertCreated()->json('id');

        $this->getJson("/api/v1/inscriptions/{$code}/photo")->assertNotFound();

        $this->postJson("/api/v1/inscriptions/{$code}/photo", ['photo' => UploadedFile::fake()->image('p.jpg')])->assertOk();
        $this->get("/api/v1/inscriptions/{$code}/photo")->assertOk();

        File::deleteDirectory($dossier);
    }

    public function test_un_chemin_en_base_ne_permet_pas_de_sortir_du_dossier(): void
    {
        $dossier = $this->dossierPhotos();
        File::deleteDirectory($dossier);
        File::ensureDirectoryExists($dossier);

        $code = $this->postJson('/api/v1/inscriptions', $this->eleveValide())->assertCreated()->json('id');

        // Valeur hostile dans la colonne : elle est réduite à son basename.
        DB::connection('economat')->table('T_ETUDIANT')->where('Code', $code)
            ->update(['Photo' => '../../../../etc/passwd']);

        $this->getJson("/api/v1/inscriptions/{$code}/photo")->assertNotFound();

        File::deleteDirectory($dossier);
    }

    public function test_enregistrer_la_carriere_complete_d_un_enseignant(): void
    {
        $r = $this->postJson('/api/v1/enseignants', [
            'nom' => 'Traoré', 'prenom' => 'Moussa', 'matricule' => 'P010', 'sexe' => 'M',
            'situation_matrimoniale' => 'MARIE', 'ville' => 'Abidjan', 'cellulaire' => '0700',
            'statut' => 'TITULAIRE', 'corps' => 'IEP', 'grade' => 'A3', 'echelon' => '4',
            'diplome' => 'CAP', 'formation' => 'CAFOP', 'volume_horaire' => 24,
            'fonction' => 'Instituteur', 'dren' => 'DREN Abidjan 1', 'dden' => 'DDEN Cocody',
            'annees_service' => 12, 'date_premiere_prise_service' => '2013-10-01',
            'ecole_prise_service' => 'EPP Cocody', 'date_arrivee_poste' => '2020-09-15',
        ])->assertCreated();

        $this->assertDatabaseHas('T_PROFESSEUR', [
            'Code' => $r->json('id'), 'Corps' => 'IEP', 'Echelon' => '4',
            'VHORAIRE' => 24, 'DREN' => 'DREN Abidjan 1', 'NbrAnneeService' => 12,
            'EcolePriseService' => 'EPP Cocody',
        ], 'economat');
    }

    public function test_le_volume_horaire_de_l_enseignant_est_borne(): void
    {
        $this->postJson('/api/v1/enseignants', ['matricule' => 'P004', 'nom' => 'A', 'prenom' => 'B', 'volume_horaire' => 99])
            ->assertStatus(422)->assertJsonValidationErrors('volume_horaire');
    }
}
