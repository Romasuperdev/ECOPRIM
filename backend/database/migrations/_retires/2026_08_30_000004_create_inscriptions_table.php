<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historique de scolarité : inscription, réinscription, transfert entrant/sortant
        Schema::create('inscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('classe_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('type', 30); // inscription, reinscription, transfert_entrant, transfert_sortant
            $table->date('date_mouvement');
            $table->string('etablissement_origine', 150)->nullable();
            $table->string('etablissement_destination', 150)->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['eleve_id', 'annee_scolaire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscriptions');
    }
};
