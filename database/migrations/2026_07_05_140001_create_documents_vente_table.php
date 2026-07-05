<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_vente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // devis | commande | facture
            $table->string('code', 20);
            $table->string('statut', 15)->default('brouillon');
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->foreignId('source_document_id')->nullable()->constrained('documents_vente')->nullOnDelete();

            $table->date('date_document');
            $table->date('date_echeance')->nullable();

            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('validated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_vente');
    }
};
