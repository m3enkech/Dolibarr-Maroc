<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            // Une facture émise depuis la caisse est rattachée à sa session POS.
            $table->foreignId('pos_session_id')->nullable()->after('source_document_id')
                ->constrained('pos_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_session_id');
        });
    }
};
