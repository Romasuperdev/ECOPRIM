<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Ce qu'une évaluation notée porte en plus de ses notes, et qu'ECONOMAT ne sait pas
        // ranger : sur ATECH, T_NOTEENTETE n'a ni intitulé (« Dictée n°3 »), ni barème, ni
        // statut de publication, et T_NOTEDETAILS pas de case « absent ». Les NOTES restent
        // dans ECONOMAT ; cette table s'y rattache par le code de l'entête.
        //
        // statut : brouillon (invisible des parents) | publiee. Une entête SANS ligne ici
        // (saisie avant, ou depuis ECONOMAT) est traitée comme publiée : rien de ce que les
        // parents voyaient ne disparaît.
        Schema::connection('ecoprim')->create('notes_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entete_code')->unique();
            $table->string('classe_code', 50);
            $table->string('matiere_code', 50);
            $table->string('session', 20)->nullable();
            $table->string('type', 50)->nullable();
            $table->date('date')->nullable();
            $table->string('titre', 150);
            $table->decimal('bareme', 6, 2)->default(20);
            $table->text('absents')->nullable(); // matricules (JSON)
            $table->string('statut', 20)->default('brouillon');
            $table->dateTime('publiee_le')->nullable();
            $table->unsignedBigInteger('rh_user_id')->nullable();
            $table->string('annee', 20)->nullable();
            $table->timestamps();

            $table->index(['classe_code', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('notes_evaluations');
    }
};
