<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->string('numero_acte_naissance', 50)->nullable()->after('date_naissance');
            $table->string('lieu_naissance', 150)->nullable()->after('numero_acte_naissance');
        });
    }

    public function down(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->dropColumn(['numero_acte_naissance', 'lieu_naissance']);
        });
    }
};
