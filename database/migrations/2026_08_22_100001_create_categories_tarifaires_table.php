<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Niveaux de tarif d'un grossiste : gros, demi-gros, détail…
        Schema::create('categories_tarifaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            // Catégorie appliquée aux clients qui n'en ont pas de propre.
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_default']);
        });

        Schema::table('tiers', function (Blueprint $table) {
            $table->foreignId('categorie_tarifaire_id')->nullable()->after('lead_source')
                ->constrained('categories_tarifaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categorie_tarifaire_id');
        });

        Schema::dropIfExists('categories_tarifaires');
    }
};
