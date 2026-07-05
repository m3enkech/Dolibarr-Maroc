<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_vente_id')->constrained('documents_vente')->cascadeOnDelete();

            $table->date('date_paiement');
            $table->decimal('montant', 12, 2);
            $table->string('mode', 20); // especes | cheque | virement | carte | autre
            $table->string('reference')->nullable();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'document_vente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
