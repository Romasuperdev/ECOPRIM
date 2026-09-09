<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Planification d'une évaluation (devoir, composition, interrogation...) : un
        // titre, un coefficient, une note maximale, un créneau — rien de tout cela n'a
        // de colonne dans ECONOMAT (T_NOTEENTETE ne porte ni titre, ni heure, ni note
        // maximale, ni enseignant). Table propre à NEXORA, comme les tables console_* :
        // aucune écriture dans une table partagée.
        //
        // classe_code / matiere_code / enseignant_code renvoient vers ECONOMAT
        // (T_CLASSE.CodeClasse, T_MATIERE.CodeMatiere, T_PROFESSEUR.Code) en LECTURE
        // seule — aucune contrainte de clé étrangère inter-base, la cohérence est
        // vérifiée côté application à la création.
        Schema::connection('ecoprim')->create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 150);
            $table->string('classe_code', 50);
            $table->string('matiere_code', 50);
            $table->unsignedBigInteger('enseignant_code')->nullable();
            $table->string('type', 50);
            $table->date('date');
            $table->string('heure_debut', 10)->nullable();
            $table->string('heure_fin', 10)->nullable();
            $table->decimal('coefficient', 5, 2)->default(1);
            $table->decimal('note_maximale', 5, 2)->default(20);
            $table->string('annee', 50);
            $table->timestamps();

            $table->index(['annee', 'classe_code']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('evaluations');
    }
};
