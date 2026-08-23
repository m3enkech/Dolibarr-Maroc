<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rattache un règlement à la SESSION DE CAISSE qui a encaissé l'argent.
     *
     * Sans cette colonne, le rapport Z remonte aux paiements par le document :
     * le règlement d'un crédit encaissé des jours plus tard serait attribué
     * rétroactivement à la session qui a émis le ticket, faussant deux Z à la
     * fois. Le backfill reproduit l'attribution actuelle pour que les clôtures
     * déjà archivées ne bougent pas.
     */
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignId('pos_session_id')->nullable()->after('document_vente_id')
                ->constrained('pos_sessions')->nullOnDelete();
            $table->index('pos_session_id');
        });

        DB::statement('
            UPDATE paiements
               SET pos_session_id = (
                   SELECT dv.pos_session_id FROM documents_vente dv WHERE dv.id = paiements.document_vente_id
               )
             WHERE EXISTS (
                   SELECT 1 FROM documents_vente dv
                    WHERE dv.id = paiements.document_vente_id AND dv.pos_session_id IS NOT NULL
               )
        ');
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropIndex(['pos_session_id']);
            $table->dropConstrainedForeignId('pos_session_id');
        });
    }
};
