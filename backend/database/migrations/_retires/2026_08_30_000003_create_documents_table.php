<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('documentable_type', 100);
            $table->unsignedBigInteger('documentable_id');
            $table->string('nom', 150);
            $table->string('chemin', 500);
            $table->string('type_mime', 100)->nullable();
            $table->unsignedBigInteger('taille')->nullable();
            $table->string('categorie', 50)->nullable();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id']);
        });

        Schema::create('documents_etablissement', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150);
            $table->string('chemin', 500);
            $table->string('type_mime', 100)->nullable();
            $table->unsignedBigInteger('taille')->nullable();
            $table->string('categorie', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_etablissement');
        Schema::dropIfExists('documents');
    }
};
