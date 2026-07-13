<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            // Facture d'abonnement générée dans la compta de l'opérateur (plateforme).
            $table->foreignId('operator_tenant_id')->nullable()->after('tenant_id')->constrained('tenants')->nullOnDelete();
            $table->foreignId('document_vente_id')->nullable()->after('operator_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operator_tenant_id');
            $table->dropColumn('document_vente_id');
        });
    }
};
