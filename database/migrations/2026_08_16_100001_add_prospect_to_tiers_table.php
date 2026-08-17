<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            // Qualification commerciale : un prospect est un client potentiel,
            // pas encore converti. La source du lead alimente les stats CRM.
            $table->boolean('is_prospect')->default(false)->after('is_supplier');
            $table->string('lead_source', 30)->nullable()->after('is_prospect');
            $table->timestamp('converti_at')->nullable()->after('lead_source');

            $table->index(['tenant_id', 'is_prospect']);
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_prospect']);
            $table->dropColumn(['is_prospect', 'lead_source', 'converti_at']);
        });
    }
};
