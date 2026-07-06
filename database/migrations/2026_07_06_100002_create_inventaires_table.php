<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Session d'inventaire physique sur un entrepôt : on fige les quantités
        // théoriques, on saisit le comptage, puis la validation génère les
        // ajustements de stock (un mouvement d'ajustement par écart).
        Schema::create('inventaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entrepot_id')->constrained('entrepots')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('code');
            $table->string('statut', 12)->default('brouillon'); // brouillon | valide
            $table->string('note')->nullable();
            $table->timestamp('validated_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'entrepot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventaires');
    }
};
