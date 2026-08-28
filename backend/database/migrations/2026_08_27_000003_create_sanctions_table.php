<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->date('date_sanction');
            $table->string('faute', 255);
            $table->string('sanction', 255);
            $table->string('observation', 500)->nullable();
            $table->timestamps();

            $table->index('eleve_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanctions');
    }
};
