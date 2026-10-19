<?php

namespace Tests\Feature;

use App\Modules\Communications\Transport\ClickToChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MonteEnvoisWhatsApp;
use Tests\TestCase;

/**
 * Préparation d'un message WhatsApp : à qui écrire, quoi dire en français et
 * en arabe, quel lien joindre — et ce que le serveur refuse de préparer.
 */
class PreparationWhatsAppTest extends TestCase
{
    use MonteEnvoisWhatsApp, RefreshDatabase;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jeton = $this->entreprise('Atlas Distribution', 'patron@atlas.ma')['token'];
    }

    private function preparerVente(int $documentId, string $type, array $params = []): TestResponse
    {
        return $this->withToken($this->jeton)->getJson(
            "/api/v1/ventes/documents/{$documentId}/whatsapp?".http_build_query(['type' => $type] + $params),
        );
    }

    private function preparerRelance(int $documentId, ?int $niveau = null): TestResponse
    {
        $requete = $niveau !== null ? "?niveau={$niveau}" : '';

        return $this->withToken($this->jeton)->getJson("/api/v1/relances/{$documentId}/whatsapp{$requete}");
    }

    public function test_les_numeros_proposes_suivent_l_ordre_et_sont_dedoublonnes(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0522 30 30 30');
        // Le principal : son mobile d'abord. Son fixe est celui de la fiche,
        // écrit autrement — un seul numéro, proposé une seule fois.
        $this->contact($this->jeton, $client, [
            'nom' => 'Karim Alami', 'mobile' => '06 61 00 00 00', 'phone' => '05.22.30.30.30', 'is_principal' => true,
        ]);
        // Un second contact : mobile valable, téléphone inexploitable.
        $this->contact($this->jeton, $client, [
            'nom' => 'Salma Bennani', 'mobile' => '+212 7 00 11 22 33', 'phone' => '0522 40 40 40 poste 12',
        ]);
        // Un contact désactivé n'est pas proposé.
        $this->contact($this->jeton, $client, ['nom' => 'Ancien Gérant', 'mobile' => '0600000000', 'is_active' => false]);

        $facture = $this->piece($this->jeton, $client, 'facture', null, now()->addDays(30)->toDateString());

        $data = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data');

        $this->assertSame(
            ['212661000000', '212700112233', '212522303030'],
            array_column($data['destinataires'], 'numero'),
        );
        $this->assertSame(
            ['Karim Alami · mobile', 'Salma Bennani · mobile', 'Client X · téléphone'],
            array_column($data['destinataires'], 'libelle'),
        );
        $this->assertSame([true, false, false], array_column($data['destinataires'], 'selectionne'));
        $this->assertSame('212661000000', $data['numero_selectionne']);
        $this->assertSame('+212 6 61 00 00 00', $data['destinataires'][0]['affichage']);

        // Le fixe de la fiche est proposé, mais signalé.
        $this->assertSame('fixe', $data['destinataires'][2]['nature']);
        $this->assertSame('fixe — WhatsApp peu probable', $data['destinataires'][2]['avertissement']);

        // L'inexploitable n'a pas disparu en silence : il revient avec sa raison.
        $this->assertCount(1, $data['numeros_ignores']);
        $this->assertSame('Salma Bennani · téléphone', $data['numeros_ignores'][0]['libelle']);
        $this->assertSame('Le numéro contient du texte : corrigez la fiche.', $data['numeros_ignores'][0]['raison']);

        // Le lien wa.me du numéro présélectionné, message tel que préparé.
        $this->assertSame(ClickToChat::lien('212661000000', $data['messages']['fr']), $data['liens_whatsapp']['fr']);
        $this->assertStringStartsWith('https://wa.me/212661000000?text=', $data['liens_whatsapp']['ar']);
    }

    public function test_sans_fixe_seul_le_fixe_est_preselectionne(): void
    {
        $client = $this->client($this->jeton, 'Client Fixe', '0522303030');
        $facture = $this->piece($this->jeton, $client);

        $data = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data');

        $this->assertSame('212522303030', $data['numero_selectionne']);
        $this->assertTrue($data['destinataires'][0]['selectionne']);
    }

    public function test_sans_aucun_numero_whatsapp_laisse_choisir_le_contact(): void
    {
        $client = $this->client($this->jeton, 'Client Sans Numéro');
        $facture = $this->piece($this->jeton, $client);

        $data = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data');

        $this->assertSame([], $data['destinataires']);
        $this->assertNull($data['numero_selectionne']);
        $this->assertStringStartsWith('https://wa.me/?text=', $data['liens_whatsapp']['fr']);
    }

    public function test_le_message_de_facture_porte_numero_montant_echeance_et_lien(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $echeance = now()->addDays(30);
        $facture = $this->piece($this->jeton, $client, 'facture', now()->toDateString(), $echeance->toDateString(), 10287.17);

        $data = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data');
        $fr = $data['messages']['fr'];
        $ar = $data['messages']['ar'];
        $lien = $data['lien_document']['url'];

        // 10 287,17 HT + 20 % = 12 344,60 TTC (arrondi à la ligne).
        $this->assertSame('12344.60', $data['document']['total_ttc']);
        foreach ([$fr, $ar] as $texte) {
            $this->assertStringContainsString($facture['code'], $texte);
            $this->assertStringContainsString($echeance->format('d/m/Y'), $texte);
            $this->assertStringContainsString($lien, $texte);
            $this->assertStringContainsString('Atlas Distribution', $texte, 'Signé du nom de l\'entreprise.');
        }
        $this->assertStringContainsString($this->mad('12 344,60'), $fr);
        $this->assertStringContainsString('à régler au plus tard le '.$echeance->format('d/m/Y'), $fr);
        $this->assertStringContainsString(now()->format('d/m/Y'), $fr);
        $this->assertStringContainsString($this->mad('12 344,60', 'درهم'), $ar);
        $this->assertStringContainsString('فاتورتكم', $ar);

        // Le lien : public, signé, temporaire, sur l'hôte de la requête hors
        // production.
        $this->assertStringStartsWith("http://localhost/api/v1/public/documents/{$facture['id']}/pdf?expires=", $lien);
        $this->assertStringContainsString('&signature=', $lien);
        $this->assertSame(
            now()->addDays(30)->endOfDay()->toIso8601String(),
            $data['lien_document']['expire_le'],
        );
    }

    public function test_une_facture_deja_echue_n_est_pas_a_regler_au_plus_tard_hier(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->factureEchue($this->jeton, $client, 10);
        $echeance = now()->subDays(10)->format('d/m/Y');

        $messages = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data.messages');

        $this->assertStringContainsString(", échue le {$echeance}.", $messages['fr']);
        $this->assertStringNotContainsString('au plus tard', $messages['fr']);
        $this->assertStringContainsString("حل أجل أدائها في {$echeance}", $messages['ar']);
    }

    public function test_le_message_de_devis_porte_sa_validite_si_elle_existe(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $validite = now()->addDays(15);
        $devis = $this->piece($this->jeton, $client, 'devis', null, $validite->toDateString());

        $data = $this->preparerVente($devis['id'], 'devis')->assertOk()->json('data');

        $this->assertStringContainsString("notre devis {$devis['code']}", $data['messages']['fr']);
        $this->assertStringContainsString($this->mad('1 200,00').' TTC, valable jusqu\'au '.$validite->format('d/m/Y'), $data['messages']['fr']);
        $this->assertStringContainsString('عرض الثمن رقم '.$devis['code'], $data['messages']['ar']);
        $this->assertStringContainsString('صالح إلى غاية '.$validite->format('d/m/Y'), $data['messages']['ar']);

        // Sans échéance, le message ne promet aucune validité.
        $sansDate = $this->piece($this->jeton, $client, 'devis');
        $fr = $this->preparerVente($sansDate['id'], 'devis')->assertOk()->json('data.messages.fr');
        $this->assertStringNotContainsString('valable', $fr);
    }

    public function test_un_devis_perime_n_est_pas_dit_valable(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $finValidite = now()->subDays(17);
        $devis = $this->piece($this->jeton, $client, 'devis', now()->subDays(40)->toDateString(), $finValidite->toDateString());

        $messages = $this->preparerVente($devis['id'], 'devis')->assertOk()->json('data.messages');

        $this->assertStringNotContainsString('valable jusqu', $messages['fr']);
        $this->assertStringContainsString(
            'TTC. Sa validité a pris fin le '.$finValidite->format('d/m/Y').' : contactez-nous pour l\'actualiser.',
            $messages['fr'],
        );
        $this->assertStringNotContainsString('صالح إلى غاية', $messages['ar']);
        $this->assertStringContainsString('انتهت صلاحيته في '.$finValidite->format('d/m/Y'), $messages['ar']);

        // Le jour même, il est encore valable.
        $aujourdhui = $this->piece($this->jeton, $client, 'devis', null, now()->toDateString());
        $fr = $this->preparerVente($aujourdhui['id'], 'devis')->assertOk()->json('data.messages.fr');
        $this->assertStringContainsString('valable jusqu\'au '.now()->format('d/m/Y'), $fr);
    }

    public function test_la_confirmation_de_paiement_porte_montant_mode_facture_et_reste(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->piece($this->jeton, $client);
        $acompte = $this->paiement($this->jeton, $facture['id'], 500, 'cheque', 'CHQ-881');

        $data = $this->preparerVente($facture['id'], 'paiement', ['paiement_id' => $acompte])->assertOk()->json('data');

        $this->assertSame($acompte, $data['paiement_id']);
        $fr = $data['messages']['fr'];
        $this->assertStringContainsString('votre paiement de '.$this->mad('500,00').' par chèque (réf. CHQ-881)', $fr);
        $this->assertStringContainsString("pour la facture {$facture['code']}", $fr);
        $this->assertStringContainsString('Reste à payer à ce jour : '.$this->mad('700,00'), $fr);
        $this->assertStringContainsString('بشيك (المرجع: CHQ-881)', $data['messages']['ar']);
        $this->assertStringContainsString($this->mad('700,00', 'درهم'), $data['messages']['ar']);

        // Le solde : la facture est dite soldée.
        $solde = $this->paiement($this->jeton, $facture['id'], 700, 'especes');
        $data = $this->preparerVente($facture['id'], 'paiement', ['paiement_id' => $solde])->assertOk()->json('data');

        $this->assertStringContainsString($this->mad('700,00').' en espèces', $data['messages']['fr']);
        $this->assertStringContainsString('La facture est désormais soldée.', $data['messages']['fr']);
        $this->assertStringContainsString('مؤداة بالكامل', $data['messages']['ar']);
    }

    public function test_ce_qu_une_traite_couvre_n_est_pas_reclame_au_client(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->piece($this->jeton, $client, 'facture', null, now()->addDays(30)->toDateString());
        $acompte = $this->paiement($this->jeton, $facture['id'], 200, 'especes');

        // Le solde de 1 000 passe sur une LCN : aucune ligne de paiement n'est
        // créée, resteAPayer() vaut toujours 1 000.
        $this->withToken($this->jeton)->putJson('/api/v1/parametres', ['features' => ['effets' => true]])->assertOk();
        $echeanceEffet = now()->addDays(60);
        $effet = $this->withToken($this->jeton)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'], 'date_echeance' => $echeanceEffet->toDateString(),
        ])->assertCreated()->json('data');

        $couverture = "Votre effet n° {$effet['code']}, à échéance du {$echeanceEffet->format('d/m/Y')}, couvre "
            .$this->mad('1 000,00').' de cette facture.';

        // En portefeuille : le message dit que l'effet couvre le solde, sans
        // le réclamer ni rappeler la date limite de la facture.
        $paiement = $this->preparerVente($facture['id'], 'paiement', ['paiement_id' => $acompte])->assertOk()->json('data.messages');
        $this->assertStringContainsString($couverture, $paiement['fr']);
        $this->assertStringNotContainsString('Reste à payer', $paiement['fr']);
        $this->assertStringNotContainsString('soldée', $paiement['fr']);
        $this->assertStringContainsString("كمبيالتكم رقم {$effet['code']}", $paiement['ar']);
        $this->assertStringContainsString($this->mad('1 000,00', 'درهم').' من هذه الفاتورة', $paiement['ar']);
        $this->assertStringNotContainsString('المبلغ المتبقي', $paiement['ar']);

        $envoi = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data.messages');
        $this->assertStringContainsString($couverture, $envoi['fr']);
        $this->assertStringNotContainsString('au plus tard', $envoi['fr']);
        $this->assertStringNotContainsString('Reste à payer', $envoi['fr']);
        $this->assertStringNotContainsString('يرجى أداؤها', $envoi['ar']);

        // Encaissée par la banque, la traite vaut règlement : la facture est
        // dite soldée, alors qu'aucun Paiement ne s'est ajouté.
        $this->withToken($this->jeton)->postJson("/api/v1/effets/{$effet['id']}/encaisser")->assertOk();

        $paiement = $this->preparerVente($facture['id'], 'paiement', ['paiement_id' => $acompte])->assertOk()->json('data.messages');
        $this->assertStringContainsString('La facture est désormais soldée.', $paiement['fr']);
        $this->assertStringNotContainsString('effet', $paiement['fr']);
        $this->assertStringContainsString('أصبحت الفاتورة مؤداة بالكامل.', $paiement['ar']);

        $envoi = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data.messages');
        $this->assertStringContainsString('Elle est déjà réglée : merci.', $envoi['fr']);
        $this->assertStringNotContainsString('Reste à payer', $envoi['fr']);
    }

    public function test_une_traite_impayee_rend_la_somme_au_client(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->piece($this->jeton, $client);

        $this->withToken($this->jeton)->putJson('/api/v1/parametres', ['features' => ['effets' => true]])->assertOk();
        $effet = $this->withToken($this->jeton)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'], 'date_echeance' => now()->addDays(60)->toDateString(),
        ])->assertCreated()->json('data');
        $this->withToken($this->jeton)->postJson("/api/v1/effets/{$effet['id']}/impaye")->assertOk();

        // L'effet impayé ne couvre plus rien : la créance revient au client.
        $fr = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data.messages.fr');
        $this->assertStringNotContainsString('effet', $fr);
        $this->assertStringNotContainsString('réglée', $fr);
    }

    public function test_le_ton_de_la_relance_monte_avec_le_niveau(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->factureEchue($this->jeton, $client, 32);
        $this->paiement($this->jeton, $facture['id'], 200);
        $echeance = now()->subDays(32)->format('d/m/Y');

        $niveau1 = $this->preparerRelance($facture['id'], 1)->assertOk()->json('data');
        $this->assertSame(1, $niveau1['niveau']);
        $this->assertSame('Rappel', $niveau1['niveau_label']);
        $this->assertTrue($niveau1['document']['echue']);
        $this->assertSame(32, $niveau1['document']['jours_retard']);
        $this->assertSame('1000.00', $niveau1['document']['reste_a_payer']);
        foreach (['fr', 'ar'] as $langue) {
            $this->assertStringContainsString($facture['code'], $niveau1['messages'][$langue]);
            $this->assertStringContainsString($echeance, $niveau1['messages'][$langue]);
            $this->assertStringContainsString('32', $niveau1['messages'][$langue]);
        }
        $this->assertStringContainsString('Sauf erreur de notre part', $niveau1['messages']['fr']);
        $this->assertStringContainsString('(32 jours de retard)', $niveau1['messages']['fr']);
        $this->assertStringContainsString('Reste à payer : '.$this->mad('1 000,00'), $niveau1['messages']['fr']);
        $this->assertStringContainsString('merci de ne pas tenir compte de ce message', $niveau1['messages']['fr']);
        $this->assertStringContainsString('المبلغ المتبقي: '.$this->mad('1 000,00', 'درهم'), $niveau1['messages']['ar']);

        $niveau2 = $this->preparerRelance($facture['id'], 2)->assertOk()->json('data');
        $this->assertStringContainsString('Malgré notre précédent rappel', $niveau2['messages']['fr']);
        $this->assertStringContainsString('sous huit jours', $niveau2['messages']['fr']);
        $this->assertStringContainsString('رغم تذكيرنا السابق', $niveau2['messages']['ar']);

        $niveau3 = $this->preparerRelance($facture['id'], 3)->assertOk()->json('data');
        $this->assertSame('Mise en demeure', $niveau3['niveau_label']);
        $this->assertStringContainsString('Nous vous mettons en demeure', $niveau3['messages']['fr']);
        $this->assertStringContainsString('procédure de recouvrement', $niveau3['messages']['fr']);
        $this->assertStringContainsString('إنذارا', $niveau3['messages']['ar']);
    }

    public function test_sans_niveau_demande_la_relance_suit_la_precedente(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->factureEchue($this->jeton, $client);

        $this->assertSame(1, $this->preparerRelance($facture['id'])->assertOk()->json('data.niveau'));

        $this->withToken($this->jeton)->postJson('/api/v1/relances', [
            'document_vente_id' => $facture['id'], 'niveau' => 1,
        ])->assertCreated();

        $data = $this->preparerRelance($facture['id'])->assertOk()->json('data');
        $this->assertSame(2, $data['niveau']);
        $this->assertSame([false, true, false], array_column($data['niveaux'], 'suggere'));
        $this->assertSame([true, true, true], array_column($data['niveaux'], 'possible'));
    }

    public function test_avant_l_echeance_seul_un_rappel_courtois_est_possible(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $echeance = now()->addDays(5);
        $facture = $this->piece($this->jeton, $client, 'facture', null, $echeance->toDateString());

        $data = $this->preparerRelance($facture['id'])->assertOk()->json('data');
        $this->assertSame(1, $data['niveau']);
        $this->assertFalse($data['document']['echue']);
        $this->assertStringContainsString('arrive à échéance le '.$echeance->format('d/m/Y'), $data['messages']['fr']);
        $this->assertSame([true, false, false], array_column($data['niveaux'], 'possible'));

        $this->preparerRelance($facture['id'], 2)->assertUnprocessable()->assertJsonValidationErrors('niveau');
        $this->preparerRelance($facture['id'], 3)->assertUnprocessable()->assertJsonValidationErrors('niveau');
    }

    public function test_un_brouillon_ne_se_prepare_pas(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->piece($this->jeton, $client, 'facture', valider: false);
        $devis = $this->piece($this->jeton, $client, 'devis', valider: false);

        $this->preparerVente($facture['id'], 'facture')->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'Un document en brouillon ne s\'envoie pas : validez-le d\'abord.');
        $this->preparerVente($devis['id'], 'devis')->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->preparerRelance($facture['id'], 1)->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_une_facture_soldee_ne_se_relance_pas(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->factureEchue($this->jeton, $client);
        $this->paiement($this->jeton, $facture['id'], 1200);

        $this->preparerRelance($facture['id'], 1)->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'Cette facture est déjà soldée.');

        // L'envoi de la facture, lui, reste possible : elle est dite réglée.
        $fr = $this->preparerVente($facture['id'], 'facture')->assertOk()->json('data.messages.fr');
        $this->assertStringContainsString('Elle est déjà réglée', $fr);
    }

    public function test_une_facture_couverte_par_une_traite_ne_se_relance_pas(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->factureEchue($this->jeton, $client);

        $this->withToken($this->jeton)->putJson('/api/v1/parametres', ['features' => ['effets' => true]])->assertOk();
        $this->withToken($this->jeton)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'], 'date_echeance' => now()->addDays(60)->toDateString(),
        ])->assertCreated();

        // Même exclusion que la liste « à relancer » : c'est l'effet qui se suit.
        $this->preparerRelance($facture['id'], 1)->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'Cette facture est couverte par une traite en cours : c\'est l\'effet qui se suit.');
    }

    public function test_le_type_doit_correspondre_au_document(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->piece($this->jeton, $client);
        $devis = $this->piece($this->jeton, $client, 'devis');

        $this->preparerVente($facture['id'], 'devis')->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->preparerVente($devis['id'], 'facture')->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->preparerRelance($devis['id'], 1)->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'Seule une facture peut être relancée.');

        // La relance ne passe pas par la route des ventes : elle relève d'une
        // autre permission.
        $this->preparerVente($facture['id'], 'relance')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->preparerVente($facture['id'], 'sms')->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_le_paiement_doit_regler_ce_document(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $factureA = $this->piece($this->jeton, $client);
        $factureB = $this->piece($this->jeton, $client);
        $paiementDeB = $this->paiement($this->jeton, $factureB['id'], 100);

        $this->preparerVente($factureA['id'], 'paiement', ['paiement_id' => $paiementDeB])
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement_id.0', 'Ce paiement ne concerne pas ce document.');

        $this->preparerVente($factureA['id'], 'paiement')->assertUnprocessable()->assertJsonValidationErrors('paiement_id');
        $this->preparerVente($factureA['id'], 'paiement', ['paiement_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement_id.0', 'Ce paiement ne concerne pas ce document.');
    }

    public function test_un_document_d_une_autre_entreprise_est_introuvable(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->factureEchue($this->jeton, $client);
        $paiement = $this->paiement($this->jeton, $facture['id'], 100);

        $autre = $this->entreprise('Concurrent', 'patron@concurrent.ma');
        $clientAutre = $this->client($autre['token'], 'Client Y', '0699999999');
        $factureAutre = $this->piece($autre['token'], $clientAutre);

        $this->withToken($autre['token'])->getJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp?type=facture")->assertNotFound();
        $this->withToken($autre['token'])->getJson("/api/v1/relances/{$facture['id']}/whatsapp")->assertNotFound();

        // Le paiement d'une autre entreprise : même refus qu'un paiement d'une
        // autre pièce, rien à sonder.
        $this->withToken($autre['token'])
            ->getJson("/api/v1/ventes/documents/{$factureAutre['id']}/whatsapp?type=paiement&paiement_id={$paiement}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement_id.0', 'Ce paiement ne concerne pas ce document.');
    }

    public function test_les_refus_parlent_la_langue_de_l_appelant(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0522 30 30 30 poste 4');
        $facture = $this->piece($this->jeton, $client, 'facture', valider: false);

        $this->withToken($this->jeton)->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp?type=facture")
            ->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'لا تُرسَل وثيقة في وضعية مسودة: صادق عليها أولا.');

        $valide = $this->piece($this->jeton, $client);
        $data = $this->withToken($this->jeton)->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/ventes/documents/{$valide['id']}/whatsapp?type=facture")->assertOk()->json('data');

        $this->assertSame('Client X · الهاتف', $data['numeros_ignores'][0]['libelle']);
        $this->assertSame('يحتوي الرقم على نص: صحّح البطاقة.', $data['numeros_ignores'][0]['raison']);
        // Le message au client, lui, ne dépend pas de la langue de l'écran.
        $this->assertStringStartsWith('Bonjour,', $data['messages']['fr']);
    }

    public function test_preparer_demande_le_droit_d_ecrire(): void
    {
        $atlas = $this->entreprise('Atlas Bis', 'patron@atlas-bis.ma');
        $client = $this->client($atlas['token'], 'Client X', '0612345678');
        $facture = $this->factureEchue($atlas['token'], $client);

        // La préparation émet un lien public au porteur, valable trente jours,
        // que rien ne trace ni ne révoque : la lecture seule ne l'obtient pas,
        // même si la route est un GET.
        $lecture = $this->jetonPourRole($atlas['tenant_id'], 'lecture');
        $this->withToken($lecture)->getJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp?type=facture")
            ->assertForbidden()
            ->assertJsonMissingPath('data');
        $this->withToken($lecture)->getJson("/api/v1/relances/{$facture['id']}/whatsapp")->assertForbidden();
        // Elle lit toujours le journal.
        $this->withToken($lecture)->getJson("/api/v1/ventes/documents/{$facture['id']}/envois")->assertOk();

        // Le comptable : ventes en lecture, recouvrement en écriture — il
        // prépare la relance, pas l'envoi de la facture.
        $comptable = $this->jetonPourRole($atlas['tenant_id'], 'comptable');
        $this->withToken($comptable)->getJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp?type=facture")->assertForbidden();
        $this->withToken($comptable)->getJson("/api/v1/relances/{$facture['id']}/whatsapp")->assertOk();

        // Le commercial écrit dans les deux.
        $commercial = $this->jetonPourRole($atlas['tenant_id'], 'commercial');
        $this->withToken($commercial)->getJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp?type=facture")->assertOk();

        // Le caissier n'a ni les ventes ni le recouvrement.
        $caissier = $this->jetonPourRole($atlas['tenant_id'], 'caissier');
        $this->withToken($caissier)->getJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp?type=facture")->assertForbidden();
        $this->withToken($caissier)->getJson("/api/v1/relances/{$facture['id']}/whatsapp")->assertForbidden();
    }

    public function test_le_module_relances_desactive_ferme_la_relance_whatsapp(): void
    {
        $client = $this->client($this->jeton, 'Client X', '0612345678');
        $facture = $this->factureEchue($this->jeton, $client);

        $this->withToken($this->jeton)->putJson('/api/v1/parametres', ['features' => ['relances' => false]])->assertOk();

        $this->preparerRelance($facture['id'])->assertForbidden();
        // L'envoi de la facture ne dépend pas du module de recouvrement.
        $this->preparerVente($facture['id'], 'facture')->assertOk();
    }

    public function test_un_identifiant_non_numerique_repond_404(): void
    {
        $this->withToken($this->jeton)->getJson('/api/v1/ventes/documents/abc/whatsapp?type=facture')->assertNotFound();
        $this->withToken($this->jeton)->getJson('/api/v1/relances/abc/whatsapp')->assertNotFound();
    }
}
