<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historique des relances de recouvrement émises sur une facture impayée.
        Schema::create('relances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_vente_id')->constrained('documents_vente')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('niveau'); // 1 rappel | 2 relance ferme | 3 mise en demeure
            $table->string('canal', 12)->default('courrier'); // courrier | email | telephone | autre
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_vente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relances');
    }
};
