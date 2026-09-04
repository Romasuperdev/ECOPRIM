<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aligne `etablissements` sur les colonnes pertinentes de dbmasterbacou.T_ETABLISSEMENT
// (schéma indépendant, pas de connexion live). Ajoute le bloc identité du responsable
// pédagogique (distinct du compte Admin Établissement) et le rattachement administratif
// ivoirien (DREN/IEP), utile pour les rapports officiels. Exclu : champs de liaison
// comptable (id_compta, compte_treso...) — prématuré tant qu'aucun module comptable n'existe.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->string('prenom_responsable', 150)->nullable()->after('nom_responsable');
            $table->string('fonction_responsable', 100)->nullable()->after('prenom_responsable');
            $table->string('contact_responsable', 30)->nullable()->after('fonction_responsable');
            $table->string('sous_prefecture', 100)->nullable()->after('email_directeur');
            $table->string('circonscription', 100)->nullable()->after('sous_prefecture');
            $table->string('code_iep', 30)->nullable()->after('circonscription');
            $table->string('intitule_iep', 150)->nullable()->after('code_iep');
            $table->string('code_dren', 30)->nullable()->after('intitule_iep');
            $table->string('intitule_dren', 150)->nullable()->after('code_dren');
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn([
                'prenom_responsable', 'fonction_responsable', 'contact_responsable',
                'sous_prefecture', 'circonscription', 'code_iep', 'intitule_iep', 'code_dren', 'intitule_dren',
            ]);
        });
    }
};
