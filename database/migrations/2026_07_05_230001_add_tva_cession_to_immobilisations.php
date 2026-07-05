<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('immobilisations', function (Blueprint $table) {
            // TVA collectée sur le prix de cession (valeur_cession = HT).
            $table->decimal('tva_cession', 14, 2)->nullable()->after('valeur_cession');
        });
    }

    public function down(): void
    {
        Schema::table('immobilisations', function (Blueprint $table) {
            $table->dropColumn('tva_cession');
        });
    }
};
