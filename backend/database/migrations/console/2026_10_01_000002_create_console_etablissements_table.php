<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        Schema::connection('ecoprim')->create('console_etablissements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('intitule', 150);
            $table->string('type', 50)->nullable();
            $table->string('adresse', 200)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 50)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('site_web', 150)->nullable();
            // Rattachement obligatoire à une seule société (par son code).
            $table->string('societe_code', 30);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index('societe_code');
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('console_etablissements');
    }
};
