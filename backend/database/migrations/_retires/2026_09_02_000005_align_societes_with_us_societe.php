<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aligne `societes` sur les colonnes pertinentes de dbmasterbacou.US_SOCIETE (schéma
// indépendant, pas de connexion live — voir décision prise pour la Console Administrative).
// Colonnes volontairement exclues : CODEEXPERT, NUMAUTO, LIEU, CODIFICATION, NOMBASE
// (nom de base légataire d'une architecture multi-tenant par base, hors périmètre ECOPRIM),
// NB_ETAB/NB_APPART/NB_USER (compteurs dénormalisés, déjà dérivables via etablissements_count).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('societes', function (Blueprint $table) {
            $table->dropColumn('responsable');

            $table->string('adresse_ligne2', 150)->nullable()->after('adresse');
            $table->string('code_postal', 20)->nullable()->after('adresse_ligne2');
            $table->string('ville', 100)->nullable()->after('code_postal');
            $table->string('pays', 100)->nullable()->after('ville');
            $table->string('fax', 30)->nullable()->after('telephone');
            $table->string('site_web')->nullable()->after('email');
            $table->string('activite_principale', 150)->nullable()->after('site_web');
            $table->string('activite_secondaire', 150)->nullable()->after('activite_principale');
            $table->string('forme_juridique', 100)->nullable()->after('activite_secondaire');
            $table->string('regime_fiscal', 100)->nullable()->after('forme_juridique');
            $table->decimal('capital', 15, 2)->nullable()->after('regime_fiscal');
            $table->string('representant_civilite', 20)->nullable()->after('capital');
            $table->string('representant_nom', 150)->nullable()->after('representant_civilite');
            $table->string('representant_fonction', 100)->nullable()->after('representant_nom');
            $table->string('representant_telephone', 30)->nullable()->after('representant_fonction');
            $table->string('representant_mobile', 30)->nullable()->after('representant_telephone');
        });
    }

    public function down(): void
    {
        Schema::table('societes', function (Blueprint $table) {
            $table->dropColumn([
                'adresse_ligne2', 'code_postal', 'ville', 'pays', 'fax', 'site_web',
                'activite_principale', 'activite_secondaire', 'forme_juridique', 'regime_fiscal', 'capital',
                'representant_civilite', 'representant_nom', 'representant_fonction',
                'representant_telephone', 'representant_mobile',
            ]);
            $table->string('responsable')->nullable();
        });
    }
};
