<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comptes des acheteurs du portail (les épiciers qui commandent chez un
     * grossiste).
     *
     * VOLONTAIREMENT SANS `tenant_id` et sans le trait BelongsToTenant :
     * un acheteur n'appartient à AUCUNE entreprise. C'est ce qui lui permettra,
     * en phase 2, de commander chez plusieurs grossistes avec un seul compte —
     * et ce qui garantit qu'il ne consomme aucun siège facturable.
     */
    public function up(): void
    {
        Schema::create('acheteurs', function (Blueprint $table) {
            $table->id();
            // Identité de connexion : unique à l'échelle de la plateforme.
            $table->string('email')->unique();
            $table->string('name');
            $table->string('password');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('derniere_connexion_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acheteurs');
    }
};
