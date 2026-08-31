<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EleveController;
use App\Http\Controllers\Api\V1\ClasseController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NiveauController;
use App\Http\Controllers\Api\V1\AnneeScolaireController;
use App\Http\Controllers\Api\V1\PeriodeController;
use App\Http\Controllers\Api\V1\EnseignantController;
use App\Http\Controllers\Api\V1\MatiereController;
use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\SanctionController;
use App\Http\Controllers\Api\V1\ParentController;
use App\Http\Controllers\Api\V1\SeanceController;
use App\Http\Controllers\Api\V1\ClasseIntervenantController;
use App\Http\Controllers\Api\V1\RetardController;
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
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\JournalActiviteController;

// Routes API ECOPRIM - v1
Route::prefix('v1')->group(function () {

    // Publiques
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('eleves', [EleveController::class, 'index']);
        Route::get('eleves/{eleve}', [EleveController::class, 'show']);
        Route::get('classes', [ClasseController::class, 'index']);
        Route::get('classes/{classe}', [ClasseController::class, 'show']);
        Route::get('notes', [NoteController::class, 'index']);
        Route::get('enseignants', [EnseignantController::class, 'index']);
        Route::get('enseignants/{enseignant}', [EnseignantController::class, 'show']);
        Route::get('matieres', [MatiereController::class, 'index']);
        Route::get('matieres/{matiere}', [MatiereController::class, 'show']);
        Route::apiResource('absences', AbsenceController::class);
        Route::apiResource('sanctions', SanctionController::class);
        Route::apiResource('parents', ParentController::class);
        Route::post('parents/{parent}/eleves', [ParentController::class, 'attachEleve']);
        Route::delete('parents/{parent}/eleves/{eleve}', [ParentController::class, 'detachEleve']);
        Route::apiResource('seances', SeanceController::class);
        Route::get('classes/{classe}/intervenants', [ClasseIntervenantController::class, 'index']);
        Route::post('classes/{classe}/intervenants', [ClasseIntervenantController::class, 'store']);
        Route::delete('classes/{classe}/intervenants/{intervenant}', [ClasseIntervenantController::class, 'destroy']);
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
        Route::apiResource('retards', RetardController::class);
        Route::get('classes/{classe}/moyennes', [RapportController::class, 'moyennesClasse']);
        Route::get('classes/{classe}/assiduite', [RapportController::class, 'assiduiteClasse']);
        Route::get('evaluations', [RapportController::class, 'evaluations']);
        Route::get('eleves/{eleve}/bulletin', [BulletinController::class, 'show']);
        Route::get('dashboard/stats', [DashboardController::class, 'index']);

        // Pédagogie / Enseignement
        Route::apiResource('programmes', ProgrammeController::class);
        Route::apiResource('ressources', RessourceController::class);

        // Scolarité
        Route::get('inscriptions', [InscriptionController::class, 'index']);
        Route::post('inscriptions', [InscriptionController::class, 'store']);
        Route::get('inscriptions/{inscription}', [InscriptionController::class, 'show']);
        Route::delete('inscriptions/{inscription}', [InscriptionController::class, 'destroy']);

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

        // Console Administrative. Gouvernance plateforme (sociétés, suppression
        // d'établissement, journal global) réservée au Super Admin. Les autres routes sont
        // ouvertes à Admin Société / Admin Établissement mais restreintes à leur périmètre
        // via le scope global BelongsToPerimetre (Etablissement, Affectation) + les
        // FormRequest::authorize() (Store/UpdateEtablissementRequest).
        Route::middleware('role:Super Admin')->group(function () {
            Route::apiResource('societes', SocieteController::class);
            Route::post('societes/{societe}/activer', [SocieteController::class, 'activer']);
            Route::post('societes/{societe}/desactiver', [SocieteController::class, 'desactiver']);
            Route::delete('etablissements/{etablissement}', [EtablissementController::class, 'destroy']);
            Route::get('journal-activite', [JournalActiviteController::class, 'index']);
        });

        Route::middleware('role:Super Admin|Admin Société')->group(function () {
            Route::post('etablissements', [EtablissementController::class, 'store']);
            Route::post('etablissements/{etablissement}/activer', [EtablissementController::class, 'activer']);
            Route::post('etablissements/{etablissement}/desactiver', [EtablissementController::class, 'desactiver']);
            Route::apiResource('affectations', AffectationController::class)->only(['store', 'destroy']);
        });

        Route::middleware('role:Super Admin|Admin Société|Admin Établissement')->group(function () {
            Route::get('etablissements', [EtablissementController::class, 'index']);
            Route::get('etablissements/{etablissement}', [EtablissementController::class, 'show']);
            Route::put('etablissements/{etablissement}', [EtablissementController::class, 'update']);
            Route::get('affectations', [AffectationController::class, 'index']);
            Route::get('utilisateurs', [UserController::class, 'index']);
            Route::get('utilisateurs/{user}', [UserController::class, 'show']);
            Route::get('roles', [RoleController::class, 'index']);
        });
    });
});
