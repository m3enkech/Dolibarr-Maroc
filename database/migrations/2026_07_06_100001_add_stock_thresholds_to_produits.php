<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            // Seuil d'alerte de réapprovisionnement (null = pas de suivi) et
            // quantité cible visée lors du réappro (sert à suggérer la commande).
            $table->decimal('stock_min', 12, 3)->nullable()->after('unit');
            $table->decimal('stock_reappro', 12, 3)->nullable()->after('stock_min');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['stock_min', 'stock_reappro']);
        });
    }
};
