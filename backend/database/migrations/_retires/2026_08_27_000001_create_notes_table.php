<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('periode_id')->nullable()->constrained('periodes')->nullOnDelete();
            $table->foreignId('enseignant_id')->nullable()->constrained('enseignants')->nullOnDelete();
            $table->decimal('valeur', 4, 2); // note sur 20
            $table->unsignedInteger('coefficient')->default(1);
            $table->string('type_evaluation', 30)->default('devoir'); // devoir, composition, interrogation
            $table->date('date_evaluation');
            $table->string('appreciation', 255)->nullable();
            $table->timestamps();

            $table->index(['eleve_id', 'matiere_id', 'periode_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
