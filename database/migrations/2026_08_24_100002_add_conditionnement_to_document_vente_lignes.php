<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_vente_lignes', function (Blueprint $table) {
            // Colis vendu et son nombre. `quantite` reste, elle, exprimée en
            // unité de stock : c'est ce qui garde stock, TVA et compta justes.
            $table->foreignId('conditionnement_id')->nullable()->after('produit_id')
                ->constrained('produit_conditionnements')->nullOnDelete();
            $table->decimal('quantite_colis', 12, 3)->nullable()->after('conditionnement_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_vente_lignes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conditionnement_id');
            $table->dropColumn('quantite_colis');
        });
    }
};
