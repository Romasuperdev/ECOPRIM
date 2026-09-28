<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ecoprim';

    public function up(): void
    {
        // Barème et coefficient de chaque matière, par niveau ou par classe.
        //
        // POURQUOI UNE TABLE PROPRE À NEXORA. ECONOMAT porte déjà DEUX tables de
        // coefficients — T_CORMATNIVEAUCOEFF et T_COEFFICIENT — et ni l'une ni l'autre n'a
        // de colonne établissement ou année scolaire : elles ne savent donc pas porter une
        // grille différente d'une école à l'autre, ni d'une année à l'autre. Elles sont en
        // outre remplies avec les niveaux d'un autre établissement (BTS), et les colonnes
        // de T_CORMATNIVEAUCOEFF sont inversées (CodeMatiere contient le libellé). On n'en
        // crée pas une troisième chez ECONOMAT : la grille vit ici, comme `evaluations`.
        //
        // AUCUNE CLÉ ÉTRANGÈRE. niveau_code, classe_code et matiere_code renvoient vers
        // ECONOMAT (T_NIVEAU, T_CLASSE, T_MATIERE), sur une AUTRE connexion : une contrainte
        // inter-base est impossible. La cohérence est vérifiée à l'écriture, comme pour
        // `evaluations`.
        //
        // NULL VEUT DIRE « POUR TOUS ». etablissement_code null = grille par défaut, valable
        // partout ; annee null = valable toutes années. C'est ce qui permet à une école de
        // surcharger la grille commune sans la recopier.
        Schema::connection('ecoprim')->create('coefficients_matiere', function (Blueprint $table) {
            $table->id();
            $table->string('etablissement_code', 50)->nullable();
            $table->string('annee', 50)->nullable();
            // Une grille se définit par NIVEAU (tout le CP1) ou par CLASSE (la seule CP1 A).
            // Exactement l'un des deux est renseigné ; la classe l'emporte sur le niveau.
            $table->string('niveau_code', 50)->nullable();
            $table->string('classe_code', 50)->nullable();
            $table->string('matiere_code', 50);
            $table->decimal('coefficient', 6, 2);
            // Barème de la matière : « noté sur 50 ». Null = identique au coefficient, cas
            // du primaire ivoirien où la moyenne est la somme des points rapportée au total.
            $table->decimal('note_max', 6, 2)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(
                ['etablissement_code', 'annee', 'niveau_code', 'classe_code', 'matiere_code'],
                'coefficients_matiere_portee_unique',
            );
            $table->index(['matiere_code']);
        });
    }

    public function down(): void
    {
        Schema::connection('ecoprim')->dropIfExists('coefficients_matiere');
    }
};
