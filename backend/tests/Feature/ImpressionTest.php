<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Documents imprimables : fiche élève, fiche enseignant, emploi du temps, liste de classe.
 *
 * L'impression est une lecture : elle reste possible sur une année clôturée, et elle
 * respecte l'année de travail choisie dans l'entête.
 */
class ImpressionTest extends TestCase
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
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques'],
        ]);
        $eco('T_EMPJOUR')->insert([['Code' => 1, 'Libelle' => 'Lundi']]);
        $eco('T_HORAIRE')->insert([
            ['COD_HORAIRE' => 1, 'HEUR_DEBUT' => '08:00', 'HEUR_FIN' => '09:00', 'DUREEE' => '1h'],
        ]);
        $eco('T_SALLESCLASSE')->insert([
            ['CODE' => 1, 'CODESALLE' => 'S1', 'LIBELLESALLE' => 'Salle 1', 'NBREPLACE' => 40],
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Traoré',
                'PrenomProfesseur' => 'Moussa', 'NomComplet' => 'Moussa Traoré'],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 11, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa',
                'Sexe' => 'F', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 12, 'Matricule' => 'E-12', 'Nom' => 'Bamba', 'Prenom' => 'Ali',
                'Sexe' => 'M', 'CodeClasse' => 'CP1A', 'AnneeAcad' => '2024-2025'],
        ]);
    }

    /**
     * La fiche telle qu'elle sera imprimée, en HTML.
     *
     * Le PDF produit est compressé : on ne peut pas y chercher une chaîne. On rend donc la
     * même vue avec les données que le contrôleur lui donne VRAIMENT — obtenues par
     * réflexion sur ses méthodes privées, plutôt qu'en les recopiant dans le test, ce qui
     * n'aurait éprouvé que la copie.
     */
    private function ficheEleveHtml(\App\Models\Eleve $eleve): string
    {
        $controleur = app(\App\Http\Controllers\Api\V1\ImpressionController::class);
        $classe = new \ReflectionClass($controleur);
        $appeler = function (string $methode, ...$args) use ($classe, $controleur) {
            $m = $classe->getMethod($methode);
            $m->setAccessible(true);

            return $m->invoke($controleur, ...$args);
        };

        return View::make('pdf.eleve', [
            'etablissement' => 'ETAB001',
            'annee' => self::ANNEE,
            'v' => fn ($x) => (trim((string) $x) !== '') ? e($x) : '<span class="vide">Non renseigné</span>',
            'd' => fn ($x) => $appeler('dateLisible', $x),
            'eleve' => $eleve,
            'classe' => $appeler('libelleClasse', $eleve->classe_code),
            'photo' => null,
            'details' => $appeler('detailsEleve', $eleve),
        ])->render();
    }

    /**
     * Ce que la fiche doit PORTER, et pas seulement qu'elle sorte. Ces coordonnées sont
     * saisies à l'inscription et n'étaient réaffichées nulle part : les perdre à nouveau
     * passerait inaperçu sans cette vérification.
     */
    public function test_la_fiche_eleve_porte_ce_que_l_inscription_a_saisi(): void
    {
        DB::connection('economat')->table('T_ETUDIANT')->where('Code', 11)->update([
            'DateNaiss' => '2015-02-14',
            'Adresse' => 'Rue des Jardins', 'Quartier' => 'Cocody', 'Commune' => 'Abidjan',
            'Ville' => 'Abidjan', 'Telephone' => '0102030405', 'Email' => 'awa@ecole.ci',
            'DateInscription' => '2025-09-09', 'EtabOrigine' => 'EPP Bouaké',
            'NiveauOrigine' => 'CP1', 'Transfert' => 1,
        ]);

        $html = $this->ficheEleveHtml(\App\Models\Eleve::findOrFail(11));

        foreach (['Rue des Jardins', 'Cocody', '0102030405', 'awa@ecole.ci', 'EPP Bouaké'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }

        // Le mouvement se déduit des trois indicateurs d'ECONOMAT.
        $this->assertStringContainsString('Transfert', $html);
        // Les dates sont écrites à la française, et l'âge accompagne la naissance.
        $this->assertStringContainsString('14/02/2015', $html);
        $this->assertStringContainsString('09/09/2025', $html);
        $this->assertStringContainsString('ans)', $html);
        // Aucune date brute d'SQL Server ne doit subsister.
        $this->assertStringNotContainsString('2015-02-14', $html);
    }

    public function test_un_champ_vide_se_dit_sur_la_fiche(): void
    {
        $html = $this->ficheEleveHtml(\App\Models\Eleve::findOrFail(11));

        // Une case laissée blanche laisserait croire à un oubli d'impression.
        $this->assertStringContainsString('Non renseigné', $html);
    }

    private function assertPdf($reponse): void
    {
        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));
        // dompdf produit toujours un flux commençant par la signature PDF.
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_la_fiche_d_un_eleve_s_imprime(): void
    {
        $this->assertPdf($this->get('/api/v1/impressions/eleves/11'));
    }

    public function test_la_fiche_d_un_enseignant_s_imprime(): void
    {
        $this->assertPdf($this->get('/api/v1/impressions/enseignants/7'));
    }

    public function test_l_emploi_du_temps_s_imprime(): void
    {
        DB::connection('economat')->table('T_EMPLOIDUTEMPS')->insert([
            'CODE' => 1, 'CODEJOUR' => 1, 'CODEHEURE' => 1, 'CODECLASSE' => 'CP1A',
            'CODEMATIERE' => 'MATH', 'CODESALLE' => 'S1', 'ANNEE' => self::ANNEE,
        ]);

        $this->assertPdf($this->get('/api/v1/impressions/emploi-du-temps?classe=CP1A'));
    }

    /** Le PDF étant binaire, on intercepte les données passées à la vue. */
    private function donneesDeLaVue(string $vue, string $url): array
    {
        $capture = [];
        View::creator($vue, function ($v) use (&$capture) { $capture = $v->getData(); });
        $this->assertPdf($this->get($url));

        return $capture;
    }

    public function test_la_liste_de_classe_s_imprime_et_suit_l_annee(): void
    {
        $vue = $this->donneesDeLaVue('pdf.liste-classe', '/api/v1/impressions/liste-classe?classe=CP1A');

        $this->assertSame('CP1 A', $vue['classeLibelle']);
        $this->assertSame(['Kouassi'], $vue['eleves']->pluck('nom')->all());

        // Bascule sur l'année précédente : c'est l'autre élève qui doit sortir.
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();
        $vue = $this->donneesDeLaVue('pdf.liste-classe', '/api/v1/impressions/liste-classe?classe=CP1A');

        $this->assertSame(['Bamba'], $vue['eleves']->pluck('nom')->all());
    }

    public function test_la_fiche_eleve_embarque_la_photo_en_base64(): void
    {
        $dossier = storage_path('app/photos-test-'.uniqid());
        mkdir($dossier, 0777, true);
        config()->set('nexora.photos_eleves.chemin', $dossier);
        // 1x1 PNG transparent.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        file_put_contents($dossier.'/E-11.png', $png);
        DB::connection('economat')->table('T_ETUDIANT')
            ->where('Code', 11)->update(['Photo' => 'E-11.png']);

        $vue = $this->donneesDeLaVue('pdf.eleve', '/api/v1/impressions/eleves/11');

        // dompdf ne peut pas atteindre le dossier partagé par URL : la photo est incorporée.
        $this->assertStringStartsWith('data:image/png;base64,', $vue['photo']);

        unlink($dossier.'/E-11.png');
        rmdir($dossier);
    }

    public function test_une_annee_cloturee_reste_imprimable(): void
    {
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        // Consultation seule ne veut pas dire impression interdite.
        $this->assertPdf($this->get('/api/v1/impressions/eleves/12'));
        $this->assertPdf($this->get('/api/v1/impressions/liste-classe?classe=CP1A'));
    }

    public function test_la_classe_est_obligatoire_pour_les_documents_de_classe(): void
    {
        $this->getJson('/api/v1/impressions/liste-classe')->assertStatus(422);
        $this->getJson('/api/v1/impressions/emploi-du-temps')->assertStatus(422);
    }

    public function test_le_certificat_de_scolarite_s_imprime(): void
    {
        $vue = $this->donneesDeLaVue('pdf.certificat-scolarite', '/api/v1/impressions/eleves/11/certificat-scolarite');

        $this->assertSame('CP1 A', $vue['classe']);
        $this->assertNull($vue['motif']);
    }

    public function test_l_attestation_de_frequentation_s_imprime(): void
    {
        $vue = $this->donneesDeLaVue('pdf.attestation-frequentation', '/api/v1/impressions/eleves/11/attestation-frequentation');

        $this->assertSame('CP1 A', $vue['classe']);
    }

    public function test_le_motif_facultatif_est_transmis_a_la_vue(): void
    {
        $vue = $this->donneesDeLaVue(
            'pdf.certificat-scolarite',
            '/api/v1/impressions/eleves/11/certificat-scolarite?motif=Demande+de+visa'
        );

        $this->assertSame('Demande de visa', $vue['motif']);
    }

    public function test_le_certificat_et_l_attestation_restent_imprimables_sur_une_annee_cloturee(): void
    {
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->assertPdf($this->get('/api/v1/impressions/eleves/12/certificat-scolarite'));
        $this->assertPdf($this->get('/api/v1/impressions/eleves/12/attestation-frequentation'));
    }
}
