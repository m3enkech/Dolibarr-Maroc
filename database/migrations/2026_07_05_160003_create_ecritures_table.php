<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecritures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('journal', 4); // VT (ventes) | BQ (trésorerie) | OD (divers)
            $table->string('numero', 20); // VT-2026-00001
            $table->date('date_ecriture');
            $table->string('libelle');
            $table->string('reference')->nullable(); // FA-2026-00001…
            $table->foreignId('document_vente_id')->nullable()->constrained('documents_vente')->nullOnDelete();
            $table->boolean('is_auto')->default(false); // générée par un événement métier
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'journal', 'date_ecriture']);
        });

        Schema::create('ecriture_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecriture_id')->constrained('ecritures')->cascadeOnDelete();
            $table->foreignId('compte_id')->constrained('comptes');
            $table->string('libelle')->nullable();
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->timestamps();

            $table->index('compte_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecriture_lignes');
        Schema::dropIfExists('ecritures');
    }
};
