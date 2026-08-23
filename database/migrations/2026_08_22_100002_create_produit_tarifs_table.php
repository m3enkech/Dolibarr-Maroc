<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Prix d'un article selon l'acheteur et la quantité.
         *
         * Un tarif vise SOIT un client précis (tiers_id), SOIT une catégorie
         * tarifaire (categorie_tarifaire_id) ; `quantite_min` ouvre le palier.
         * Le prix retenu est le plus spécifique applicable — voir TarifService.
         */
        Schema::create('produit_tarifs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('categorie_tarifaire_id')->nullable()
                ->constrained('categories_tarifaires')->cascadeOnDelete();
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->cascadeOnDelete();
            $table->decimal('quantite_min', 12, 3)->default(1);
            $table->decimal('prix', 12, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'produit_id']);
            $table->index(['produit_id', 'tiers_id', 'quantite_min']);
            $table->index(['produit_id', 'categorie_tarifaire_id', 'quantite_min']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_tarifs');
    }
};
