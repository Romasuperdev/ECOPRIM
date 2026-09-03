<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EleveController;
use App\Http\Controllers\Api\V1\ClasseController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmploiDuTempsController;
use App\Http\Controllers\Api\V1\ImpressionController;
use App\Http\Controllers\Api\V1\NiveauController;
use App\Http\Controllers\Api\V1\AnneeScolaireController;
use App\Http\Controllers\Api\V1\PeriodeController;
use App\Http\Controllers\Api\V1\EnseignantController;
use App\Http\Controllers\Api\V1\MatiereController;
use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\SanctionController;
use App\Http\Controllers\Api\V1\ParentController;
use App\Http\Controllers\Api\V1\SeanceController;
use App\Http\Controllers\Api\V1\RapportController;
use App\Http\Controllers\Api\V1\ProgrammeController;
use App\Http\Controllers\Api\V1\RessourceController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentEtablissementController;
use App\Http\Controllers\Api\V1\InscriptionController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\AnnonceController;
use App\Http\Controllers\Api\V1\ConseilClasseController;
use App\Http\Controllers\Api\V1\DeliberationController;
use App\Http\Controllers\Api\V1\BulletinController;
use App\Http\Controllers\Api\V1\CycleController;
use App\Http\Controllers\Api\V1\CoefficientController;
use App\Http\Controllers\Api\V1\SocieteController;
use App\Http\Controllers\Api\V1\EtablissementController;
use App\Http\Controllers\Api\V1\AffectationController;
use App\Http\Controllers\Api\V1\AffectationEnseignantController;
use App\Http\Controllers\Api\V1\CommunicationController;
use App\Http\Controllers\Api\V1\ConsoleContexteController;
use App\Http\Controllers\Api\V1\ContexteController;
use App\Http\Controllers\Api\V1\Parametres\MailConfigController;
use App\Http\Controllers\Api\V1\Parametres\PrerequisController;
use App\Http\Controllers\Api\V1\Parametres\SmsConfigController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\JournalActiviteController;

