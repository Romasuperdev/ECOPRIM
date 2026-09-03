<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Affectation enseignant ↔ classe ↔ matière (ECONOMAT.T_CORPROFCLASSE).
 *
 * C'est la table dont l'emploi du temps déduit l'enseignant de chaque créneau : le retrait
 * d'une affectation est donc contrôlé, pour ne pas laisser des créneaux orphelins ni des
 * notes sans origine.
 */
class AffectationEnseignantTest extends TestCase
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
            ['num' => 2, 'CodeClasse' => 'CP1B', 'LibelleClasse' => 'CP1 B', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
            ['num' => 3, 'CodeClasse' => 'CP1C', 'LibelleClasse' => 'CP1 C', 'CodN' => 'CP1', 'ANNEE' => '2024-2025'],
        ]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques'],
            ['Code' => 2, 'CodeMatiere' => 'FR', 'LibelleMatiere' => 'Français'],
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Traoré', 'PrenomProfesseur' => 'Moussa'],
            ['Code' => 8, 'MatriculeProfesseur' => 'P8', 'NomProfesseur' => 'Koffi', 'PrenomProfesseur' => 'Ama'],
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['classe' => 'CP1A', 'matiere' => 'MATH', 'enseignant' => 7], $extra);
    }

    private function creer(array $extra = [])
    {
        return $this->postJson('/api/v1/affectations-enseignants', $this->payload($extra));
    }

    // --- Référentiels et lecture ---

    public function test_les_referentiels_sont_bornes_a_l_annee_de_travail(): void
    {
        $r = $this->getJson('/api/v1/affectations-enseignants/referentiels')->assertOk();

        $this->assertSame(self::ANNEE, $r->json('annee'));
        // CP1C appartient à l'année précédente : elle ne doit pas être proposée.
        $this->assertSame(['CP1A', 'CP1B'], collect($r->json('classes'))->pluck('code')->all());
        // Les enseignants sortent triés sur le nom, pour une liste déroulante utilisable.
        $this->assertSame(['Ama Koffi', 'Moussa Traoré'], collect($r->json('enseignants'))->pluck('nom')->all());
    }

    public function test_affecter_puis_lire_la_grille_de_la_classe(): void
    {
        $this->creer()->assertCreated()
            ->assertJsonPath('classe_libelle', 'CP1 A')
            ->assertJsonPath('matiere_libelle', 'Mathématiques')
            ->assertJsonPath('enseignant_nom', 'Moussa Traoré')
            ->assertJsonPath('principale', false)
            ->assertJsonPath('retirable', true);

        $r = $this->getJson('/api/v1/affectations-enseignants?classe=CP1A')->assertOk();
        $this->assertSame(self::ANNEE, $r->json('annee'));
        $this->assertCount(1, $r->json('affectations'));

        // L'année est posée par le contexte, jamais saisie par le client.
        $this->assertDatabaseHas('T_CORPROFCLASSE', [
            'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE,
        ], 'economat');
    }

    public function test_on_peut_lire_les_affectations_d_un_enseignant(): void
    {
        $this->creer()->assertCreated();
        $this->creer(['classe' => 'CP1B', 'matiere' => 'FR'])->assertCreated();

        $r = $this->getJson('/api/v1/affectations-enseignants?enseignant=7')->assertOk();
        $this->assertCount(2, $r->json('affectations'));

        $this->assertCount(0, $this->getJson('/api/v1/affectations-enseignants?enseignant=8')->json('affectations'));
    }

    public function test_les_affectations_sont_cloisonnees_par_annee(): void
    {
        $this->creer()->assertCreated();

        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->assertCount(0, $this->getJson('/api/v1/affectations-enseignants')->json('affectations'));
    }

    // --- Règles ---

    public function test_une_matiere_n_a_qu_un_enseignant_par_classe(): void
    {
        $this->creer()->assertCreated();

        $r = $this->creer(['enseignant' => 8])
            ->assertStatus(422)->assertJsonValidationErrors('matiere');

        // Le message nomme celui qui occupe déjà la place.
        $this->assertStringContainsString('Moussa Traoré', $r->json('errors.matiere.0'));
    }

    public function test_le_meme_enseignant_peut_assurer_plusieurs_matieres_et_classes(): void
    {
        $this->creer()->assertCreated();
        $this->creer(['matiere' => 'FR'])->assertCreated();
        $this->creer(['classe' => 'CP1B'])->assertCreated();

        $this->assertCount(3, $this->getJson('/api/v1/affectations-enseignants')->json('affectations'));
    }

    public function test_une_classe_une_matiere_ou_un_enseignant_inconnu_est_refuse(): void
    {
        $this->creer(['classe' => 'FANTOME'])->assertStatus(422)->assertJsonValidationErrors('classe');
        $this->creer(['matiere' => 'FANTOME'])->assertStatus(422)->assertJsonValidationErrors('matiere');
        $this->creer(['enseignant' => 999])->assertStatus(422)->assertJsonValidationErrors('enseignant');
    }

    public function test_changer_l_enseignant_d_une_affectation(): void
    {
        $id = $this->creer()->assertCreated()->json('id');

        $this->putJson("/api/v1/affectations-enseignants/{$id}", ['enseignant' => 8])
            ->assertOk()->assertJsonPath('enseignant_nom', 'Ama Koffi');

        $this->assertDatabaseHas('T_CORPROFCLASSE', ['Code' => $id, 'CodeProfesseur' => 8], 'economat');
    }

    // --- Titulaire de la classe ---

    public function test_un_seul_titulaire_par_classe(): void
    {
        $math = $this->creer(['principale' => true])->assertCreated()->assertJsonPath('principale', true)->json('id');
        $fr = $this->creer(['matiere' => 'FR', 'enseignant' => 8])->assertCreated()->json('id');

        // Désigner un nouveau titulaire retire la marque au précédent.
        $this->putJson("/api/v1/affectations-enseignants/{$fr}", ['enseignant' => 8, 'principale' => true])
            ->assertOk()->assertJsonPath('principale', true);

        $this->assertDatabaseHas('T_CORPROFCLASSE', ['Code' => $math, 'Principale' => false], 'economat');
        $this->assertDatabaseHas('T_CORPROFCLASSE', ['Code' => $fr, 'Principale' => true], 'economat');
    }

    public function test_le_titulaire_d_une_autre_classe_n_est_pas_affecte(): void
    {
        $autre = $this->creer(['classe' => 'CP1B', 'principale' => true])->assertCreated()->json('id');
        $ici = $this->creer(['principale' => true])->assertCreated()->json('id');

        $this->assertDatabaseHas('T_CORPROFCLASSE', ['Code' => $autre, 'Principale' => true], 'economat');
        $this->assertDatabaseHas('T_CORPROFCLASSE', ['Code' => $ici, 'Principale' => true], 'economat');
    }

    // --- Retrait ---

    public function test_retirer_une_affectation_dont_rien_ne_depend(): void
    {
        $id = $this->creer()->assertCreated()->json('id');

        $this->deleteJson("/api/v1/affectations-enseignants/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('T_CORPROFCLASSE', ['Code' => $id], 'economat');
    }

    public function test_le_retrait_est_refuse_si_des_creneaux_en_dependent(): void
    {
        $id = $this->creer()->assertCreated()->json('id');

        DB::connection('economat')->table('T_EMPLOIDUTEMPS')->insert([
            'CODE' => 1, 'CODEJOUR' => 1, 'CODEHEURE' => 1, 'CODECLASSE' => 'CP1A',
            'CODEMATIERE' => 'MATH', 'CODESALLE' => 'S1', 'ANNEE' => self::ANNEE,
        ]);

        $r = $this->deleteJson("/api/v1/affectations-enseignants/{$id}")->assertStatus(409);
        $this->assertStringContainsString("créneau", $r->json('message'));

        // Rien n'a été supprimé.
        $this->assertDatabaseHas('T_CORPROFCLASSE', ['Code' => $id], 'economat');

        // Et la ligne annonce le blocage avant même qu'on tente le retrait.
        $ligne = collect($this->getJson('/api/v1/affectations-enseignants?classe=CP1A')->json('affectations'))->first();
        $this->assertFalse($ligne['retirable']);
        $this->assertSame(1, $ligne['creneaux']);
    }

    public function test_le_retrait_est_refuse_si_des_notes_en_dependent(): void
    {
        $id = $this->creer()->assertCreated()->json('id');

        DB::connection('economat')->table('V_NOTECLASSE')->insert([
            'Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'K', 'Prenom' => 'A', 'Note' => 12,
            'CodeMatiere' => 'MATH', 'CodeClasse' => 'CP1A', 'CodeAnnee' => '2025',
        ]);

        $r = $this->deleteJson("/api/v1/affectations-enseignants/{$id}")->assertStatus(409);
        $this->assertStringContainsString('note', $r->json('message'));
        $this->assertDatabaseHas('T_CORPROFCLASSE', ['Code' => $id], 'economat');
    }

    public function test_remplacer_l_enseignant_reste_possible_meme_avec_des_creneaux(): void
    {
        $id = $this->creer()->assertCreated()->json('id');
        DB::connection('economat')->table('T_EMPLOIDUTEMPS')->insert([
            'CODE' => 1, 'CODEJOUR' => 1, 'CODEHEURE' => 1, 'CODECLASSE' => 'CP1A',
            'CODEMATIERE' => 'MATH', 'CODESALLE' => 'S1', 'ANNEE' => self::ANNEE,
        ]);

        // C'est le geste à faire quand un professeur change en cours d'année : le créneau
        // suit, puisque l'emploi du temps déduit l'enseignant de cette table.
        $this->putJson("/api/v1/affectations-enseignants/{$id}", ['enseignant' => 8])
            ->assertOk()->assertJsonPath('enseignant_nom', 'Ama Koffi');
    }

    // --- Année clôturée ---

    public function test_une_annee_cloturee_est_en_consultation_seule(): void
    {
        $id = $this->creer()->assertCreated()->json('id');
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->creer(['classe' => 'CP1C'])->assertStatus(423);
        $this->putJson("/api/v1/affectations-enseignants/{$id}", ['enseignant' => 8])->assertOk();
    }
}
