<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $table) {
            // Tiers de la ligne (comptes 3411/4411) — permet le lettrage par client/fournisseur.
            $table->foreignId('tiers_id')->nullable()->after('compte_id')->constrained('tiers')->nullOnDelete();
            // Code de lettrage (AAA, AAB…) : un groupe équilibré de lignes du même compte.
            $table->string('lettrage', 5)->nullable()->after('credit');

            $table->index(['compte_id', 'lettrage']);
        });
    }

    public function down(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $table) {
            $table->dropIndex(['compte_id', 'lettrage']);
            $table->dropConstrainedForeignId('tiers_id');
            $table->dropColumn('lettrage');
        });
    }
};
