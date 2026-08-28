<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->date('date_retard');
            $table->time('heure_arrivee')->nullable();
            $table->unsignedInteger('duree_minutes')->nullable();
            $table->string('motif', 255)->nullable();
            $table->boolean('justifie')->default(false);
            $table->timestamps();

            $table->index(['eleve_id', 'date_retard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retards');
    }
};
