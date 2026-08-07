<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Compte de trésorerie (classe 5) rapproché — ex. 5141 Banque.
            $table->foreignId('compte_id')->constrained('comptes')->cascadeOnDelete();
            $table->string('libelle');
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->decimal('solde_initial', 15, 2)->default(0);
            $table->decimal('solde_final', 15, 2)->default(0);
            $table->string('statut')->default('en_cours'); // en_cours | cloture
            $table->timestamps();

            $table->index(['tenant_id', 'compte_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
