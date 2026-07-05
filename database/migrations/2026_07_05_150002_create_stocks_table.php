<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Niveau de stock par produit et par entrepôt, tenu à jour
        // transactionnellement à chaque mouvement (jamais recalculé à la volée).
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('entrepot_id')->constrained('entrepots')->cascadeOnDelete();
            $table->decimal('quantite', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'produit_id', 'entrepot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
