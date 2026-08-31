<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Affectation = rôle d'un utilisateur (RH_USER, par Id) DANS un établissement.
        // Un utilisateur peut avoir plusieurs lignes (plusieurs rôles / plusieurs établissements).
        // La société est celle de l'établissement (règle : cohérence société vérifiée côté appli).
        Schema::connection('ecoprim')->create('affectations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rh_user_id'); // dbmasterbacou.RH_USER.Id (lecture seule)
            $table->string('societe_code', 30);
            $table->string('etablissement_code', 30);
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('actif')->default(true);
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->timestamps();

            $table->unique(['rh_user_id', 'etablissement_code', 'role_id'], 'affectation_unique');
            $table->index('rh_user_id');
            $table->index('etablissement_code');
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('affectations');
    }
};
