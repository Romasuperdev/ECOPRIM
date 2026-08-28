<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eleve_parent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();
            $table->string('lien_parente', 20); // pere, mere, tuteur
            $table->timestamps();

            $table->unique(['eleve_id', 'parent_id'], 'eleve_parent_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eleve_parent');
    }
};
