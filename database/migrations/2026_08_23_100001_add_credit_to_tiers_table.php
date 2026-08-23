<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            // Encours autorisé : null = pas de plafond (crédit libre).
            $table->decimal('plafond_credit', 15, 2)->nullable()->after('categorie_tarifaire_id');
            // Délai accordé, qui fixe la date d'échéance des ventes à crédit.
            $table->unsignedSmallInteger('delai_paiement_jours')->nullable()->after('plafond_credit');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropColumn(['plafond_credit', 'delai_paiement_jours']);
        });
    }
};
