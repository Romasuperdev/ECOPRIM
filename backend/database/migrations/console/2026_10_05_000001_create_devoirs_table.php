<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Devoir donné à une classe : consigne, date de remise, matière, enseignant —
        // rien de tout cela n'a de colonne dans ECONOMAT (le cahier de texte ne consigne
        // que ce qui a été vu en classe, pas un travail à rendre). Table propre à NEXORA,
        // même principe que `evaluations` : classe_code/matiere_code/enseignant_code
        // pointent vers ECONOMAT en lecture seule, sans contrainte inter-base.
        Schema::connection('ecoprim')->create('devoirs', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 150);
            $table->text('consigne')->nullable();
            $table->string('classe_code', 50);
            $table->string('matiere_code', 50);
            $table->unsignedBigInteger('enseignant_code')->nullable();
            $table->date('date_remise');
            $table->string('annee', 50);
            $table->timestamps();

            $table->index(['annee', 'classe_code']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('devoirs');
    }
};
