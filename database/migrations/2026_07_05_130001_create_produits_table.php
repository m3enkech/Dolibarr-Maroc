<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 10)->default('product'); // product | service

            // Prix hors taxe ; le TTC est calculé, jamais stocké.
            $table->decimal('sell_price', 12, 2)->default(0);
            $table->decimal('buy_price', 12, 2)->nullable();
            $table->decimal('tva_rate', 5, 2)->default(20); // taux marocains : 20, 14, 10, 7, 0

            $table->string('unit', 20)->nullable();
            $table->string('barcode', 30)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
