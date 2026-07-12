<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Compte suspendu par la plateforme (impayé, abus…) : ses
            // utilisateurs ne peuvent plus se connecter ni accéder à l'API.
            $table->timestamp('suspended_at')->nullable()->after('extra_seats');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
