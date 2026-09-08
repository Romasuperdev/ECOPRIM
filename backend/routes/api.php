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
use App\Http\Controllers\Api\V1\EnseignantController;
use App\Http\Controllers\Api\V1\MatiereController;
use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\ParentController;
use App\Http\Controllers\Api\V1\RapportController;
use App\Http\Controllers\Api\V1\InscriptionController;
use App\Http\Controllers\Api\V1\BulletinController;
use App\Http\Controllers\Api\V1\CycleController;
use App\Http\Controllers\Api\V1\SocieteController;
use App\Http\Controllers\Api\V1\EtablissementController;
use App\Http\Controllers\Api\V1\AffectationController;
use App\Http\Controllers\Api\V1\AffectationEnseignantController;
use App\Http\Controllers\Api\V1\CahierTextesController;
use App\Http\Controllers\Api\V1\CommunicationController;
use App\Http\Controllers\Api\V1\ConsoleContexteController;
use App\Http\Controllers\Api\V1\ContexteController;
use App\Http\Controllers\Api\V1\Parametres\MailConfigController;
use App\Http\Controllers\Api\V1\Parametres\PrerequisController;
use App\Http\Controllers\Api\V1\Parametres\SmsConfigController;
use App\Http\Controllers\Api\V1\TracabiliteController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\PortailEnseignantController;
use App\Http\Controllers\Api\V1\PortailParentController;

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

        // Application complète — réservée aux comptes 'staff' (RhUser::typePortail()) :
        // un compte affecté du SEUL rôle Enseignant ou Parent est renvoyé sur son portail
        // restreint (voir plus bas, routes mon-espace/*), jamais sur ces routes-ci.
        Route::middleware('portail:staff')->group(function () {

        Route::get('eleves', [EleveController::class, 'index']);
        Route::get('eleves/{eleve}', [EleveController::class, 'show']);
        // Référentiels ECONOMAT : création et modification ; la suppression est réelle mais
        // refusée dès qu'une ligne s'y rattache (voir DependancesReferentiel).
        Route::get('classes', [ClasseController::class, 'index']);
        Route::get('classes/{classe}', [ClasseController::class, 'show']);
        Route::post('classes', [ClasseController::class, 'store']);
        Route::put('classes/{classe}', [ClasseController::class, 'update']);
        Route::delete('classes/{classe}', [ClasseController::class, 'destroy']);
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

        // Cahier de textes : une semaine par classe, une ligne par matière affectée.
        Route::get('cahier-textes/referentiels', [CahierTextesController::class, 'referentiels']);
        Route::get('cahier-textes', [CahierTextesController::class, 'index']);
        Route::post('cahier-textes', [CahierTextesController::class, 'store']);
        Route::put('cahier-textes/{entete}', [CahierTextesController::class, 'update']);
        Route::delete('cahier-textes/{entete}', [CahierTextesController::class, 'destroy']);
        Route::put('cahier-textes/{entete}/lignes', [CahierTextesController::class, 'enregistrerLigne']);
        Route::delete('cahier-textes/{entete}/lignes/{matiere}', [CahierTextesController::class, 'supprimerLigne']);

        Route::get('notes', [NoteController::class, 'index']);
        Route::get('enseignants', [EnseignantController::class, 'index']);
        Route::post('enseignants', [EnseignantController::class, 'store']);
        Route::get('enseignants/{enseignant}', [EnseignantController::class, 'show']);
        Route::put('enseignants/{enseignant}', [EnseignantController::class, 'update']);
        Route::get('matieres', [MatiereController::class, 'index']);
        Route::get('matieres/{matiere}', [MatiereController::class, 'show']);
        Route::post('matieres', [MatiereController::class, 'store']);
        Route::put('matieres/{matiere}', [MatiereController::class, 'update']);
        Route::delete('matieres/{matiere}', [MatiereController::class, 'destroy']);
        // Absences : saisie, correction et retrait (suppression réelle assumée — une
        // absence mal saisie n'a pas d'autre voie de correction).
        Route::get('absences', [AbsenceController::class, 'index']);
        Route::get('absences/{absence}', [AbsenceController::class, 'show']);
        Route::post('absences', [AbsenceController::class, 'store']);
        Route::put('absences/{absence}', [AbsenceController::class, 'update']);
        Route::delete('absences/{absence}', [AbsenceController::class, 'destroy']);
        // Parents / tuteurs : annuaire DÉRIVÉ des fiches élèves, en lecture seule.
        // Les coordonnées se corrigent dans Inscriptions, seule porte d'écriture de T_ETUDIANT.
        Route::get('parents', [ParentController::class, 'index']);
        Route::get('niveaux', [NiveauController::class, 'index']);
        Route::get('niveaux/{niveau}', [NiveauController::class, 'show']);
        Route::post('niveaux', [NiveauController::class, 'store']);
        Route::put('niveaux/{niveau}', [NiveauController::class, 'update']);
        Route::delete('niveaux/{niveau}', [NiveauController::class, 'destroy']);

        Route::get('cycles', [CycleController::class, 'index']);
        Route::get('cycles/{cycle}', [CycleController::class, 'show']);
        Route::post('cycles', [CycleController::class, 'store']);
        Route::put('cycles/{cycle}', [CycleController::class, 'update']);
        Route::delete('cycles/{cycle}', [CycleController::class, 'destroy']);
        // Années scolaires : lecture seule (ECONOMAT.T_ANNEEACADEMIQUE)
        Route::get('annees-scolaires', [AnneeScolaireController::class, 'index']);
        Route::get('annees-scolaires/{anneeScolaire}', [AnneeScolaireController::class, 'show']);
        Route::post('annees-scolaires', [AnneeScolaireController::class, 'store']);
        Route::put('annees-scolaires/{anneeScolaire}', [AnneeScolaireController::class, 'update']);
        Route::delete('annees-scolaires/{anneeScolaire}', [AnneeScolaireController::class, 'destroy']);
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

        // Scolarité
        // Inscriptions = saisie d'un élève dans T_ETUDIANT. Pas de suppression : table partagée.
        Route::get('inscriptions', [InscriptionController::class, 'index']);
        Route::post('inscriptions', [InscriptionController::class, 'store']);
        Route::get('inscriptions/{inscription}', [InscriptionController::class, 'show']);
        Route::put('inscriptions/{inscription}', [InscriptionController::class, 'update']);
        Route::get('inscriptions/{inscription}/photo', [InscriptionController::class, 'photo']);
        Route::post('inscriptions/{inscription}/photo', [InscriptionController::class, 'televerserPhoto']);

        // Console Administrative — gestion (base propre ECOPRIM). Réservée au Super Admin.
        // Paramètres (ECONOMAT) : documents élèves, passerelle SMS, messagerie SMTP.
        Route::get('parametres/prerequis', [PrerequisController::class, 'index']);
        Route::post('parametres/prerequis', [PrerequisController::class, 'store']);
        Route::put('parametres/prerequis/{prerequis}', [PrerequisController::class, 'update']);
        Route::delete('parametres/prerequis/{prerequis}', [PrerequisController::class, 'destroy']);

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

        }); // fin portail:staff

        // Portail Enseignant — un compte affecté du seul rôle Enseignant : ses classes
        // (déduites de T_PROFESSEUR.LOGIN puis T_CORPROFCLASSE), rien d'autre. Les actions
        // d'écriture délèguent aux contrôleurs existants après vérification que la classe
        // visée est bien la sienne (PortailEnseignantController::assertClasseAutorisee).
        Route::middleware('portail:enseignant')->prefix('mon-espace/enseignant')->group(function () {
            Route::get('classes', [PortailEnseignantController::class, 'classes']);
            Route::get('classes/{classe}/eleves', [PortailEnseignantController::class, 'eleves']);

            Route::get('cahier-textes/referentiels', [PortailEnseignantController::class, 'cahierReferentiels']);
            Route::get('cahier-textes', [PortailEnseignantController::class, 'cahierIndex']);
            Route::post('cahier-textes', [PortailEnseignantController::class, 'cahierStore']);
            Route::put('cahier-textes/{entete}', [PortailEnseignantController::class, 'cahierUpdate']);
            Route::delete('cahier-textes/{entete}', [PortailEnseignantController::class, 'cahierDestroy']);
            Route::put('cahier-textes/{entete}/lignes', [PortailEnseignantController::class, 'cahierLigne']);
            Route::delete('cahier-textes/{entete}/lignes/{matiere}', [PortailEnseignantController::class, 'cahierLigneSupprimer']);

            Route::get('emplois-du-temps/referentiels', [PortailEnseignantController::class, 'emploiReferentiels']);
            Route::get('emplois-du-temps', [PortailEnseignantController::class, 'emploiIndex']);

            Route::get('absences', [PortailEnseignantController::class, 'absencesIndex']);
            Route::post('absences', [PortailEnseignantController::class, 'absencesStore']);
            Route::put('absences/{absence}', [PortailEnseignantController::class, 'absencesUpdate']);
            Route::delete('absences/{absence}', [PortailEnseignantController::class, 'absencesDestroy']);
        });

        // Portail Parent — un compte affecté du seul rôle Parent : uniquement les enfants
        // qui lui sont explicitement rattachés (console_affectation_eleves), en lecture.
        Route::middleware('portail:parent')->prefix('mon-espace/parent')->group(function () {
            Route::get('enfants', [PortailParentController::class, 'enfants']);
            Route::get('enfants/{matricule}', [PortailParentController::class, 'enfant']);
            Route::get('enfants/{matricule}/absences', [PortailParentController::class, 'absences']);
            Route::get('enfants/{matricule}/cahier-textes', [PortailParentController::class, 'cahierTextes']);
            Route::get('enfants/{matricule}/moyennes', [PortailParentController::class, 'moyennes']);
            Route::get('enfants/{matricule}/bulletin', [PortailParentController::class, 'bulletin']);
        });

        // Console générale — Super Admin seul : le catalogue des sociétés.
        Route::middleware('console:generale')->group(function () {
            Route::get('societes', [SocieteController::class, 'index']);
            Route::post('societes', [SocieteController::class, 'store']);
            Route::post('societes/importer', [SocieteController::class, 'importer']);
            Route::get('societes/{societe}', [SocieteController::class, 'show']);
            Route::put('societes/{societe}', [SocieteController::class, 'update']);
            Route::post('societes/{societe}/activer', [SocieteController::class, 'activer']);
            Route::post('societes/{societe}/desactiver', [SocieteController::class, 'desactiver']);
        });

        // Console d'une société — Super Admin sur n'importe laquelle, Admin Société sur la
        // sienne. Le cloisonnement par société est appliqué dans les contrôleurs.
        Route::middleware('console:societe')->group(function () {
            Route::get('console/contexte', [ConsoleContexteController::class, 'show']);
            Route::get('console/tableau-de-bord', [ConsoleContexteController::class, 'tableauDeBord']);
            Route::post('console/societe', [ConsoleContexteController::class, 'definirSociete']);

            // Le catalogue de rôles se MODIFIE au niveau société : un rôle du catalogue
            // général (Super Admin en vue générale) ou un rôle propre à la société
            // courante (Admin Société). Sa lecture est plus bas, ouverte jusqu'à l'Admin
            // Établissement, qui doit pouvoir nommer les rôles qu'il affecte.
            Route::post('roles', [RoleController::class, 'store']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);

            Route::post('etablissements', [EtablissementController::class, 'store']);
            Route::put('etablissements/{etablissement}', [EtablissementController::class, 'update']);
            Route::post('etablissements/{etablissement}/activer', [EtablissementController::class, 'activer']);
            Route::post('etablissements/{etablissement}/desactiver', [EtablissementController::class, 'desactiver']);

            // Traçabilité (ECONOMAT.T_TRACABILITE) — lecture seule, cloisonnée par société.
            Route::get('tracabilite', [TracabiliteController::class, 'index']);
            Route::get('tracabilite/utilisateurs/{user}', [TracabiliteController::class, 'utilisateur']);
        });

        // Le socle commun aux trois niveaux d'administrateur — jusqu'à l'Admin
        // Établissement, borné à son ou ses établissements dans les contrôleurs. C'est le
        // quotidien d'un établissement : créer et gérer SES comptes (Enseignant, Parent...)
        // et leurs affectations, sans devoir passer par un admin de société ou général.
        Route::middleware('console:etablissement')->group(function () {
            Route::get('roles', [RoleController::class, 'index']);

            Route::get('etablissements', [EtablissementController::class, 'index']);
            Route::get('etablissements/{code}', [EtablissementController::class, 'show']);

            Route::get('utilisateurs', [UserController::class, 'index']);
            Route::get('utilisateurs/{user}', [UserController::class, 'show']);
            Route::post('utilisateurs', [UserController::class, 'store']);
            Route::put('utilisateurs/{user}', [UserController::class, 'update']);
            Route::post('utilisateurs/{user}/activer', [UserController::class, 'activer']);
            Route::post('utilisateurs/{user}/desactiver', [UserController::class, 'desactiver']);

            Route::post('affectations', [AffectationController::class, 'store']);
            Route::put('affectations/{affectation}/eleves', [AffectationController::class, 'definirEnfants']);
            Route::delete('affectations/{affectation}', [AffectationController::class, 'destroy']);
        });
    });
});
