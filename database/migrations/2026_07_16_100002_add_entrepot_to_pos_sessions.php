<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            // Entrepôt rattaché à la caisse : les ventes de la session en sortent.
            $table->foreignId('entrepot_id')->nullable()->after('user_id')->constrained('entrepots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entrepot_id');
        });
    }
};
