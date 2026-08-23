<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Livraison partielle : une commande peut partir en plusieurs bons de
     * livraison. Miroir du mécanisme déjà en place côté Achats (réceptions
     * partielles) : la ligne de BL pointe la ligne de commande d'origine, et
     * cette dernière cumule ce qui a été livré.
     */
    public function up(): void
    {
        Schema::table('document_vente_lignes', function (Blueprint $table) {
            $table->foreignId('source_ligne_id')->nullable()->after('conditionnement_id')
                ->constrained('document_vente_lignes')->nullOnDelete();
            $table->decimal('quantite_livree', 12, 3)->default(0)->after('quantite');

            $table->index('source_ligne_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_vente_lignes', function (Blueprint $table) {
            $table->dropIndex(['source_ligne_id']);
            $table->dropConstrainedForeignId('source_ligne_id');
            $table->dropColumn('quantite_livree');
        });
    }
};
