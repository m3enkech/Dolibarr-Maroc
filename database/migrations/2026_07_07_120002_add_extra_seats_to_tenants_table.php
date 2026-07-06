<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Sièges additionnels achetés au-delà de l'inclus du plan.
            // Limite effective = sièges inclus (config/plans.php) + extra_seats.
            $table->unsignedInteger('extra_seats')->default(0)->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('extra_seats');
        });
    }
};
