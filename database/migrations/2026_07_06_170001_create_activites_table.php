<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Activités CRM : interactions (appel, email, réunion, note) et tâches
        // planifiées (à faire), rattachées à un tiers et éventuellement à une
        // opportunité. Alimentent le « à faire » et la timeline client.
        Schema::create('activites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiers_id')->constrained('tiers')->cascadeOnDelete();
            $table->foreignId('opportunite_id')->nullable()->constrained('opportunites')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 12); // appel | email | reunion | note | tache
            $table->string('sujet');
            $table->string('note', 1000)->nullable();
            $table->date('date_prevue')->nullable(); // pour les tâches/rappels
            $table->boolean('fait')->default(false);
            $table->timestamp('fait_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'fait', 'date_prevue']);
            $table->index(['tenant_id', 'tiers_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activites');
    }
};
