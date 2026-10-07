<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fiche d'un tiers interroge ses pièces de vente à chaque ouverture : la
 * synthèse (comptes par type, chiffre d'affaires), la vue d'ensemble (une
 * facture émise existe-t-elle ? revenus par mois de la période) et l'onglet
 * Transactions. Toutes filtrent sur (entreprise, tiers, type, statut, date).
 *
 * `tiers_id` ne portait qu'une clé étrangère, et PostgreSQL — la production —
 * ne crée PAS d'index derrière une clé étrangère : le seul index utilisable,
 * (tenant_id, type, statut), relisait toutes les factures de l'entreprise pour
 * en garder celles d'un client. Mille trois cents factures à chaque clic dans
 * la liste, et autant de plus chaque année.
 *
 * Ordre des colonnes : les égalités d'abord (entreprise, tiers), puis les
 * listes courtes (type, statut), la plage de dates en dernier — c'est elle
 * qu'un parcours d'index lit en ordre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'tiers_id', 'type', 'statut', 'date_document'],
                'documents_vente_tiers_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropIndex('documents_vente_tiers_index');
        });
    }
};
