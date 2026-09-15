<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Calendrier scolaire : congés/vacances, réunions parents-professeurs, sorties et
        // activités pédagogiques — quatre sortes d'événement qui partagent la même forme
        // (titre, période, lieu éventuel), regroupées dans une seule table plutôt que
        // quatre quasi identiques. Table propre à NEXORA, même principe que `evaluations` :
        // classe_code pointe vers ECONOMAT en lecture seule (nul = événement pour tout
        // l'établissement, ex. des vacances).
        Schema::connection('ecoprim')->create('evenements', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 150);
            $table->string('type', 30); // vacances | reunion | sortie | activite
            $table->text('description')->nullable();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('lieu', 150)->nullable();
            $table->string('classe_code', 50)->nullable();
            $table->string('annee', 50);
            $table->timestamps();

            $table->index(['annee', 'type']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('evenements');
    }
};
