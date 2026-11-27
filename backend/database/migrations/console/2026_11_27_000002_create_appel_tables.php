<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Un appel = une classe, un jour, un créneau (matin / après-midi). Les ABSENCES qu'il
        // constate sont écrites dans ECONOMAT (T_ABSENCEELEVE), qui reste la référence ; ces
        // tables gardent ce qu'ECONOMAT ne sait pas porter : qui a fait l'appel et quand,
        // les RETARDS (sans table dans ECONOMAT), et quelle absence vient de quel appel —
        // de quoi corriger un appel sans toucher une absence saisie par le secrétariat.
        Schema::connection('ecoprim')->create('appels', function (Blueprint $table) {
            $table->id();
            $table->string('classe_code', 50);
            $table->date('date');
            $table->string('creneau', 20);
            $table->string('annee', 20)->nullable();
            $table->string('etablissement_code', 30)->nullable();
            $table->unsignedBigInteger('rh_user_id')->nullable();
            $table->string('auteur', 150)->nullable();
            $table->unsignedSmallInteger('effectif')->default(0);
            $table->unsignedSmallInteger('absents')->default(0);
            $table->unsignedSmallInteger('retards')->default(0);
            // Identifiant d'envoi fourni par le téléphone : un appel fait hors connexion et
            // renvoyé deux fois au retour du réseau n'est traité qu'une fois.
            $table->string('dernier_envoi', 64)->nullable();
            $table->dateTime('valide_le')->nullable();
            $table->timestamps();

            $table->unique(['classe_code', 'date', 'creneau']);
            $table->index('dernier_envoi');
        });

        Schema::connection('ecoprim')->create('appel_lignes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appel_id');
            $table->string('matricule', 50);
            $table->string('statut', 10); // absent | retard — un présent n'a pas de ligne
            // L'absence ECONOMAT correspondante, et si c'est CET appel qui l'a créée (sinon
            // elle existait déjà, saisie ailleurs : on ne la supprime jamais).
            $table->unsignedBigInteger('absence_code')->nullable();
            $table->boolean('absence_creee')->default(false);
            $table->dateTime('notifie_le')->nullable();
            $table->timestamps();

            $table->unique(['appel_id', 'matricule']);
            $table->index('matricule');
        });

        // Justificatifs d'absence envoyés par les parents : un mot, une photo (certificat
        // médical) ou les deux. Décision par l'enseignant de la classe ou l'administration ;
        // elle est reportée dans T_ABSENCEELEVE.Justifier.
        Schema::connection('ecoprim')->create('absence_justificatifs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('absence_code');
            $table->string('matricule', 50);
            $table->string('classe_code', 50)->nullable();
            $table->date('date_absence')->nullable();
            $table->unsignedBigInteger('rh_user_id');
            $table->string('message', 1000)->nullable();
            $table->string('fichier', 255)->nullable();
            $table->string('fichier_nom', 255)->nullable();
            $table->string('fichier_type', 100)->nullable();
            $table->string('statut', 20)->default('en_attente'); // en_attente | justifiee | non_justifiee | annule
            $table->unsignedBigInteger('decision_par')->nullable();
            $table->string('decision_par_nom', 150)->nullable();
            $table->dateTime('decision_le')->nullable();
            $table->string('commentaire', 500)->nullable();
            $table->timestamps();

            $table->index('absence_code');
            $table->index(['classe_code', 'statut']);
            $table->index('matricule');
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('absence_justificatifs');
        Schema::connection('ecoprim')->dropIfExists('appel_lignes');
        Schema::connection('ecoprim')->dropIfExists('appels');
    }
};
