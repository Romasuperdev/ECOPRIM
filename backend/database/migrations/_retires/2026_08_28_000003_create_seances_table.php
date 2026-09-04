<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Cahier de textes" — journal des séances tenu par le titulaire de la classe
        Schema::create('seances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('enseignant_id')->nullable()->constrained('enseignants')->nullOnDelete();
            $table->date('date_seance');
            $table->text('contenu'); // ce qui a été enseigné
            $table->text('devoirs')->nullable(); // travail donné pour la fois suivante
            $table->timestamps();

            $table->index(['classe_id', 'date_seance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seances');
    }
};
