<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deux champs de la fiche façon Zoho : la FORME (entreprise ou particulier) et
 * l'adresse de LIVRAISON distincte de la facturation.
 *
 * Ce qui est éprouvé : l'existant devient « entreprise » sans rien déduire de
 * l'ICE ; aucun ICE n'est exigé, ni d'un particulier ni d'une entreprise ; une
 * livraison « identique » ne garde aucune adresse cachée ; une mise à jour
 * partielle n'efface rien ; et le bon de livraison imprime l'adresse où livrer.
 */
class FormeEtLivraisonTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jeton = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin', 'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $this->entreprise = User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant;
    }

    /** @return array<string, mixed> */
    private function creer(array $donnees): array
    {
        return $this->withToken($this->jeton)->postJson('/api/v1/tiers', $donnees)->assertCreated()->json('data');
    }

    /* ------------------------------- forme ------------------------------- */

    public function test_un_tiers_existant_devient_entreprise_par_le_defaut_de_la_colonne(): void
    {
        // Une ligne écrite SANS la colonne — comme toutes celles d'avant la
        // migration, et comme les reprises Zoho : c'est la base qui tranche.
        $id = DB::table('tiers')->insertGetId([
            'tenant_id' => $this->entreprise->id, 'code' => 'CL-ANCIEN', 'name' => 'Ancien client',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tiers = $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$id}")->assertOk()->json('data');

        $this->assertSame('entreprise', $tiers['forme']);
        $this->assertTrue($tiers['livraison_identique']);
        $this->assertNull($tiers['adresse_livraison']);
    }

    public function test_la_forme_absente_vaut_entreprise_des_la_reponse_de_creation(): void
    {
        $tiers = $this->creer(['name' => 'Atlas SARL']);

        // La réponse elle-même, pas une relecture : l'instance créée ne relit
        // pas la ligne, le défaut doit être connu du modèle.
        $this->assertSame('entreprise', $tiers['forme']);
        $this->assertTrue($tiers['livraison_identique']);
    }

    public function test_un_particulier_s_enregistre_sans_ice(): void
    {
        $tiers = $this->creer(['name' => 'Karim Bennani', 'forme' => 'particulier']);

        $this->assertSame('particulier', $tiers['forme']);
        $this->assertNull($tiers['ice']);
    }

    public function test_une_entreprise_sans_ice_reste_acceptee_et_rien_ne_se_deduit_de_l_ice(): void
    {
        // Pas d'ICE : toujours une entreprise — la forme ne se devine pas.
        $sansIce = $this->creer(['name' => 'Société sans ICE']);
        $this->assertSame('entreprise', $sansIce['forme']);

        // Un particulier qui a un ICE (auto-entrepreneur) reste un particulier.
        $autoEntrepreneur = $this->creer(['name' => 'Yassine Auto', 'forme' => 'particulier', 'ice' => '001234567000089']);
        $this->assertSame('particulier', $autoEntrepreneur['forme']);
        $this->assertSame('001234567000089', $autoEntrepreneur['ice']);
    }

    public function test_une_forme_inconnue_est_refusee(): void
    {
        $this->withToken($this->jeton)->postJson('/api/v1/tiers', ['name' => 'X', 'forme' => 'association'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('forme');
    }

    public function test_la_forme_se_modifie_sans_toucher_au_reste(): void
    {
        $tiers = $this->creer(['name' => 'Karim Bennani', 'city' => 'Rabat']);

        $maj = $this->withToken($this->jeton)->putJson("/api/v1/tiers/{$tiers['id']}", ['forme' => 'particulier'])
            ->assertOk()->json('data');

        $this->assertSame('particulier', $maj['forme']);
        $this->assertSame('Rabat', $maj['city']);
    }

    /* ----------------------------- livraison ----------------------------- */

    public function test_une_adresse_de_livraison_distincte_est_enregistree_et_rendue(): void
    {
        $tiers = $this->creer([
            'name' => 'Clinique Atlas', 'address' => '12 rue de Fès', 'city' => 'Rabat',
            'livraison_identique' => false,
            'adresse_livraison' => 'Zone industrielle, lot 45', 'ville_livraison' => 'Salé', 'code_postal_livraison' => '11000',
        ]);

        $this->assertFalse($tiers['livraison_identique']);
        $this->assertSame('Zone industrielle, lot 45', $tiers['adresse_livraison']);
        $this->assertSame('Salé', $tiers['ville_livraison']);
        $this->assertSame('11000', $tiers['code_postal_livraison']);
        // La facturation n'a pas bougé.
        $this->assertSame('12 rue de Fès', $tiers['address']);
    }

    public function test_livrer_ailleurs_sans_dire_ou_est_refuse_avec_un_message_qui_dit_quoi_faire(): void
    {
        $reponse = $this->withToken($this->jeton)->postJson('/api/v1/tiers', [
            'name' => 'Clinique Atlas', 'livraison_identique' => false, 'ville_livraison' => 'Salé',
        ])->assertUnprocessable()->assertJsonValidationErrors('adresse_livraison');

        // Le message cite la case telle que le formulaire l'écrit — une case
        // « Identique à l'adresse de facturation » n'existe nulle part.
        $this->assertStringContainsString('« Livraison à l\'adresse de facturation »', $reponse->json('errors.adresse_livraison.0'));

        // Et en arabe, le même refus dans la langue de l'écran, avec le
        // libellé arabe de la même case.
        $arabe = $this->withToken($this->jeton)->withHeader('Accept-Language', 'ar')->postJson('/api/v1/tiers', [
            'name' => 'Clinique Atlas', 'livraison_identique' => false,
        ])->assertUnprocessable();
        $this->assertStringContainsString('عنوان التسليم', $arabe->json('errors.adresse_livraison.0'));
        $this->assertStringContainsString('« التسليم في عنوان الفوترة »', $arabe->json('errors.adresse_livraison.0'));
    }

    public function test_livraison_identique_vide_les_champs_meme_s_ils_sont_envoyes(): void
    {
        $tiers = $this->creer([
            'name' => 'Clinique Atlas', 'livraison_identique' => false, 'adresse_livraison' => 'Lot 45',
        ]);

        // Recochée : l'ancienne adresse ne doit pas dormir sous la case.
        $maj = $this->withToken($this->jeton)->putJson("/api/v1/tiers/{$tiers['id']}", [
            'livraison_identique' => true, 'adresse_livraison' => 'Lot 99', 'ville_livraison' => 'Salé',
        ])->assertOk()->json('data');

        $this->assertTrue($maj['livraison_identique']);
        $this->assertNull($maj['adresse_livraison']);
        $this->assertNull($maj['ville_livraison']);

        // À la création aussi : identique par défaut, rien n'est gardé.
        $cree = $this->creer(['name' => 'Autre', 'adresse_livraison' => 'Lot 7']);
        $this->assertTrue($cree['livraison_identique']);
        $this->assertNull($cree['adresse_livraison']);
    }

    public function test_une_mise_a_jour_partielle_garde_l_adresse_de_livraison(): void
    {
        $tiers = $this->creer([
            'name' => 'Clinique Atlas', 'livraison_identique' => false, 'adresse_livraison' => 'Lot 45', 'ville_livraison' => 'Salé',
        ]);

        // « Désactiver » n'envoie que is_active : rien d'autre ne bouge.
        $maj = $this->withToken($this->jeton)->putJson("/api/v1/tiers/{$tiers['id']}", ['is_active' => false])
            ->assertOk()->json('data');

        $this->assertFalse($maj['livraison_identique']);
        $this->assertSame('Lot 45', $maj['adresse_livraison']);
        $this->assertSame('Salé', $maj['ville_livraison']);
    }

    /* ------------------------- bon de livraison ------------------------- */

    private function bonLivraison(int $tiersId): DocumentVente
    {
        app(TenantContext::class)->set($this->entreprise);

        return app(VenteService::class)->create([
            'type' => DocumentVente::TYPE_BON_LIVRAISON,
            'tiers_id' => $tiersId,
            'date_document' => now()->toDateString(),
            'lignes' => [['designation' => 'Toner', 'quantite' => 2, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ]);
    }

    /** Le HTML que DomPDF reçoit — le PDF compressé ne se lit pas en test. */
    private function htmlDuDocument(DocumentVente $document): string
    {
        return view('pdf.document-vente', [
            'document' => $document->load(['lignes', 'tiers', 'tenant', 'paiements', 'source']),
            'tvaBreakdown' => collect(),
            'montantEnLettres' => '—',
        ])->render();
    }

    public function test_le_bon_de_livraison_imprime_l_adresse_ou_livrer(): void
    {
        $tiers = $this->creer([
            'name' => 'Clinique Atlas', 'address' => '12 rue de Fès', 'city' => 'Rabat',
            'livraison_identique' => false, 'adresse_livraison' => 'Zone industrielle, lot 45',
            'ville_livraison' => 'Salé', 'code_postal_livraison' => '11000',
        ]);
        $bl = $this->bonLivraison($tiers['id']);

        $html = $this->htmlDuDocument($bl);
        $this->assertStringContainsString('Livrer à', $html);
        $this->assertStringContainsString('Zone industrielle, lot 45', $html);
        $this->assertStringContainsString('11000 Salé', $html);

        // Et le PDF se génère, bloc compris.
        $pdf = $this->withToken($this->jeton)->get("/api/v1/ventes/documents/{$bl->id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_sans_adresse_distincte_le_bon_de_livraison_ne_repete_rien(): void
    {
        $tiers = $this->creer(['name' => 'Clinique Atlas', 'address' => '12 rue de Fès', 'city' => 'Rabat']);

        $this->assertStringNotContainsString('Livrer à', $this->htmlDuDocument($this->bonLivraison($tiers['id'])));
    }

    public function test_la_facture_n_imprime_pas_l_adresse_de_livraison(): void
    {
        $tiers = $this->creer([
            'name' => 'Clinique Atlas', 'livraison_identique' => false, 'adresse_livraison' => 'Zone industrielle, lot 45',
        ]);
        app(TenantContext::class)->set($this->entreprise);
        $facture = app(VenteService::class)->create([
            'type' => DocumentVente::TYPE_FACTURE, 'tiers_id' => $tiers['id'], 'date_document' => now()->toDateString(),
            'lignes' => [['designation' => 'Toner', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ]);

        $this->assertStringNotContainsString('Zone industrielle', $this->htmlDuDocument($facture));
    }
}
