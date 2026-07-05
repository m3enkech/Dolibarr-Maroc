<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_achat_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_achat_id')->constrained('documents_achat')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            // Ligne de commande dont provient une ligne de réception :
            // c'est ce lien qui permet les réceptions partielles.
            $table->foreignId('source_ligne_id')->nullable()->constrained('document_achat_lignes')->nullOnDelete();

            $table->string('designation');
            $table->decimal('quantite', 10, 3);
            // Cumul reçu — renseigné uniquement sur les lignes de commande.
            $table->decimal('quantite_recue', 10, 3)->default(0);
            $table->decimal('prix_unitaire', 12, 2); // HT (prix d'achat)
            $table->decimal('remise_percent', 5, 2)->default(0);
            $table->decimal('tva_rate', 5, 2);

            $table->decimal('montant_ht', 12, 2);
            $table->decimal('montant_tva', 12, 2);
            $table->decimal('montant_ttc', 12, 2);

            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_achat_lignes');
    }
};
