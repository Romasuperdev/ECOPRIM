<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal d'activité : append-only (aucune mise à jour ni suppression applicative).
        Schema::create('journal_activite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50); // create, update, delete, activate, deactivate, reopen...
            $table->string('module', 50); // societes, etablissements, utilisateurs, roles, affectations, annees_scolaires...
            $table->string('objet_type', 100)->nullable();
            $table->unsignedBigInteger('objet_id')->nullable();
            $table->json('donnees_avant')->nullable();
            $table->json('donnees_apres')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_activite');
    }
};
