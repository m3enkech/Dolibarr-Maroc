<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catégorie de produits portant les comptes comptables (GL) : les
        // produits en héritent. Repli sur les mappings globaux si aucune catégorie.
        Schema::create('categories_produit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            $table->foreignId('compte_vente_id')->nullable()->constrained('comptes')->nullOnDelete();
            $table->foreignId('compte_achat_id')->nullable()->constrained('comptes')->nullOnDelete();

            // Catégorie d'immobilisation : l'achat crée un bien amortissable.
            $table->boolean('is_immobilisation')->default(false);
            $table->foreignId('compte_amortissement_id')->nullable()->constrained('comptes')->nullOnDelete();
            $table->unsignedSmallInteger('duree_amortissement')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories_produit');
    }
};
