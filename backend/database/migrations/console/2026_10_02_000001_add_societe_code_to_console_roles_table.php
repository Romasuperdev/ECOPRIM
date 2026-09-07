<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // NULL = rôle du catalogue général (Super Admin) ; sinon rôle propre à une
        // société, créé et géré par son Admin Société.
        Schema::connection('ecoprim')->table('console_roles', function (Blueprint $table) {
            $table->string('societe_code', 30)->nullable()->after('nom');
            $table->index('societe_code');
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->table('console_roles', function (Blueprint $table) {
            $table->dropIndex(['societe_code']);
            $table->dropColumn('societe_code');
        });
    }
};
