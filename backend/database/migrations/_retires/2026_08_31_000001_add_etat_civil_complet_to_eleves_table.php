<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            // Identité / état civil (T_ETUDIANT : Nationalite, PaysNaiss, ActDu, Ethnie omise volontairement)
            $table->string('nationalite', 100)->nullable()->after('lieu_naissance');
            $table->string('pays_naissance', 100)->nullable()->after('nationalite');
            $table->string('acte_delivre_par', 150)->nullable()->after('numero_acte_naissance');

            // Coordonnées (T_ETUDIANT : Adresse, Email, Telephone, Ville, Commune, Quartier, Region)
            $table->string('adresse', 255)->nullable()->after('lieu_naissance');
            $table->string('ville', 100)->nullable()->after('adresse');
            $table->string('commune', 100)->nullable()->after('ville');
            $table->string('quartier', 100)->nullable()->after('commune');
            $table->string('region', 100)->nullable()->after('quartier');
            $table->string('email', 150)->nullable()->after('region');
            $table->string('telephone', 30)->nullable()->after('email');

            // Scolarité (T_ETUDIANT : Redoublant, TypeEleve, LV2)
            $table->boolean('redoublant')->default(false)->after('statut');
            $table->string('type_eleve', 50)->nullable()->after('redoublant');
            $table->string('lv2', 50)->nullable()->after('type_eleve');

            // Situation familiale (T_ETUDIANT : SituationFamiliale)
            $table->string('situation_familiale', 50)->nullable()->after('lv2');
        });
    }

    public function down(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->dropColumn([
                'nationalite', 'pays_naissance', 'acte_delivre_par',
                'adresse', 'ville', 'commune', 'quartier', 'region', 'email', 'telephone',
                'redoublant', 'type_eleve', 'lv2', 'situation_familiale',
            ]);
        });
    }
};
