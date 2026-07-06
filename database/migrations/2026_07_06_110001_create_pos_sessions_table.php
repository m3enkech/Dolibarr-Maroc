<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Session de caisse (POS) : ouverte avec un fond de caisse, fermée avec
        // un comptage. L'écart = compté − théorique (fond + espèces encaissées).
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('code'); // CS-AAAA-NNNNN
            $table->string('statut', 10)->default('ouverte'); // ouverte | fermee
            $table->decimal('fond_caisse', 12, 2)->default(0);
            $table->decimal('montant_compte', 12, 2)->nullable();
            $table->decimal('ecart', 12, 2)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'statut']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
