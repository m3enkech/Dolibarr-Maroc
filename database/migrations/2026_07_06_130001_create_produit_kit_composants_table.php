<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composition d'un kit : le kit (produit type 'kit') est vendu comme un
        // article unique mais consomme le stock de ses composants.
        Schema::create('produit_kit_composants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('composant_id')->constrained('produits')->cascadeOnDelete();
            $table->decimal('quantite', 12, 3);
            $table->timestamps();

            $table->unique(['kit_id', 'composant_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_kit_composants');
    }
};
