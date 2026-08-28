<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expediteur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('destinataire_id')->constrained('users')->noActionOnDelete();
            $table->string('sujet', 150);
            $table->text('contenu');
            $table->timestamp('lu_at')->nullable();
            $table->timestamps();

            $table->index(['destinataire_id', 'lu_at']);
        });

        Schema::create('annonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auteur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('classe_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('titre', 150);
            $table->text('contenu');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annonces');
        Schema::dropIfExists('messages');
    }
};
