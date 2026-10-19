<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des messages préparés pour un client : relance d'impayé, envoi de
 * facture ou de devis, confirmation de paiement.
 *
 * CE QUE LA LIGNE PROUVE, ET CE QU'ELLE NE PROUVE PAS. Avec le lien WhatsApp
 * « click-to-chat », l'application ouvre WhatsApp avec le message prêt ; c'est
 * l'utilisateur qui appuie sur Envoyer, depuis son propre téléphone. Le
 * serveur ne voit jamais l'envoi. Une ligne dit donc « WhatsApp a été ouvert
 * avec CE texte, vers CE numéro, par CETTE personne » — pas « le client l'a
 * reçu ». Le jour où l'API WhatsApp Business enverra elle-même, le même
 * journal accueillera un autre `canal` et pourra porter un accusé de remise.
 *
 * `message` est le texte FINAL, retouches de l'utilisateur comprises : c'est
 * ce qui a été dit au client qui compte en cas de litige, pas le modèle.
 *
 * `auteur_nom` est une COPIE, prise à l'écriture, pour la même raison que sur
 * les commentaires des tiers : retirer un membre de l'équipe supprime sa ligne
 * `users`, et le journal ne doit pas devenir anonyme pour autant.
 *
 * `tiers_id` est recopié de la pièce : c'est le client à qui l'on a écrit, et
 * l'historique d'un client se lira un jour sans repasser par ses pièces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('canal', 20);  // whatsapp (demain : whatsapp_api, email, sms…)
            $table->string('type', 20);   // relance | facture | devis | paiement

            $table->foreignId('document_vente_id')->constrained('documents_vente')->cascadeOnDelete();
            $table->foreignId('paiement_id')->nullable()->constrained('paiements')->nullOnDelete();
            // La relance créée dans la même transaction, pour type = relance.
            $table->foreignId('relance_id')->nullable()->constrained('relances')->nullOnDelete();
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auteur_nom')->nullable();

            $table->unsignedTinyInteger('niveau')->nullable(); // niveau de relance (1 à 3)
            // Numéro normalisé (chiffres seuls, indicatif compris). Null quand
            // l'utilisateur choisit le destinataire dans WhatsApp lui-même.
            $table->string('destinataire', 20)->nullable();
            $table->string('langue', 2);  // fr | ar
            $table->text('message');

            // Clé d'idempotence tirée par le navigateur au clic. Sur téléphone,
            // WhatsApp passe au premier plan pendant que le journal part : la
            // réponse se perd alors qu'il a été écrit, et « Réessayer » le
            // réécrirait — deux Relances pour un seul message. Unique par
            // entreprise ; nulle pour les appels qui n'en envoient pas (les
            // valeurs nulles ne se heurtent pas, sur SQLite comme sur
            // PostgreSQL).
            $table->uuid('cle')->nullable();

            // Une ligne de journal ne se modifie pas : pas d'updated_at.
            $table->timestamp('created_at')->nullable();

            // La seule lecture : les envois d'un document, du plus récent au
            // plus ancien. PostgreSQL n'indexe pas une clé étrangère tout seul.
            $table->index(['tenant_id', 'document_vente_id', 'id']);
            $table->unique(['tenant_id', 'cle']);

            // Retirer un membre de l'équipe SUPPRIME sa ligne `users`, et
            // supprimer un tiers sans pièce est permis : dans les deux cas ON
            // DELETE SET NULL cherche les envois qui y pointent — sans index,
            // toute la table, de toutes les entreprises, dans la transaction
            // de suppression. Aucune route ne supprime un paiement ni une
            // relance : leurs clés n'en ont pas besoin.
            $table->index('user_id');
            $table->index('tiers_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
