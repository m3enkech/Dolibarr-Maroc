<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rattachement d'un acheteur à un grossiste.
     *
     * Table TRAVERSANTE par nature : elle relie un compte hors entreprise à une
     * entreprise donnée. Elle ne porte donc PAS le trait BelongsToTenant et doit
     * toujours être interrogée avec un `where` explicite sur tenant_id.
     *
     * Créée dès maintenant avec sa clé composite : un acheteur pourra être
     * rattaché à plusieurs grossistes en phase 2 sans nouvelle migration.
     */
    public function up(): void
    {
        Schema::create('acheteur_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acheteur_id')->constrained('acheteurs')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Le compte client correspondant CHEZ CE GROSSISTE. Null tant que la
            // demande n'est pas approuvée : c'est l'approbation qui le rattache.
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete();

            $table->string('statut', 20)->default('en_attente'); // en_attente|approuve|refuse|revoque
            $table->timestamp('demande_at')->nullable();
            $table->timestamp('approuve_at')->nullable();
            $table->foreignId('approuve_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoque_at')->nullable();
            $table->timestamps();

            // Un acheteur ne demande qu'une fois l'accès à un grossiste donné.
            $table->unique(['acheteur_id', 'tenant_id']);
            $table->index(['tenant_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acheteur_tiers');
    }
};
