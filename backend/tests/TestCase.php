<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Crée les rôles applicatifs (guard web) utilisés par la Console Administrative
     * et l'application. Réplique le RoleSeeder sans dépendre de la connexion SQL Server.
     */
    protected function seedRoles(): void
    {
        foreach ([
            'Super Admin', 'Admin Société', 'Admin Établissement', 'Direction',
            'Directeur Adjoint', 'Enseignant', 'Secretaire', 'Surveillant', 'Parent', 'Élève',
        ] as $role) {
            Role::findOrCreate($role, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Rebranche la connexion `master` (RH_USER) sur une base SQLite en mémoire pour
     * les tests d'authentification, et y crée une table RH_USER minimale reproduisant
     * les colonnes réellement lues par AuthController::login / syncLocalUser.
     */
    protected function fakeMasterRhUser(): void
    {
        config(['database.connections.master' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge('master');

        Schema::connection('master')->create('RH_USER', function ($table) {
            $table->increments('Id');
            $table->string('Login')->nullable();
            $table->string('Nom')->nullable();
            $table->string('Prenom')->nullable();
            $table->string('Email')->nullable();
            $table->string('MotDePasse')->nullable();
            $table->boolean('SuperAdmin')->default(false);
        });
    }
}
