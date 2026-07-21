<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            // Clé d'idempotence générée par le client (caisse hors-ligne) : rejouer
            // une vente déjà enregistrée renvoie la même facture, sans doublon.
            $table->uuid('client_uuid')->nullable()->after('pos_session_id');
            $table->index(['tenant_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
