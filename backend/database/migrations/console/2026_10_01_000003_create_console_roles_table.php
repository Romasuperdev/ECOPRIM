<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        Schema::connection('ecoprim')->create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('nom', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('roles');
    }
};
