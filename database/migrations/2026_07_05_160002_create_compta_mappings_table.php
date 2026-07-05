<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // « Comptes par défaut » : l'entreprise manipule des concepts simples
        // (ventes, caisse, banque…), le mapping résout vers le compte PCGM.
        Schema::create('compta_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('cle', 30); // clients, ventes_services, tva_facturee…
            $table->foreignId('compte_id')->constrained('comptes');
            $table->timestamps();

            $table->unique(['tenant_id', 'cle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compta_mappings');
    }
};
