<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classe_matiere_enseignant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('enseignant_id')->constrained('enseignants')->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->unsignedInteger('coefficient')->nullable();
            $table->timestamps();

            $table->unique(['classe_id', 'matiere_id', 'annee_scolaire_id'], 'cme_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classe_matiere_enseignant');
    }
};
