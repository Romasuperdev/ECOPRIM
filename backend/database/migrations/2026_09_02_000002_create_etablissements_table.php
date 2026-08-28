<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etablissements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->noActionOnDelete();
            $table->string('code', 30)->unique();
            $table->string('nom');
            $table->string('type', 40)->nullable(); // maternelle, primaire, college, lycee, groupe_scolaire, secondaire
            $table->string('logo')->nullable();
            $table->string('couleur_primaire', 10)->nullable();
            $table->string('couleur_secondaire', 10)->nullable();
            $table->string('adresse')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('site_web')->nullable();
            $table->string('nom_responsable')->nullable();
            $table->string('email_directeur')->nullable();
            $table->string('statut', 20)->default('brouillon'); // brouillon, actif, inactif
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etablissements');
    }
};
