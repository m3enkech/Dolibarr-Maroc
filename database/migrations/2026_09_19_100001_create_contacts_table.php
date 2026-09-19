<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les interlocuteurs d'un tiers.
 *
 * Jusqu'ici une société n'avait qu'UN nom de contact, dans une colonne texte.
 * Or on ne traite jamais avec une société, on traite avec des gens : le
 * directeur des achats passe la commande, la comptabilité règle la facture, le
 * magasin reçoit la marchandise. Trois personnes, trois numéros, et une seule
 * case pour les trois.
 *
 * `is_principal` n'est pas une décoration : c'est le contact que les documents
 * et les relances viseront par défaut. L'unicité n'est pas posée en base — une
 * contrainte partielle ne s'écrit pas pareil sur SQLite et sur PostgreSQL, et
 * une contrainte qui diverge entre développement et production est pire que
 * pas de contrainte. C'est le service qui la tient, et un test qui la prouve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiers_id')->constrained('tiers')->cascadeOnDelete();

            $table->string('nom');
            $table->string('fonction', 120)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_principal')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // La requête de la fiche 360 : tous les contacts d'un tiers, le
            // principal d'abord. Sur PostgreSQL une clé étrangère n'indexe
            // rien toute seule — le défaut déjà corrigé deux fois ici.
            $table->index(['tenant_id', 'tiers_id', 'is_principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
