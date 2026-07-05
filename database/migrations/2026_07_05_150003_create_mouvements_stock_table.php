<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('entrepot_id')->constrained('entrepots')->cascadeOnDelete();
            $table->foreignId('document_vente_id')->nullable()->constrained('documents_vente')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 12); // entree | sortie | ajustement | vente
            $table->decimal('quantite', 12, 3); // delta signé
            $table->decimal('quantite_apres', 12, 3); // stock de l'entrepôt après le mouvement
            $table->string('reference')->nullable();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'produit_id']);
            $table->index(['tenant_id', 'entrepot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
