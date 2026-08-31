<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
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
    }
    /**
     * Rebranche la connexion `economat` sur SQLite en mémoire avec T_ANNEEACADEMIQUE,
     * pour tester les écrans pédagogiques en lecture seule sans SQL Server.
     */
    protected function setUpEconomatDb(): void
    {
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
    }
}
