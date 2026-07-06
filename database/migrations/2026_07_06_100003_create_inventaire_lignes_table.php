<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventaire_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventaire_id')->constrained('inventaires')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();

            // Quantité théorique figée à l'ouverture de la session.
            $table->decimal('quantite_theorique', 12, 3)->default(0);
            // Quantité physiquement comptée (null = pas encore comptée).
            $table->decimal('quantite_comptee', 12, 3)->nullable();

            $table->timestamps();

            $table->unique(['inventaire_id', 'produit_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventaire_lignes');
    }
};
