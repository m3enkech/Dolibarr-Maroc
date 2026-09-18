<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La référence que le CLIENT a donnée à sa propre commande.
 *
 * « N° de bon de commande » sur la facture : c'est la clé dont le service
 * comptable du client a besoin pour rapprocher la facture de son engagement, et
 * sans laquelle beaucoup de grands comptes la renvoient sans la payer.
 *
 * Distincte de `source_document_id`, qui relie la facture à NOTRE commande, et
 * de `source_id`, qui dit d'où l'enregistrement a été repris. Ici il s'agit
 * d'une référence étrangère, saisie ou reprise, qui ne pointe sur rien chez nous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->string('reference_client', 60)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropColumn('reference_client');
        });
    }
};
