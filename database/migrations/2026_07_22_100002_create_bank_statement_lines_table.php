<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->date('date_operation');
            $table->string('libelle');
            $table->string('reference')->nullable();
            // Montant signé « côté titulaire » : + = encaissement (entrée), − = décaissement (sortie).
            $table->decimal('montant', 15, 2);
            // Ligne d'écriture comptable pointée (rapprochée) — null tant que non rapprochée.
            $table->foreignId('ecriture_ligne_id')->nullable()->constrained('ecriture_lignes')->nullOnDelete();
            $table->timestamp('rapproche_at')->nullable();
            $table->timestamps();

            $table->index('bank_statement_id');
            $table->index('ecriture_ligne_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
