<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le calcul de réapprovisionnement lit, pour chaque produit affiché, ses
 * mouvements des quatre-vingt-dix derniers jours. L'index existant s'arrête à
 * (tenant_id, produit_id) : la borne de date se payait ensuite en filtrage,
 * sur la table qui grossit le plus vite de tout l'ERP. Ajouter `created_at`
 * transforme cela en simple parcours d'intervalle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->index(['tenant_id', 'produit_id', 'created_at'], 'mouvements_stock_fenetre_index');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropIndex('mouvements_stock_fenetre_index');
        });
    }
};
