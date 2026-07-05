<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comptes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 8); // ex. 3411, 7111
            $table->string('label');
            $table->unsignedTinyInteger('classe'); // 1..7 (CGNC)
            $table->boolean('is_system')->default(false); // seedé PCGM, non supprimable
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'classe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes');
    }
};
