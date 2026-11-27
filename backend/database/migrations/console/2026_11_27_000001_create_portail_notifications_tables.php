<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Notifications des espaces Enseignant et Parent : la cloche de l'application.
        // Une ligne par destinataire (compte RH_USER) : « lue » est propre à chacun.
        Schema::connection('ecoprim')->create('portail_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rh_user_id');
            // absence, note, devoir, annonce, message, preinscription… : sert à l'icône
            // et au filtrage, jamais à une décision d'accès.
            $table->string('type', 30);
            $table->string('titre', 150);
            $table->string('corps', 500)->nullable();
            // Chemin de l'application à ouvrir au toucher (« /mon-espace/enfants/P24-0061 »).
            $table->string('lien', 255)->nullable();
            $table->text('donnees')->nullable();
            $table->dateTime('lue_le')->nullable();
            $table->timestamps();

            $table->index(['rh_user_id', 'lue_le']);
            $table->index('created_at');
        });

        // Abonnements Web Push : un par appareil (téléphone, navigateur). Le point d'arrivée
        // est une URL longue : l'unicité porte sur son empreinte SHA-256, l'index d'une
        // colonne de 1000 octets étant refusé par SQL Server.
        Schema::connection('ecoprim')->create('portail_abonnements_push', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rh_user_id');
            $table->text('endpoint');
            $table->char('endpoint_empreinte', 64)->unique();
            $table->string('cle_p256dh', 200)->nullable();
            $table->string('cle_auth', 100)->nullable();
            $table->string('appareil', 255)->nullable();
            $table->dateTime('dernier_envoi_le')->nullable();
            $table->timestamps();

            $table->index('rh_user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('portail_abonnements_push');
        Schema::connection('ecoprim')->dropIfExists('portail_notifications');
    }
};
