<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('libelle', 100);
            $table->unsignedInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::table('niveaux', function (Blueprint $table) {
            $table->foreignId('cycle_id')->nullable()->after('id')->constrained('cycles')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('niveaux', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cycle_id');
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('cycles');
    }
};
