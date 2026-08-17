<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            // Devis (puis facture par transformation) issu d'une opportunité :
            // permet de suivre l'entonnoir commercial et d'afficher les documents
            // liés sur la fiche de l'opportunité.
            $table->foreignId('opportunite_id')->nullable()->after('entrepot_id')
                ->constrained('opportunites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opportunite_id');
        });
    }
};