// Routes API ECOPRIM - v1
Route::prefix('v1')->group(function () {

    // Publiques
    Route::post('/login', [AuthController::class, 'login']);

    // Public : libellé de l'établissement rattaché à un identifiant, affiché sur la page
    // de connexion. Limité en débit pour freiner l'énumération d'identifiants.
    Route::post('/etablissement-du-compte', [AuthController::class, 'etablissementDuCompte'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('eleves', [EleveController::class, 'index']);
        Route::get('eleves/{eleve}', [EleveController::class, 'show']);
        Route::get('classes', [ClasseController::class, 'index']);
        Route::get('classes/{classe}', [ClasseController::class, 'show']);
        // Emplois du temps : grille par classe, bornée à l'année de travail.
        Route::get('emplois-du-temps/referentiels', [EmploiDuTempsController::class, 'referentiels']);
        Route::get('emplois-du-temps', [EmploiDuTempsController::class, 'index']);
        Route::post('emplois-du-temps', [EmploiDuTempsController::class, 'store']);
        Route::put('emplois-du-temps/{creneau}', [EmploiDuTempsController::class, 'update']);
        Route::delete('emplois-du-temps/{creneau}', [EmploiDuTempsController::class, 'destroy']);

        // Affectation enseignant <-> classe <-> matiere (ECONOMAT.T_CORPROFCLASSE).
        // En amont de l'emploi du temps, qui en deduit l'enseignant de chaque creneau.
        Route::get('affectations-enseignants/referentiels', [AffectationEnseignantController::class, 'referentiels']);
        Route::get('affectations-enseignants', [AffectationEnseignantController::class, 'index']);
        Route::post('affectations-enseignants', [AffectationEnseignantController::class, 'store']);
        Route::put('affectations-enseignants/{affectation}', [AffectationEnseignantController::class, 'update']);
        Route::delete('affectations-enseignants/{affectation}', [AffectationEnseignantController::class, 'destroy']);

        Route::get('notes', [NoteController::class, 'index']);
        Route::get('enseignants', [EnseignantController::class, 'index']);
        Route::post('enseignants', [EnseignantController::class, 'store']);
        Route::get('enseignants/{enseignant}', [EnseignantController::class, 'show']);
        Route::put('enseignants/{enseignant}', [EnseignantController::class, 'update']);
        Route::get('matieres', [MatiereController::class, 'index']);
        Route::get('matieres/{matiere}', [MatiereController::class, 'show']);
        Route::get('absences', [AbsenceController::class, 'index']);
        Route::get('absences/{absence}', [AbsenceController::class, 'show']);
        Route::apiResource('sanctions', SanctionController::class);
        Route::apiResource('parents', ParentController::class);
        Route::post('parents/{parent}/eleves', [ParentController::class, 'attachEleve']);
        Route::delete('parents/{parent}/eleves/{eleve}', [ParentController::class, 'detachEleve']);
        Route::apiResource('seances', SeanceController::class);
        Route::get('niveaux', [NiveauController::class, 'index']);
        Route::get('niveaux/{niveau}', [NiveauController::class, 'show']);
        Route::get('cycles', [CycleController::class, 'index']);
        // Années scolaires : lecture seule (ECONOMAT.T_ANNEEACADEMIQUE)
        Route::get('annees-scolaires', [AnneeScolaireController::class, 'index']);
        Route::get('annees-scolaires/{anneeScolaire}', [AnneeScolaireController::class, 'show']);
        Route::get('coefficients', [CoefficientController::class, 'index']);
        Route::post('coefficients', [CoefficientController::class, 'store']);
        Route::delete('coefficients/{coefficient}', [CoefficientController::class, 'destroy']);
        Route::get('periodes', [PeriodeController::class, 'index']);
        Route::get('classes/{classe}/moyennes', [RapportController::class, 'moyennesClasse']);
        Route::get('classes/{classe}/assiduite', [RapportController::class, 'assiduiteClasse']);
        Route::get('evaluations', [RapportController::class, 'evaluations']);
        // Documents imprimables (PDF) — lecture seule, une année clôturée s'imprime.
        Route::get('impressions/eleves/{eleve}', [ImpressionController::class, 'eleve']);
        Route::get('impressions/enseignants/{enseignant}', [ImpressionController::class, 'enseignant']);
        Route::get('impressions/emploi-du-temps', [ImpressionController::class, 'emploiDuTemps']);
        Route::get('impressions/liste-classe', [ImpressionController::class, 'listeClasse']);

        Route::get('eleves/{eleve}/bulletin', [BulletinController::class, 'show']);
        Route::get('dashboard/stats', [DashboardController::class, 'index']);

        // Pédagogie / Enseignement
        Route::apiResource('programmes', ProgrammeController::class);
        Route::apiResource('ressources', RessourceController::class);

        // Scolarité
        // Inscriptions = saisie d'un élève dans T_ETUDIANT. Pas de suppression : table partagée.
        Route::get('inscriptions', [InscriptionController::class, 'index']);
        Route::post('inscriptions', [InscriptionController::class, 'store']);
        Route::get('inscriptions/{inscription}', [InscriptionController::class, 'show']);
        Route::put('inscriptions/{inscription}', [InscriptionController::class, 'update']);
        Route::get('inscriptions/{inscription}/photo', [InscriptionController::class, 'photo']);
        Route::post('inscriptions/{inscription}/photo', [InscriptionController::class, 'televerserPhoto']);

        // Documents
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{document}/telecharger', [DocumentController::class, 'download']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
        Route::get('documents-etablissement', [DocumentEtablissementController::class, 'index']);
        Route::post('documents-etablissement', [DocumentEtablissementController::class, 'store']);
        Route::get('documents-etablissement/{document}/telecharger', [DocumentEtablissementController::class, 'download']);
        Route::delete('documents-etablissement/{document}', [DocumentEtablissementController::class, 'destroy']);

        // Communication
        Route::get('messages', [MessageController::class, 'index']);
        Route::post('messages', [MessageController::class, 'store']);
        Route::post('messages/{message}/lire', [MessageController::class, 'markAsRead']);
        Route::get('destinataires', [MessageController::class, 'destinataires']);
        Route::apiResource('annonces', AnnonceController::class)->only(['index', 'store', 'destroy']);

        // Conseil de classe
        Route::apiResource('conseils-classe', ConseilClasseController::class)
            ->parameters(['conseils-classe' => 'conseil']);
        Route::post('conseils-classe/{conseil}/deliberations', [DeliberationController::class, 'store']);
        Route::delete('conseils-classe/{conseil}/deliberations/{deliberation}', [DeliberationController::class, 'destroy']);

        // Console Administrative — gestion (base propre ECOPRIM). Réservée au Super Admin.
        // Paramètres (ECONOMAT) : documents élèves, passerelle SMS, messagerie SMTP.
        Route::get('parametres/prerequis', [PrerequisController::class, 'index']);
        Route::post('parametres/prerequis', [PrerequisController::class, 'store']);
        Route::put('parametres/prerequis/{prerequis}', [PrerequisController::class, 'update']);

        Route::get('parametres/sms', [SmsConfigController::class, 'show']);
        Route::post('parametres/sms', [SmsConfigController::class, 'store']);

        Route::get('parametres/mail', [MailConfigController::class, 'show']);
        Route::post('parametres/mail', [MailConfigController::class, 'store']);

        // Communication : envoi SMS/mail + historique.
        Route::post('communication/envoyer', [CommunicationController::class, 'envoyer']);
        Route::get('communication/historique', [CommunicationController::class, 'historique']);

        // Contexte de travail : choix de l'établissement courant (tout utilisateur connecté).
        Route::get('contexte', [ContexteController::class, 'show']);
        Route::post('contexte/etablissement', [ContexteController::class, 'store']);
        Route::delete('contexte/etablissement', [ContexteController::class, 'destroy']);
        Route::post('contexte/annee', [ContexteController::class, 'definirAnnee']);

        // Console générale — Super Admin seul : le catalogue des sociétés et des rôles.
        Route::middleware('console:generale')->group(function () {
            Route::get('societes', [SocieteController::class, 'index']);
            Route::post('societes', [SocieteController::class, 'store']);
            Route::post('societes/importer', [SocieteController::class, 'importer']);
            Route::get('societes/{societe}', [SocieteController::class, 'show']);
            Route::put('societes/{societe}', [SocieteController::class, 'update']);
            Route::post('societes/{societe}/activer', [SocieteController::class, 'activer']);
            Route::post('societes/{societe}/desactiver', [SocieteController::class, 'desactiver']);

            // Le catalogue de rôles se MODIFIE depuis la console générale seulement ; sa
            // lecture est plus bas, car un Admin Société doit pouvoir nommer les rôles
            // qu'il affecte.
            Route::post('roles', [RoleController::class, 'store']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        });

        // Console d'une société — Super Admin sur n'importe laquelle, Admin Société sur la
        // sienne. Le cloisonnement par société est appliqué dans les contrôleurs.
        Route::middleware('console:societe')->group(function () {
            Route::get('console/contexte', [ConsoleContexteController::class, 'show']);
            Route::get('console/tableau-de-bord', [ConsoleContexteController::class, 'tableauDeBord']);
            Route::post('console/societe', [ConsoleContexteController::class, 'definirSociete']);

            Route::get('roles', [RoleController::class, 'index']);

            Route::get('etablissements', [EtablissementController::class, 'index']);
            Route::post('etablissements', [EtablissementController::class, 'store']);
            Route::get('etablissements/{code}', [EtablissementController::class, 'show']);
            Route::put('etablissements/{etablissement}', [EtablissementController::class, 'update']);
            Route::post('etablissements/{etablissement}/activer', [EtablissementController::class, 'activer']);
            Route::post('etablissements/{etablissement}/desactiver', [EtablissementController::class, 'desactiver']);

            Route::get('utilisateurs', [UserController::class, 'index']);
            Route::post('utilisateurs', [UserController::class, 'store']);
            Route::get('utilisateurs/{user}', [UserController::class, 'show']);
            Route::put('utilisateurs/{user}', [UserController::class, 'update']);
            Route::post('utilisateurs/{user}/activer', [UserController::class, 'activer']);
            Route::post('utilisateurs/{user}/desactiver', [UserController::class, 'desactiver']);

            Route::post('affectations', [AffectationController::class, 'store']);
            Route::delete('affectations/{affectation}', [AffectationController::class, 'destroy']);
        });
    });
});
