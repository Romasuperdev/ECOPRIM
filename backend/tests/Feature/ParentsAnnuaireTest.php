<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Annuaire des parents / tuteurs — dérivé de ECONOMAT.T_ETUDIANT.
 *
 * Il n'existe pas de table de parents : père/tuteur et mère sont des colonnes de la fiche
 * élève. La page les regroupe. Ces tests portent sur le regroupement, qui est la seule
 * logique de la page — et sur ce qu'il ne doit PAS faire : fusionner deux familles.
 */
class ParentsAnnuaireTest extends TestCase
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

        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
            ['CODE' => 2, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
                'Activer' => false, 'ClotureDefinitive' => true, 'DEBUT' => '2024-09-01'],
        ]);
    }

    private function eleve(array $a): void
    {
        DB::connection('economat')->table('T_ETUDIANT')->insert(array_merge([
            'AnneeAcad' => self::ANNEE, 'CodeClasse' => 'CP1A',
        ], $a));
    }

    public function test_deux_enfants_d_un_meme_parent_donnent_une_seule_ligne(): void
    {
        $this->eleve(['Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'Kone', 'Prenom' => 'Awa',
            'NomPereTuteur' => 'Kone', 'PrenomPereTuteur' => 'Ibrahim',
            'TelephonePereTuteur' => '0700000001', 'ProfessionPereTuteur' => 'Commerçant']);
        $this->eleve(['Code' => 2, 'Matricule' => 'E-2', 'Nom' => 'Kone', 'Prenom' => 'Salif',
            'NomPereTuteur' => 'Kone', 'PrenomPereTuteur' => 'Ibrahim',
            'TelephonePereTuteur' => '0700000001']);

        $r = $this->getJson('/api/v1/parents?lien=pere')->assertOk();

        $this->assertSame(1, $r->json('total'));
        $this->assertSame(2, $r->json('data.0.nb_enfants'));
        // Une coordonnée absente d'une fiche est reprise de l'autre.
        $this->assertSame('Commerçant', $r->json('data.0.profession'));
    }

    public function test_deux_parents_homonymes_sans_telephone_restent_distincts(): void
    {
        // On préfère scinder à tort que fusionner deux familles.
        $this->eleve(['Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'A', 'Prenom' => 'A',
            'NomPereTuteur' => 'Traore', 'PrenomPereTuteur' => 'Moussa']);
        $this->eleve(['Code' => 2, 'Matricule' => 'E-2', 'Nom' => 'B', 'Prenom' => 'B',
            'NomPereTuteur' => 'Traore', 'PrenomPereTuteur' => 'Moussa', 'TelephonePereTuteur' => '0700000009']);

        $this->assertSame(2, $this->getJson('/api/v1/parents?lien=pere')->json('total'));
    }

    public function test_pere_et_mere_apparaissent_tous_les_deux(): void
    {
        $this->eleve(['Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'Kone', 'Prenom' => 'Awa',
            'NomPereTuteur' => 'Kone', 'PrenomPereTuteur' => 'Ibrahim', 'TelephonePereTuteur' => '01',
            'NomMere' => 'Bamba', 'PrenomMere' => 'Fatou', 'TelephoneMere' => '02',
            'EmailMere' => 'fatou@ecole.ci']);

        $r = $this->getJson('/api/v1/parents')->assertOk();

        $this->assertSame(2, $r->json('total'));
        $liens = collect($r->json('data'))->pluck('lien')->sort()->values()->all();
        $this->assertSame(['Mère', 'Père / Tuteur'], $liens);
    }

    public function test_un_parent_non_renseigne_ne_cree_pas_de_ligne_vide(): void
    {
        $this->eleve(['Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'Seul', 'Prenom' => 'Enfant']);

        $this->assertSame(0, $this->getJson('/api/v1/parents')->json('total'));
    }

    public function test_l_annuaire_suit_l_annee_et_se_filtre_par_classe(): void
    {
        $this->eleve(['Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'A', 'Prenom' => 'A',
            'CodeClasse' => 'CP1A', 'NomPereTuteur' => 'Un', 'PrenomPereTuteur' => 'Papa', 'TelephonePereTuteur' => '01']);
        $this->eleve(['Code' => 2, 'Matricule' => 'E-2', 'Nom' => 'B', 'Prenom' => 'B',
            'CodeClasse' => 'CP1B', 'NomPereTuteur' => 'Deux', 'PrenomPereTuteur' => 'Papa', 'TelephonePereTuteur' => '02']);
        $this->eleve(['Code' => 3, 'Matricule' => 'E-3', 'Nom' => 'C', 'Prenom' => 'C',
            'AnneeAcad' => '2024-2025', 'NomPereTuteur' => 'Ancien', 'PrenomPereTuteur' => 'Papa', 'TelephonePereTuteur' => '03']);

        $this->assertSame(2, $this->getJson('/api/v1/parents')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/parents?classe=CP1B')->json('total'));

        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();
        $r = $this->getJson('/api/v1/parents')->assertOk();
        $this->assertSame(1, $r->json('total'));
        $this->assertSame('Ancien', $r->json('data.0.nom'));
    }

    public function test_on_recherche_par_nom_telephone_ou_email(): void
    {
        $this->eleve(['Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'A', 'Prenom' => 'A',
            'NomPereTuteur' => 'Ouattara', 'PrenomPereTuteur' => 'Bakary',
            'TelephonePereTuteur' => '0755123456', 'EmailPereTuteur' => 'bakary@ecole.ci']);
        $this->eleve(['Code' => 2, 'Matricule' => 'E-2', 'Nom' => 'B', 'Prenom' => 'B',
            'NomPereTuteur' => 'Diallo', 'PrenomPereTuteur' => 'Aminata', 'TelephonePereTuteur' => '0799000000']);

        $this->assertSame(1, $this->getJson('/api/v1/parents?q=ouattara')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/parents?q=0755')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/parents?q=bakary@')->json('total'));
        $this->assertSame(0, $this->getJson('/api/v1/parents?q=inconnu')->json('total'));
    }

    public function test_l_annuaire_est_en_lecture_seule(): void
    {
        // La fiche élève n'a qu'une porte d'écriture : Inscriptions.
        $this->postJson('/api/v1/parents', ['nom' => 'X'])->assertStatus(405);
    }
}
