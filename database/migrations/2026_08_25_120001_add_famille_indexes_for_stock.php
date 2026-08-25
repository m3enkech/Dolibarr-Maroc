<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La règle « le stock ne sort qu'une fois » interroge, à chaque validation de
 * document, la descendance d'une famille puis les mouvements déjà écrits par
 * ses membres. Ces deux colonnes ne portaient qu'une contrainte de clé
 * étrangère : MySQL crée l'index en douce, PostgreSQL — ce que fait tourner la
 * production — ne le fait PAS. Sans ces index, chaque facture validée coûtait
 * un balayage complet de la table qui grossit le plus vite de tout l'ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->index(['tenant_id', 'source_document_id'], 'documents_vente_famille_index');
        });

        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->index(['tenant_id', 'document_vente_id'], 'mouvements_stock_document_index');
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropIndex('documents_vente_famille_index');
        });

        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropIndex('mouvements_stock_document_index');
        });
    }
};
