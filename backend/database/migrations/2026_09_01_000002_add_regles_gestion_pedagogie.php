<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annees_scolaires', function (Blueprint $table) {
            $table->boolean('cloturee')->default(false)->after('active');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->boolean('archivee')->default(false)->after('capacite');
            $table->unique(['niveau_id', 'annee_scolaire_id', 'nom'], 'classe_unique_par_niveau_annee');
        });

        Schema::table('enseignants', function (Blueprint $table) {
            $table->string('statut', 20)->default('titulaire')->after('nom'); // titulaire, vacataire
            $table->boolean('actif')->default(true)->after('statut');
        });

        Schema::table('programmes', function (Blueprint $table) {
            $table->foreignId('annee_scolaire_id')->nullable()->after('matiere_id')
                ->constrained('annees_scolaires')->cascadeOnDelete();
        });

        Schema::table('seances', function (Blueprint $table) {
            $table->foreignId('programme_id')->nullable()->after('matiere_id')
                ->constrained('programmes')->noActionOnDelete();
        });

        Schema::create('niveau_matiere_coefficients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('niveau_id')->constrained('niveaux')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->unsignedInteger('coefficient')->default(1);
            $table->timestamps();

            $table->unique(['niveau_id', 'matiere_id'], 'coefficient_unique_par_niveau_matiere');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('niveau_matiere_coefficients');

        Schema::table('seances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_id');
        });

        Schema::table('programmes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annee_scolaire_id');
        });

        Schema::table('enseignants', function (Blueprint $table) {
            $table->dropColumn(['statut', 'actif']);
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique('classe_unique_par_niveau_annee');
            $table->dropColumn('archivee');
        });

        Schema::table('annees_scolaires', function (Blueprint $table) {
            $table->dropColumn('cloturee');
        });
    }
};
