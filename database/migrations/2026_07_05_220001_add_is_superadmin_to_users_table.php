<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Superadmin plateforme (opérateur SaaS) : au-dessus des comptes
            // entreprises, seul habilité aux opérations sensibles (réouverture
            // d'exercice…). Distinct du rôle « admin » qui reste au niveau tenant.
            $table->boolean('is_superadmin')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_superadmin');
        });
    }
};
