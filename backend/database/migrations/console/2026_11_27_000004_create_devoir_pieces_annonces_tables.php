<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Pièces jointes d'un devoir : la photo du tableau ou d'une fiche d'exercices, prise
        // avec le téléphone. Les fichiers vivent hors du dossier public
        // (storage/app/devoirs) et ne se lisent que par l'API, après contrôle d'accès.
        Schema::connection('ecoprim')->create('devoir_pieces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('devoir_id');
            $table->string('fichier', 255);
            $table->string('fichier_nom', 255)->nullable();
            $table->string('fichier_type', 100)->nullable();
            $table->unsignedInteger('taille')->nullable();
            $table->timestamps();

            $table->index('devoir_id');
        });

        // Annonces d'un enseignant aux parents d'UNE classe (« Sortie demain : prévoir une
        // casquette et une gourde »), avec une photo facultative. Chaque envoi prévient les
        // parents rattachés aux élèves de la classe.
        Schema::connection('ecoprim')->create('annonces', function (Blueprint $table) {
            $table->id();
            $table->string('classe_code', 50);
            $table->string('titre', 150);
            $table->text('message')->nullable();
            $table->string('fichier', 255)->nullable();
            $table->string('fichier_nom', 255)->nullable();
            $table->string('fichier_type', 100)->nullable();
            $table->unsignedBigInteger('rh_user_id')->nullable();
            $table->string('auteur', 150)->nullable();
            $table->unsignedInteger('parents_prevenus')->default(0);
            $table->string('annee', 20)->nullable();
            $table->timestamps();

            $table->index(['classe_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('annonces');
        Schema::connection('ecoprim')->dropIfExists('devoir_pieces');
    }
};
