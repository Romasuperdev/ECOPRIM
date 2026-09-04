<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->foreign('niveau_id')->references('id')->on('niveaux')->nullOnDelete();
            $table->foreign('annee_scolaire_id')->references('id')->on('annees_scolaires')->nullOnDelete();
            $table->foreign('enseignant_principal_id')->references('id')->on('enseignants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['niveau_id']);
            $table->dropForeign(['annee_scolaire_id']);
            $table->dropForeign(['enseignant_principal_id']);
        });
    }
};
