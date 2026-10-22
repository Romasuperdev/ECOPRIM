<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Nouveautés : les notes de version présentées aux utilisateurs. Une version, ses
        // éléments (nouveauté, amélioration, correction), et qui l'a lue.
        Schema::connection('ecoprim')->create('nouveautes_versions', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 50);
            $table->string('titre', 200);
            $table->text('description')->nullable();
            $table->date('date_sortie');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'date_sortie']);
        });

        Schema::connection('ecoprim')->create('nouveautes_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('nouveautes_versions')->cascadeOnDelete();
            $table->string('titre', 200);
            $table->text('description')->nullable();
            $table->string('type', 20); // nouveaute | amelioration | correction
            $table->integer('ordre')->default(0);
            $table->timestamps();

            $table->index(['version_id', 'ordre']);
        });

        // Lectures : une ligne par compte et par version. `rh_user_id` désigne RH_USER.Id
        // (base maître, autre serveur logique) — pas de clé étrangère possible de ce côté.
        // Côté version, en revanche, la cascade est voulue : supprimer une version efface
        // ses lectures, au lieu de laisser des lignes orphelines.
        Schema::connection('ecoprim')->create('nouveautes_lues', function (Blueprint $table) {
            $table->id();
            $table->integer('rh_user_id');
            $table->foreignId('version_id')->constrained('nouveautes_versions')->cascadeOnDelete();
            $table->dateTime('lu_le');

            $table->unique(['rh_user_id', 'version_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('nouveautes_lues');
        Schema::connection('ecoprim')->dropIfExists('nouveautes_elements');
        Schema::connection('ecoprim')->dropIfExists('nouveautes_versions');
    }
};
