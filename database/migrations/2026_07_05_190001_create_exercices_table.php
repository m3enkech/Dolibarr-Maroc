<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exercices comptables clôturés (année civile, norme marocaine).
        Schema::create('exercices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->decimal('resultat', 14, 2)->default(0);
            $table->foreignId('ecriture_resultat_id')->nullable()->constrained('ecritures')->nullOnDelete();
            $table->foreignId('ecriture_an_id')->nullable()->constrained('ecritures')->nullOnDelete();
            $table->timestamp('cloture_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'annee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercices');
    }
};
