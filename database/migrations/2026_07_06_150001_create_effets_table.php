<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Effets / traites (LCN) : à recevoir (client) ou à payer (fournisseur).
        // Un effet transfère la créance/dette du compte collectif vers le compte
        // d'effets, jusqu'à son encaissement/paiement à l'échéance.
        Schema::create('effets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->foreignId('document_vente_id')->nullable()->constrained('documents_vente')->nullOnDelete();
            $table->foreignId('document_achat_id')->nullable()->constrained('documents_achat')->nullOnDelete();

            $table->string('type', 10); // recevoir | payer
            $table->string('code'); // EFR-2026-00001 | EFP-2026-00001
            $table->decimal('montant', 12, 2);
            $table->date('date_creation');
            $table->date('date_echeance');
            $table->string('statut', 12)->default('portefeuille'); // portefeuille | encaisse | paye | impaye
            $table->string('lettrage_code', 5)->nullable(); // lettre posée sur la facture (pour délettrer si impayé)
            $table->timestamp('regle_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'type', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effets');
    }
};
