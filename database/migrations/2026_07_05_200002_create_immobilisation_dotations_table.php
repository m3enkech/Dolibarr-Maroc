<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dotation d'amortissement réellement comptabilisée (≠ plan théorique) :
        // sert de base au cumul et donc à la VNA lors d'une cession.
        Schema::create('immobilisation_dotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('immobilisation_id')->constrained('immobilisations')->cascadeOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->decimal('montant', 14, 2);
            $table->foreignId('ecriture_id')->nullable()->constrained('ecritures')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'immobilisation_id', 'annee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immobilisation_dotations');
    }
};
