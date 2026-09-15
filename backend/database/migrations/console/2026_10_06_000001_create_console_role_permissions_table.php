<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Permissions accordées à un rôle du catalogue console_roles (Enseignant, Secrétaire...).
        // Le catalogue des codes de permission vit dans App\Support\Permissions (fixe, porté
        // par le code) ; seule l'association rôle -> permissions est éditable, ici.
        // Un Super Admin, Admin Société ou Admin Établissement n'a pas besoin de ligne ici :
        // RhUser::aLaPermission() les laisse toujours passer (voir ce fichier).
        Schema::connection('ecoprim')->create('console_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('console_roles')->cascadeOnDelete();
            $table->string('permission_code', 50);
            $table->timestamps();

            $table->unique(['role_id', 'permission_code']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('console_role_permissions');
    }
};
