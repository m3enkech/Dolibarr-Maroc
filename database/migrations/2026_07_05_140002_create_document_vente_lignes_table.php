<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_vente_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_vente_id')->constrained('documents_vente')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();

            $table->string('designation');
            $table->decimal('quantite', 10, 3);
            $table->decimal('prix_unitaire', 12, 2); // HT
            $table->decimal('remise_percent', 5, 2)->default(0);
            $table->decimal('tva_rate', 5, 2);

            // Montants figés au moment de la saisie (jamais recalculés a posteriori).
            $table->decimal('montant_ht', 12, 2);
            $table->decimal('montant_tva', 12, 2);
            $table->decimal('montant_ttc', 12, 2);

            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_vente_lignes');
    }
};
