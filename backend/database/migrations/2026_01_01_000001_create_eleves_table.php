<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eleves', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 30)->unique();
            $table->string('nom', 100);       // NVARCHAR côté SQL Server (accents/Unicode)
            $table->string('prenom', 100);
            $table->date('date_naissance');
            $table->string('sexe', 1); // M / F
            $table->string('photo')->nullable();
            $table->foreignId('classe_id')->nullable()->constrained('classes');
            $table->string('statut', 20)->default('actif'); // actif, transferé, radié
            $table->timestamps();
            $table->softDeletes();

            $table->index('classe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eleves');
    }
};
