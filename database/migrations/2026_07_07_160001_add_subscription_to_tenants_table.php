<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Cycle de facturation et cycle de vie de l'abonnement.
            $table->string('billing_cycle')->default('mensuel')->after('extra_seats'); // mensuel | annuel
            $table->string('subscription_status')->default('essai')->after('billing_cycle'); // essai | actif | annule
            $table->timestamp('trial_ends_at')->nullable()->after('subscription_status');
            $table->date('current_period_end')->nullable()->after('trial_ends_at'); // payé jusqu'au
            $table->timestamp('subscription_started_at')->nullable()->after('current_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'billing_cycle', 'subscription_status', 'trial_ends_at',
                'current_period_end', 'subscription_started_at',
            ]);
        });
    }
};
