<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->date('date_absence');
            $table->foreignId('matiere_id')->nullable()->constrained('matieres')->nullOnDelete();
            $table->string('motif', 255)->nullable();
            $table->boolean('justifiee')->default(false);
            $table->timestamps();

            $table->index(['eleve_id', 'date_absence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
