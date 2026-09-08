<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Quel(s) élève(s) un compte « Parent » (console_affectations, rôle parent) peut
        // consulter. Une affectation peut porter plusieurs enfants (fratrie) : table à
        // part plutôt qu'une colonne sur console_affectations, dont la contrainte
        // d'unicité (utilisateur + établissement + rôle) resterait vraie pour un parent
        // de plusieurs enfants dans le même établissement.
        Schema::connection('ecoprim')->create('console_affectation_eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('console_affectations')->cascadeOnDelete();
            $table->string('eleve_matricule', 50); // ECONOMAT.T_ETUDIANT.Matricule (lecture seule)
            $table->timestamps();

            $table->unique(['affectation_id', 'eleve_matricule']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('console_affectation_eleves');
    }
};
