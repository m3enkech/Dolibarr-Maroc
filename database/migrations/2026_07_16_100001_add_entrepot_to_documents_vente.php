<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            // Entrepôt d'où sort la marchandise (caisse multi-points de vente).
            // Null = entrepôt par défaut du tenant (comportement historique).
            $table->foreignId('entrepot_id')->nullable()->after('pos_session_id')->constrained('entrepots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entrepot_id');
        });
    }
};
