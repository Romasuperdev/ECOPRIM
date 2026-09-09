<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Bulletin PDF d'un élève.
 *
 * NEXORA ne calcule rien : les moyennes, coefficients et rangs sont ceux d'ECONOMAT
 * (`V_NOTECLASSE`, `V_MOYENNE_ELEVE_CLASSE`). Ces tests vérifient donc la restitution —
 * que le bon élève, la bonne session et le bon rang arrivent dans la vue — et non
 * l'arithmétique, qui n'est pas la nôtre.
 *
 * La « période » d'ECONOMAT est la SESSION : il n'y a pas de table de périodes.
 */
class BulletinTest extends TestCase
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
                'Sexe' => 'F', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 12, 'Matricule' => 'E-12', 'Nom' => 'Bamba', 'Prenom' => 'Ali',
                'Sexe' => 'M', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            // Élève sans classe : le bulletin n'a pas de sens. (CodeClasse à null explicite —
            // SQLite exige le même jeu de colonnes sur toutes les lignes d'un insert groupé.)
            ['Code' => 13, 'Matricule' => 'E-13', 'Nom' => 'Sans', 'Prenom' => 'Classe',
                'Sexe' => 'M', 'CodeClasse' => null, 'AnneeAcad' => self::ANNEE],
        ]);
        $eco('V_NOTECLASSE')->insert([
            ['Code' => 1, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa', 'Note' => 15,
                'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques', 'TypeNote' => 'Devoir',
                'CodeClasse' => 'CP1A', 'CodeSession' => 'S1', 'CodeAnnee' => '2025', 'Coefficient' => 2],
            ['Code' => 2, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa', 'Note' => 11,
                'CodeMatiere' => 'FR', 'LibelleMatiere' => 'Français', 'TypeNote' => 'Devoir',
                'CodeClasse' => 'CP1A', 'CodeSession' => 'S1', 'CodeAnnee' => '2025', 'Coefficient' => 3],
            // Autre session : ne doit pas se mélanger.
            ['Code' => 3, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa', 'Note' => 5,
                'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques', 'TypeNote' => 'Devoir',
                'CodeClasse' => 'CP1A', 'CodeSession' => 'S2', 'CodeAnnee' => '2025', 'Coefficient' => 2],
        ]);
        $eco('V_MOYENNE_ELEVE_CLASSE')->insert([
            ['Code' => 1, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa',
                'Moyenne' => 12.6, 'Rang' => 2, 'CodeClasse' => 'CP1A', 'CodeSession' => 'S1',
                'CodeAnnee' => '2025', 'Passage' => 'Admis'],
            ['Code' => 2, 'Matricule' => 'E-12', 'Nom' => 'Bamba', 'Prenom' => 'Ali',
                'Moyenne' => 14.2, 'Rang' => 1, 'CodeClasse' => 'CP1A', 'CodeSession' => 'S1',
                'CodeAnnee' => '2025', 'Passage' => 'Admis'],
        ]);
    }

    private function assertPdf($reponse): void
    {
        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    /** Le PDF étant binaire, on intercepte les données passées à la vue. */
    private function donneesDeLaVue(string $url): array
    {
        $capture = [];
        View::creator('pdf.bulletin', function ($v) use (&$capture) { $capture = $v->getData(); });
        $this->assertPdf($this->get($url));

        return $capture;
    }

    public function test_le_bulletin_s_imprime(): void
    {
        $this->assertPdf($this->get('/api/v1/eleves/11/bulletin?session=S1'));
    }

    public function test_le_bulletin_porte_le_rang_et_l_effectif_calcules_par_economat(): void
    {
        $vue = $this->donneesDeLaVue('/api/v1/eleves/11/bulletin?session=S1');

        $this->assertSame('E-11', $vue['eleve']->matricule);
        $this->assertSame(self::ANNEE, $vue['annee']);
        $this->assertSame('S1', $vue['session']);
        // Rang et moyenne viennent de la vue ECONOMAT, pas d'un calcul local.
        $this->assertSame(2, (int) $vue['rang']);
        $this->assertEqualsWithDelta(12.6, (float) $vue['moyenneGenerale'], 0.01);
        // L'effectif est le nombre d'élèves classés dans la classe.
        $this->assertSame(2, $vue['effectif']);
    }

    public function test_le_bulletin_ne_melange_pas_les_sessions(): void
    {
        $s1 = $this->donneesDeLaVue('/api/v1/eleves/11/bulletin?session=S1');
        $matieres = collect($s1['moyennesParMatiere']);

        $this->assertSame(['FR', 'MATH'], $matieres->pluck('matiere_code')->sort()->values()->all());
        // La note de 5 appartient à S2 : elle ne doit pas peser sur la moyenne de maths.
        $this->assertEqualsWithDelta(15.0, (float) $matieres->firstWhere('matiere_code', 'MATH')['moyenne'], 0.01);

        $s2 = $this->donneesDeLaVue('/api/v1/eleves/11/bulletin?session=S2');
        $this->assertSame(['MATH'], collect($s2['moyennesParMatiere'])->pluck('matiere_code')->all());
    }

    public function test_les_coefficients_d_economat_sont_restitues(): void
    {
        $vue = $this->donneesDeLaVue('/api/v1/eleves/11/bulletin?session=S1');
        $matieres = collect($vue['moyennesParMatiere']);

        $this->assertSame(2.0, (float) $matieres->firstWhere('matiere_code', 'MATH')['coefficient']);
        $this->assertSame(3.0, (float) $matieres->firstWhere('matiere_code', 'FR')['coefficient']);
    }

    public function test_un_eleve_sans_classe_ne_peut_pas_avoir_de_bulletin(): void
    {
        $this->getJson('/api/v1/eleves/13/bulletin?session=S1')->assertStatus(422);
    }

    public function test_un_eleve_sans_note_donne_un_bulletin_vide_et_non_une_erreur(): void
    {
        $vue = $this->donneesDeLaVue('/api/v1/eleves/12/bulletin?session=S9');

        // Session sans note : le bulletin sort, sans moyenne ni rang.
        $this->assertSame([], collect($vue['moyennesParMatiere'])->all());
        $this->assertNull($vue['moyenneGenerale']);
        $this->assertNull($vue['rang']);
    }

    /** Aperçu à l'écran (fiche élève, page Résultats) : mêmes données que le PDF, en JSON. */
    public function test_les_donnees_du_bulletin_sont_exposees_en_json(): void
    {
        $r = $this->getJson('/api/v1/eleves/11/bulletin/donnees?session=S1')->assertOk();

        $this->assertSame(2, (int) $r->json('rang'));
        $this->assertEqualsWithDelta(12.6, (float) $r->json('moyenneGenerale'), 0.01);
        $this->assertSame(2, $r->json('effectif'));
        $this->assertSame(['FR', 'MATH'], collect($r->json('moyennesParMatiere'))->pluck('matiere_code')->sort()->values()->all());
    }

    public function test_le_bulletin_suit_l_annee_de_travail(): void
    {
        // Sur l'année précédente, cet élève n'a aucune note : le bulletin reste imprimable
        // mais vide, ce qui vaut mieux qu'un bulletin de la mauvaise année.
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $vue = $this->donneesDeLaVue('/api/v1/eleves/11/bulletin?session=S1');

        $this->assertSame('2024-2025', $vue['annee']);
        $this->assertSame([], collect($vue['moyennesParMatiere'])->all());
    }
}
