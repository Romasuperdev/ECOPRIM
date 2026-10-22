<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Aide : documentation interne, rédigée par le Super Admin et lue par tous les
        // comptes. Deux niveaux seulement — une catégorie, ses articles — parce qu'un
        // sommaire plus profond se parcourt mal et que personne ne l'entretient.
        //
        // `active` existe sur les deux tables : désactiver retire de la LECTURE, jamais de
        // la gestion (l'écran de la console liste tout, actif ou non, pour pouvoir
        // réactiver). C'est précisément ce qui manquait à l'appli dont l'idée est reprise.
        Schema::connection('ecoprim')->create('aide_categories', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 200);
            $table->string('icone', 50)->nullable(); // nom d'icône lucide (ex. « BookOpen »)
            $table->integer('ordre')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::connection('ecoprim')->create('aide_articles', function (Blueprint $table) {
            $table->id();
            // Supprimer une catégorie supprime ses articles : un article sans catégorie
            // n'aurait plus de place dans le sommaire, il serait introuvable.
            $table->foreignId('categorie_id')->constrained('aide_categories')->cascadeOnDelete();
            $table->string('titre', 200);
            // Texte brut : rendu tel quel côté lecteur, sauts de ligne conservés. Pas de
            // HTML — rien à assainir, rien à injecter.
            $table->text('contenu');
            // Rubrique de l'application concernée (libellé d'un groupe du menu), facultative.
            $table->string('module', 100)->nullable();
            $table->integer('ordre')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['categorie_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('aide_articles');
        Schema::connection('ecoprim')->dropIfExists('aide_categories');
    }
};
