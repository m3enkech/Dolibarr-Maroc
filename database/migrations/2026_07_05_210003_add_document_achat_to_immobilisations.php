<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('immobilisations', function (Blueprint $table) {
            // Facture d'achat d'origine (immobilisation créée automatiquement).
            $table->foreignId('document_achat_id')->nullable()->after('code')
                ->constrained('documents_achat')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('immobilisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_achat_id');
        });
    }
};
