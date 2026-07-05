<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->foreignId('categorie_produit_id')->nullable()->after('type')
                ->constrained('categories_produit')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categorie_produit_id');
        });
    }
};
