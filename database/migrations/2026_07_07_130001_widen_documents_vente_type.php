<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Élargit documents_vente.type : la colonne avait été créée en varchar(10)
 * (« devis | commande | facture ») avant l'ajout du type « bon_livraison »
 * (13 caractères). SQLite ignore la longueur, mais PostgreSQL (prod) la fait
 * respecter → insertion en échec. On passe à 20 pour couvrir tous les types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->string('type', 10)->change();
        });
    }
};
