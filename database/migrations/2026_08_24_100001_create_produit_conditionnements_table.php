<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Conditionnements d'un article : carton de 12, palette de 100…
         *
         * `quantite_base` exprime le colis en UNITÉ DE STOCK. Le stock, la
         * comptabilité et les tarifs continuent de raisonner en unité de base :
         * le conditionnement ne sert qu'à saisir et à afficher (« 5 cartons »).
         */
        Schema::create('produit_conditionnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->string('nom');
            $table->decimal('quantite_base', 12, 3);
            // Code-barres propre au colis : scanner un carton ajoute 12 pièces.
            $table->string('barcode')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'produit_id']);
            $table->index(['tenant_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_conditionnements');
    }
};
