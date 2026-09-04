<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Même défaut qu'en août sur les mouvements de stock, à un autre endroit : les
 * deux tables de LIGNES ne portaient sur ces colonnes qu'une contrainte de clé
 * étrangère. MySQL crée l'index en douce, PostgreSQL — ce que fait tourner la
 * production — ne le fait PAS.
 *
 * Ce que ça coûte aujourd'hui, sans rien attendre du nouvel écran :
 * `enCommandeSubquery()` est une sous-requête CORRÉLÉE, évaluée une fois PAR
 * LIGNE de produit affichée. Sur `/stock/niveaux` et `/stock/alertes`, chaque
 * page de catalogue déclenche donc autant de balayages complets de
 * `document_achat_lignes` qu'elle affiche d'articles.
 *
 * L'écran de suivi ajoute deux usages qui appuient exactement au même endroit :
 * la ventilation par dépôt (reste à recevoir d'un article) et le compteur des
 * commandes clients à reliquat, qui pose un EXISTS sur les lignes de vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_achat_lignes', function (Blueprint $table) {
            // Le couple sert la sous-requête « reste à recevoir » : on filtre sur
            // le produit, puis on rejoint le document parent.
            $table->index(['produit_id', 'document_achat_id'], 'dal_produit_document_index');
            $table->index('document_achat_id', 'dal_document_index');
        });

        Schema::table('document_vente_lignes', function (Blueprint $table) {
            $table->index('document_vente_id', 'dvl_document_index');
            $table->index('produit_id', 'dvl_produit_index');
        });
    }

    public function down(): void
    {
        Schema::table('document_achat_lignes', function (Blueprint $table) {
            $table->dropIndex('dal_produit_document_index');
            $table->dropIndex('dal_document_index');
        });

        Schema::table('document_vente_lignes', function (Blueprint $table) {
            $table->dropIndex('dvl_document_index');
            $table->dropIndex('dvl_produit_index');
        });
    }
};
