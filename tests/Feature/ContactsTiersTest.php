<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Tiers\Models\Contact;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\ContactService;
use App\Modules\Tiers\Services\TiersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les interlocuteurs d'un tiers.
 *
 * On ne traite jamais avec une société, on traite avec des gens : le directeur
 * des achats passe la commande, la comptabilité règle la facture. Jusqu'ici
 * une seule case texte pour les deux.
 */
class ContactsTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tiers $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        $this->jeton = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->json('token');

        app(TenantContext::class)->set(
            User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant,
        );

        $this->client = app(TiersService::class)->create([
            'name' => 'CLIM & COOL', 'is_client' => true, 'is_supplier' => false,
        ]);
    }

    private function creer(array $data = []): Contact
    {
        return app(ContactService::class)->create($this->client, array_merge([
            'nom' => 'Karim Alami', 'fonction' => 'Directeur achats',
        ], $data));
    }

    /* ---------------------------------------------------------------- */

    public function test_un_tiers_porte_plusieurs_interlocuteurs(): void
    {
        $this->creer();
        $this->creer(['nom' => 'Salma Bennani', 'fonction' => 'Comptabilité']);

        $contacts = $this->withToken($this->jeton)
            ->getJson("/api/v1/tiers/{$this->client->id}/contacts")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $contacts);
        // Le principal en tête : c'est celui qu'on cherche neuf fois sur dix.
        $this->assertTrue($contacts[0]['is_principal']);
        $this->assertSame('Karim Alami', $contacts[0]['nom']);
    }

    /** Sans cela, un tiers a des contacts mais personne à qui écrire. */
    public function test_le_premier_contact_est_principal_d_office(): void
    {
        $this->assertTrue($this->creer()->is_principal);
    }

    public function test_le_second_contact_ne_l_est_pas(): void
    {
        $this->creer();

        $this->assertFalse($this->creer(['nom' => 'Salma Bennani'])->is_principal);
    }

    /**
     * LA règle du lot : deux principaux et l'envoi d'une relance devient un
     * tirage au sort. Elle n'est pas en base — une contrainte unique partielle
     * ne s'écrit pas pareil sur SQLite et sur PostgreSQL — donc elle se prouve.
     */
    public function test_designer_un_principal_destitue_l_ancien(): void
    {
        $premier = $this->creer();
        $second = $this->creer(['nom' => 'Salma Bennani']);

        app(ContactService::class)->update($second, ['is_principal' => true]);

        $this->assertTrue($second->refresh()->is_principal);
        $this->assertFalse($premier->refresh()->is_principal);
        $this->assertSame(1, Contact::where('tiers_id', $this->client->id)->where('is_principal', true)->count());
    }

    public function test_supprimer_le_principal_en_designe_un_autre(): void
    {
        $premier = $this->creer();
        $second = $this->creer(['nom' => 'Salma Bennani']);

        app(ContactService::class)->delete($premier);

        $this->assertTrue($second->refresh()->is_principal, 'Le tiers ne doit pas rester sans interlocuteur désigné.');
    }

    public function test_un_contact_se_cree_et_se_modifie_par_l_api(): void
    {
        $cree = $this->withToken($this->jeton)
            ->postJson("/api/v1/tiers/{$this->client->id}/contacts", [
                'nom' => 'Karim Alami',
                'fonction' => 'Directeur achats',
                'email' => 'k.alami@climcool.ma',
                'mobile' => '0661000000',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('k.alami@climcool.ma', $cree['email']);

        $this->withToken($this->jeton)
            ->putJson("/api/v1/tiers/{$this->client->id}/contacts/{$cree['id']}", ['fonction' => 'DG'])
            ->assertOk()
            ->assertJsonPath('data.fonction', 'DG')
            ->assertJsonPath('data.nom', 'Karim Alami');
    }

    /**
     * Sans ce contrôle, /tiers/1/contacts/42 modifierait le contact du tiers 2 :
     * l'identifiant du contact suffirait, celui du tiers ne servirait à rien.
     */
    public function test_un_contact_ne_se_modifie_pas_par_le_mauvais_tiers(): void
    {
        $contact = $this->creer();
        $autre = app(TiersService::class)->create([
            'name' => 'AUTRE SARL', 'is_client' => true, 'is_supplier' => false,
        ]);

        $this->withToken($this->jeton)
            ->putJson("/api/v1/tiers/{$autre->id}/contacts/{$contact->id}", ['nom' => 'Pirate'])
            ->assertNotFound();

        $this->assertSame('Karim Alami', $contact->refresh()->nom);
    }

    public function test_un_contact_ne_change_pas_de_tiers(): void
    {
        $contact = $this->creer();
        $autre = app(TiersService::class)->create([
            'name' => 'AUTRE SARL', 'is_client' => true, 'is_supplier' => false,
        ]);

        app(ContactService::class)->update($contact, ['tiers_id' => $autre->id, 'nom' => 'Karim A.']);

        $this->assertSame($this->client->id, $contact->refresh()->tiers_id);
    }

    public function test_un_contact_se_supprime_par_l_api(): void
    {
        $contact = $this->creer();

        $this->withToken($this->jeton)
            ->deleteJson("/api/v1/tiers/{$this->client->id}/contacts/{$contact->id}")
            ->assertNoContent();

        $this->assertSame(0, Contact::where('tiers_id', $this->client->id)->count());
    }

    public function test_un_nom_est_obligatoire(): void
    {
        $this->withToken($this->jeton)
            ->postJson("/api/v1/tiers/{$this->client->id}/contacts", ['fonction' => 'Sans nom'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nom');
    }

    /** Le cloisonnement : les contacts d'une entreprise ne fuient pas ailleurs. */
    public function test_les_contacts_ne_traversent_pas_les_entreprises(): void
    {
        $contact = $this->creer();

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Concurrent', 'name' => 'Autre',
            'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->assertCreated();

        $jetonAutre = $this->postJson('/api/v1/auth/login', [
            'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->json('token');

        $this->withToken($jetonAutre)
            ->getJson("/api/v1/tiers/{$this->client->id}/contacts")
            ->assertNotFound();

        $this->assertSame('Karim Alami', $contact->refresh()->nom);
    }
}
