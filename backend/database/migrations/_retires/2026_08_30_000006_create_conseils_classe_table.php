<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conseils_classe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periodes')->cascadeOnDelete();
            $table->date('date_conseil');
            $table->string('president', 150)->nullable();
            $table->string('secretaire', 150)->nullable();
            $table->text('observations_generales')->nullable();
            $table->timestamps();
        });

        Schema::create('deliberations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conseil_classe_id')->constrained('conseils_classe')->cascadeOnDelete();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->decimal('moyenne_generale', 4, 2)->nullable();
            $table->string('decision', 30)->nullable(); // passage, redoublement, orientation, avertissement
            $table->text('appreciation')->nullable();
            $table->string('mention', 50)->nullable();
            $table->timestamps();

            $table->unique(['conseil_classe_id', 'eleve_id'], 'deliberation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliberations');
        Schema::dropIfExists('conseils_classe');
    }
};
