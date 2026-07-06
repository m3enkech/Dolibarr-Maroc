<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opportunités commerciales (pipeline CRM) : une affaire potentielle
        // rattachée à un tiers, qui progresse d'étape en étape jusqu'à être
        // gagnée (→ devis/facture) ou perdue.
        Schema::create('opportunites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiers_id')->constrained('tiers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('code'); // OPP-2026-00001
            $table->string('titre');
            $table->decimal('montant_estime', 12, 2)->default(0);
            $table->unsignedTinyInteger('probabilite')->default(50); // 0-100 %
            $table->string('etape', 20)->default('nouveau'); // nouveau|qualifie|proposition|negociation
            $table->string('statut', 10)->default('ouverte'); // ouverte|gagnee|perdue
            $table->unsignedInteger('position')->default(0); // ordre dans la colonne
            $table->date('date_cloture_prevue')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamp('close_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'statut', 'etape']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunites');
    }
};
