<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Rebranche la connexion `master` sur une base SQLite en mémoire reproduisant les
     * tables de dbmasterbacou nécessaires à l'authentification lecture seule :
     * RH_USER + users + roles + role_user + societe_utilisateur.
     */
    protected function setUpMasterDb(): void
    {
        config(['database.connections.master' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('master');

        $s = Schema::connection('master');

        $s->create('RH_USER', function ($t) {
            $t->integer('Id');
            $t->string('Login')->nullable();
            $t->string('Nom')->nullable();
            $t->string('Prenom')->nullable();
            $t->string('Email')->nullable();
            $t->string('MotDePasse')->nullable();
            $t->string('Matricule')->nullable();
            $t->string('Etab')->nullable();
            $t->string('Contact')->nullable();
            $t->boolean('SuperAdmin')->default(false);
            $t->boolean('Supprimer')->default(false);
            $t->string('CodeApp')->nullable();
            $t->string('Profil')->nullable();
            $t->integer('user_id')->nullable();
        });

        $s->create('users', function ($t) {
            $t->integer('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
        });

        $s->create('roles', function ($t) {
            $t->integer('id');
            $t->string('code')->nullable();
            $t->string('name');
            $t->string('codesociete')->nullable();
        });

        $s->create('role_user', function ($t) {
            $t->integer('id');
            $t->integer('user_id');
            $t->integer('role_id');
        });

        $s->create('societe_utilisateur', function ($t) {
            $t->integer('id');
            $t->integer('user_id');
            $t->string('societe_id');
        });

        $s->create('US_SOCIETE', function ($t) {
            $t->string('CODESOCIETE');
            $t->integer('NUMAUTO')->nullable();
            $t->string('NOMBASE')->nullable();
            $t->string('PAYSSOCIETE')->nullable();
            $t->string('NOMSOCIETE')->nullable();
            $t->string('VILLESOCIETE')->nullable();
            $t->string('AD1SOCIETE')->nullable();
            $t->string('TELSOCIETE')->nullable();
            $t->string('EMAILSOCIETE')->nullable();
            $t->string('NOMPRENOMREPRESENTANT')->nullable();
        });

        $s->create('ECO_SOCIETE_SUSPENSION', function ($t) {
            $t->string('CODESOCIETE');
            $t->boolean('SUSPENDU');
        });

        $s->create('T_ETABLISSEMENT', function ($t) {
            $t->integer('Num');
            $t->string('CODE');
            $t->string('RAISONSOCIALE')->nullable();
            $t->string('TYPE')->nullable();
            $t->string('ADRESSE')->nullable();
            $t->string('TELEPHONE')->nullable();
            $t->string('EMAIL')->nullable();
            $t->string('STATUT')->nullable();
            $t->string('NOMRESP')->nullable();
            $t->string('PRENOMRESP')->nullable();
            $t->string('INTITULEDREN')->nullable();
            $t->string('INTITULEIEP')->nullable();
        });
    }
    /**
     * Rebranche la connexion `economat` sur SQLite en mémoire avec T_ANNEEACADEMIQUE,
     * pour tester les écrans pédagogiques en lecture seule sans SQL Server.
     */
    protected function setUpEconomatDb(): void
    {
        AnneeScolaireGuard::oublier();
        ContexteScolaire::oublier();

        config(['database.connections.economat' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('economat');

        Schema::connection('economat')->create('T_ANNEEACADEMIQUE', function ($t) {
            $t->integer('CODE');
            $t->string('CodeAnnee')->nullable();
            $t->string('LibelleAnnee')->nullable();
            $t->boolean('Activer')->nullable();
            $t->boolean('CloturePartielle')->nullable();
            $t->boolean('ClotureDefinitive')->nullable();
            $t->date('DEBUT')->nullable();
            $t->date('FIN')->nullable();
            $t->string('CODESOCIETE')->nullable();
        });

        Schema::connection('economat')->create('T_PREREQUIS', function ($t) {
            $t->integer('CODES');
            $t->string('CODENIVEAU')->nullable();
            $t->string('TYPE')->nullable();
            $t->float('MONTANT')->nullable();
            $t->string('ANNEE')->nullable();
            $t->string('LIBELLE')->nullable();
            $t->string('CODE')->nullable();
            $t->boolean('INSCR')->nullable();
            $t->boolean('SCO')->nullable();
            $t->string('CODEELEVE')->nullable();
            $t->integer('QUANTITE')->nullable();
            $t->string('CODESOCIETE')->nullable();
        });

        Schema::connection('economat')->create('ECO_SMS_CONFIG', function ($t) {
            $t->integer('id');
            $t->string('CODESOCIETE')->nullable();
            $t->string('CODEETABLISSEMENT')->nullable();
            $t->boolean('ENABLED')->default(false);
            $t->string('NAME')->nullable();
            $t->string('PROVIDER')->nullable();
            $t->string('ENVIRONMENT')->default('TEST');
            $t->string('API_URL')->nullable();
            $t->string('API_KEY')->nullable();
            $t->string('API_SECRET')->nullable();
            $t->string('SENDER_ID')->nullable();
            $t->boolean('DELIVERY_REPORTS')->default(false);
            $t->boolean('LONG_SMS')->default(false);
            $t->boolean('AUTO_NOTIF')->default(false);
            $t->dateTime('UPDATED_AT')->nullable();
            $t->string('DESCRIPTION')->nullable();
            $t->string('COUNTRY')->nullable();
            $t->boolean('IS_DEFAULT')->nullable();
        });

        Schema::connection('economat')->create('T_MAIL_DIFFUSION', function ($t) {
            $t->integer('ID_MAIL_DIF');
            $t->string('ADRESS_MAIL')->nullable();
            $t->string('MOT_PASS')->nullable();
            $t->string('SERVEUR_SMTP')->nullable();
            $t->string('code_etab')->nullable();
            $t->integer('PORT_SMTP')->nullable();
            $t->string('CODESOCIETE')->nullable();
        });

        Schema::connection('economat')->create('T_SMS', function ($t) {
            $t->integer('id');
            $t->date('Date')->nullable();
            $t->string('Numero')->nullable();
            $t->string('Message')->nullable();
            $t->string('Heure')->nullable();
            $t->string('Users')->nullable();
            $t->string('Type')->nullable();
        });

        Schema::connection('economat')->create('BEtablissements', function ($t) {
            $t->string('CodeEtablissement');
            $t->string('Intitule')->nullable();
            $t->string('Adresse1')->nullable();
            $t->string('Pays')->nullable();
            $t->string('Ville')->nullable();
            $t->string('SiteWeb')->nullable();
            $t->string('Telephone')->nullable();
            $t->string('Email')->nullable();
            $t->string('CodeSociete')->nullable();
            $t->timestamps();
        });

        Schema::connection('economat')->create('T_CYCLE', function ($t) {
            $t->integer('Num');
            $t->string('CodeCycle');
            $t->string('LibelleCycle')->nullable();
            $t->string('CodeEtab')->nullable();
            $t->boolean('Primaire')->nullable();
        });

        Schema::connection('economat')->create('T_NIVEAU', function ($t) {
            $t->integer('Num');
            $t->string('CodeNiveau');
            $t->string('LibelleNiveau')->nullable();
            $t->string('CodeCycle')->nullable();
            $t->string('CodeFiliere')->nullable();
            $t->boolean('NiveauExamen')->nullable();
            $t->string('ANNEE')->nullable();
            $t->integer('Ordre')->nullable();
            $t->string('CODEETABLISSEMENT')->nullable();
            $t->string('CODESOCIETE')->nullable();
        });

        Schema::connection('economat')->create('T_MATIERE', function ($t) {
            $t->integer('Code');
            $t->string('CodeMatiere')->nullable();
            $t->string('LibelleMatiere')->nullable();
            $t->string('Type')->nullable();
            $t->boolean('Composition')->nullable();
            $t->string('CodeCycle')->nullable();
        });

        Schema::connection('economat')->create('T_CLASSE', function ($t) {
            $t->integer('num');
            $t->string('CodeClasse');
            $t->string('LibelleClasse')->nullable();
            $t->string('CodN')->nullable();
            $t->string('CodeF')->nullable();
            $t->string('ANNEE')->nullable();
            $t->string('CodeSerie')->nullable();
            $t->string('CODESOCIETE')->nullable();
        });

        Schema::connection('economat')->create('T_PROFESSEUR', function ($t) {
            $t->integer('Code');
            $t->string('MatriculeProfesseur')->nullable();
            $t->string('NomProfesseur')->nullable();
            $t->string('PrenomProfesseur')->nullable();
            $t->string('NomComplet')->nullable();
            $t->string('Sexe')->nullable();
            $t->string('EmailProfesseur')->nullable();
            $t->string('ContactProfesseur')->nullable();
            $t->string('Cellulaire')->nullable();
            $t->string('TypeProfesseur')->nullable();
            $t->string('GradeProfesseur')->nullable();
            $t->string('Matiere')->nullable();
            $t->string('DateEmbauche')->nullable();
            $t->integer('SalaireMensuel')->nullable();
            $t->string('AdresseProfesseur')->nullable();
            $t->string('SituationMatrimoniale')->nullable();
            $t->string('DateNaiss')->nullable();
            $t->string('LieuNaiss')->nullable();
            $t->string('DiplTitrUniv')->nullable();
            $t->string('Ville')->nullable();
            $t->string('CodeAnnee')->nullable();
            $t->string('DateDepart')->nullable();
            $t->string('Motif')->nullable();
            $t->string('EtabAccueil')->nullable();
        });

        Schema::connection('economat')->create('T_ETUDIANT', function ($t) {
            $t->integer('Code');
            $t->string('Matricule')->nullable();
            $t->string('Nom')->nullable();
            $t->string('Prenom')->nullable();
            $t->string('Sexe')->nullable();
            $t->date('DateNaiss')->nullable();
            $t->string('LieuNaiss')->nullable();
            $t->string('Nationalite')->nullable();
            $t->string('Photo')->nullable();
            $t->string('Adresse')->nullable();
            $t->string('Email')->nullable();
            $t->string('Telephone')->nullable();
            $t->string('Ville')->nullable();
            $t->string('Commune')->nullable();
            $t->string('Quartier')->nullable();
            $t->string('EtabOrigine')->nullable();
            $t->string('NiveauOrigine')->nullable();
            $t->date('DateInscription')->nullable();
            $t->integer('Inscription')->nullable();
            $t->integer('Reinscription')->nullable();
            $t->integer('Transfert')->nullable();
            $t->string('CODESOCIETE')->nullable();
            $t->string('CodeClasse')->nullable();
            $t->string('CodeNiveau')->nullable();
            $t->string('CodeCycle')->nullable();
            $t->string('AnneeAcad')->nullable();
            $t->integer('Etat')->nullable();
            $t->string('Redoublant')->nullable();
            $t->string('NomPereTuteur')->nullable();
            $t->string('PrenomPereTuteur')->nullable();
            $t->string('ProfessionPereTuteur')->nullable();
            $t->string('TelephonePereTuteur')->nullable();
            $t->string('EmailPereTuteur')->nullable();
            $t->string('NomMere')->nullable();
            $t->string('PrenomMere')->nullable();
            $t->string('ProfessionMere')->nullable();
            $t->string('TelephoneMere')->nullable();
            $t->string('EmailMere')->nullable();
            $t->integer('Scolarite')->nullable();
        });

        Schema::connection('economat')->create('V_NOTECLASSE', function ($t) {
            $t->integer('Code');
            $t->string('Matricule')->nullable();
            $t->string('Nom')->nullable();
            $t->string('Prenom')->nullable();
            $t->float('Note')->nullable();
            $t->string('CodeMatiere')->nullable();
            $t->string('LibelleMatiere')->nullable();
            $t->string('TypeNote')->nullable();
            $t->string('CodeClasse')->nullable();
            $t->string('CodeSession')->nullable();
            $t->string('CodeAnnee')->nullable();
            $t->float('Coefficient')->nullable();
        });

        Schema::connection('economat')->create('T_ABSENCEELEVE', function ($t) {
            $t->integer('Code');
            $t->string('Matricule')->nullable();
            $t->string('CodeClasse')->nullable();
            $t->string('Heure')->nullable();
            $t->date('Date')->nullable();
            $t->string('Cause')->nullable();
            $t->string('AnneeCour')->nullable();
            $t->string('CodeSession')->nullable();
            $t->integer('CodeEleve')->nullable();
            $t->boolean('Justifier')->nullable();
        });

        Schema::connection('economat')->create('V_MOYENNE_ELEVE_CLASSE', function ($t) {
            $t->integer('Code');
            $t->string('Nom')->nullable();
            $t->string('Prenom')->nullable();
            $t->float('Moyenne')->nullable();
            $t->string('Rang')->nullable();
            $t->integer('CodeEleve')->nullable();
            $t->string('Matricule')->nullable();
            $t->string('CodeClasse')->nullable();
            $t->string('CodeSession')->nullable();
            $t->string('CodeAnnee')->nullable();
            $t->boolean('Passage')->nullable();
        });
    }
}
