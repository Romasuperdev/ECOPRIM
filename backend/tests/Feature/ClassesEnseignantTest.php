<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Fiche enseignant : classes où il donne cours, et visibilité d'un enseignant sans classe. */
class ClassesEnseignantTest extends TestCase
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
        $eco('T_ANNEEACADEMIQUE')->insert([['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE, 'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01']]);
        $eco('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'S'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'École Bêta', 'CodeSociete' => 'S'],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CM1A', 'LibelleClasse' => 'CM1 A', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E1'],
            ['num' => 2, 'CodeClasse' => 'CM2A', 'LibelleClasse' => 'CM2 A', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E1'],
            ['num' => 3, 'CodeClasse' => 'CP1B', 'LibelleClasse' => 'CP1 B', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E2'],
        ]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'FR', 'LibelleMatiere' => 'Français'],
            ['Code' => 2, 'CodeMatiere' => 'MAT', 'LibelleMatiere' => 'Mathématiques'],
        ]);
    }

    private function travaillerDans(string $etab): void
    {
        $this->postJson('/api/v1/contexte/etablissement', ['code' => $etab])->assertOk();
    }

    private function fiche(array $extra = []): array
    {
        return array_merge(['matricule' => 'P1', 'nom' => 'Konan', 'prenom' => 'Marc'], $extra);
    }

    private function affectations(int $prof): array
    {
        return DB::connection('economat')->table('T_CORPROFCLASSE')->where('CodeProfesseur', $prof)
            ->orderBy('CodeClasse')->orderBy('CodeMatiere')->get(['CodeClasse', 'CodeMatiere'])
            ->map(fn ($l) => $l->CodeClasse.'/'.$l->CodeMatiere)->all();
    }

    public function test_les_classes_cochees_deviennent_des_affectations(): void
    {
        $this->travaillerDans('E1');
        $r = $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['FR', 'MAT'], 'classes' => ['CM1A']]))->assertCreated();

        $this->assertSame(['CM1A'], $r->json('classes'));
        $this->assertSame(['CM1A/FR', 'CM1A/MAT'], $this->affectations($r->json('id')));
    }

    public function test_un_enseignant_sans_classe_reste_visible_dans_son_etablissement(): void
    {
        $this->travaillerDans('E1');
        $id = $this->postJson('/api/v1/enseignants', $this->fiche())->assertCreated()->json('id');

        $this->assertSame('E1', DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $id)->value('Site'));
        $this->assertContains($id, collect($this->getJson('/api/v1/enseignants')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/enseignants/{$id}")->assertOk();

        // Il n'apparaît pas dans un autre établissement.
        $this->travaillerDans('E2');
        $this->assertNotContains($id, collect($this->getJson('/api/v1/enseignants')->json('data'))->pluck('id')->all());
    }

    public function test_une_matiere_deja_assuree_n_est_pas_ecrasee(): void
    {
        $this->travaillerDans('E1');
        $autre = $this->postJson('/api/v1/enseignants', $this->fiche(['matricule' => 'P0', 'nom' => 'Bamba', 'matieres' => ['MAT'], 'classes' => ['CM1A']]))->json('id');

        $r = $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['FR', 'MAT'], 'classes' => ['CM1A']]))->assertCreated();

        $this->assertSame(['CM1A/FR'], $this->affectations($r->json('id')));
        $this->assertSame(['CM1A/MAT'], $this->affectations($autre));
        $this->assertStringContainsString('déjà assurée par Bamba', $r->json('avertissements.0'));
    }

    public function test_decocher_une_classe_retire_ses_affectations(): void
    {
        $this->travaillerDans('E1');
        $id = $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['FR'], 'classes' => ['CM1A', 'CM2A']]))->json('id');

        $this->putJson("/api/v1/enseignants/{$id}", $this->fiche(['matieres' => ['FR'], 'classes' => ['CM2A']]))->assertOk()
            ->assertJsonPath('classes', ['CM2A']);
        $this->assertSame(['CM2A/FR'], $this->affectations($id));
    }

    public function test_des_classes_sans_matiere_sont_refusees(): void
    {
        $this->travaillerDans('E1');
        $this->postJson('/api/v1/enseignants', $this->fiche(['classes' => ['CM1A']]))
            ->assertStatus(422)->assertJsonValidationErrors('classes');
    }

    public function test_une_classe_d_un_autre_etablissement_est_refusee(): void
    {
        $this->travaillerDans('E1');
        $this->postJson('/api/v1/enseignants', $this->fiche(['matieres' => ['FR'], 'classes' => ['CP1B']]))
            ->assertStatus(422)->assertJsonValidationErrors('classes');
        $this->assertSame(0, DB::connection('economat')->table('T_PROFESSEUR')->count());
    }
}
