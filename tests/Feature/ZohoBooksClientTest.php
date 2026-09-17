<?php

namespace Tests\Feature;

use App\Modules\Integrations\Zoho\ZohoBooksClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Le client Zoho Books : s'authentifier et paginer, rien d'autre.
 *
 * Zoho n'est jamais appelé pour de vrai ; les réponses sont simulées.
 */
class ZohoBooksClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('zoho.books', [
            'client_id' => '1000.TEST',
            'client_secret' => 'secret-a-ne-jamais-journaliser',
            'refresh_token' => '1000.refresh',
            'organization_id' => '20080363566',
            'accounts_url' => 'https://accounts.zoho.eu',
            'api_url' => 'https://www.zohoapis.eu/books/v3',
            'pause_entre_pages_ms' => 0,
        ]);

        Cache::flush();
    }

    public function test_la_configuration_incomplete_est_detectee(): void
    {
        $this->assertTrue(app(ZohoBooksClient::class)->estConfigure());

        config()->set('zoho.books.refresh_token', null);
        $this->assertFalse(app(ZohoBooksClient::class)->estConfigure());
    }

    public function test_le_jeton_est_demande_une_seule_fois_pour_plusieurs_appels(): void
    {
        Http::fake([
            'accounts.zoho.eu/*' => Http::response(['access_token' => 'jeton-abc', 'expires_in' => 3600]),
            'zohoapis.eu/*' => Http::response([
                'contacts' => [['contact_id' => '1', 'contact_name' => 'ACME']],
                'page_context' => ['has_more_page' => false],
            ]),
        ]);

        $client = app(ZohoBooksClient::class);
        iterator_to_array($client->contacts('customer'));
        iterator_to_array($client->contacts('vendor'));

        // Un jeton vaut une heure : le redemander à chaque appel gaspillerait
        // le quota d'API pour rien.
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'accounts.zoho.eu/oauth/v2/token'));
    }

    public function test_la_pagination_suit_has_more_page(): void
    {
        $page = 0;

        Http::fake([
            'accounts.zoho.eu/*' => Http::response(['access_token' => 'jeton-abc']),
            'zohoapis.eu/*' => function () use (&$page) {
                $page++;

                return Http::response([
                    'contacts' => [['contact_id' => (string) $page]],
                    'page_context' => ['has_more_page' => $page < 3],
                ]);
            },
        ]);

        $contacts = iterator_to_array(app(ZohoBooksClient::class)->contacts('customer'));

        $this->assertCount(3, $contacts);
        $this->assertSame(['1', '2', '3'], array_column($contacts, 'contact_id'));
    }

    /**
     * Le piège du centre de données : un compte européen ne répond pas sur les
     * domaines `.com` de la documentation, et Zoho renvoie alors un
     * « invalid_client » parfaitement trompeur puisque les identifiants sont
     * bons. Le message d'erreur doit orienter vers la vraie cause.
     */
    public function test_le_refus_du_jeton_explique_le_centre_de_donnees_sans_divulguer_le_secret(): void
    {
        Http::fake([
            'accounts.zoho.eu/*' => Http::response(['error' => 'invalid_client'], 400),
        ]);

        try {
            iterator_to_array(app(ZohoBooksClient::class)->contacts('customer'));
            $this->fail('Un refus de jeton doit lever une exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid_client', $e->getMessage());
            $this->assertStringContainsString('centre de données', $e->getMessage());
            $this->assertStringNotContainsString(
                'secret-a-ne-jamais-journaliser',
                $e->getMessage(),
                'Le secret ne doit jamais apparaître dans un message d\'erreur.',
            );
        }
    }

    public function test_l_organisation_accompagne_chaque_appel(): void
    {
        Http::fake([
            'accounts.zoho.eu/*' => Http::response(['access_token' => 'jeton-abc']),
            'zohoapis.eu/*' => Http::response(['contacts' => [], 'page_context' => ['has_more_page' => false]]),
        ]);

        iterator_to_array(app(ZohoBooksClient::class)->contacts('customer'));

        // Sans organization_id, Zoho Books répond sur une autre organisation —
        // ou sur aucune. C'est la première cause d'un import qui « ne remonte rien ».
        Http::assertSent(fn ($r) => str_contains($r->url(), 'organization_id=20080363566')
            && str_contains($r->url(), 'contact_type=customer'));
    }
}
