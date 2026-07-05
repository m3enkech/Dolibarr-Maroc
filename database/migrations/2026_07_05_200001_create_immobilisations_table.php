<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immobilisations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20); // IM-2026-00001
            $table->string('label');
            $table->string('category', 30);
            $table->date('date_acquisition');
            $table->decimal('valeur_acquisition', 14, 2); // HT
            $table->unsignedSmallInteger('duree_annees');

            // Comptes CGNC : immobilisation (classe 2) et amortissement (28xx).
            $table->foreignId('compte_immo_id')->constrained('comptes');
            $table->foreignId('compte_amort_id')->constrained('comptes');

            $table->string('statut', 12)->default('en_service'); // en_service | cede
            $table->date('date_cession')->nullable();
            $table->decimal('valeur_cession', 14, 2)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immobilisations');
    }
};
