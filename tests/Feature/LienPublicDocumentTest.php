<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communications\Services\LienPublicDocument;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\DocumentPdfService;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MonteEnvoisWhatsApp;
use Tests\TestCase;

/**
 * Le lien public vers le PDF, glissé dans le message WhatsApp : il s'ouvre
 * sans compte, mais seulement signé, à temps, et pour une pièce partageable.
 * Tout refus est le MÊME 403, pour ne rien laisser deviner.
 */
class LienPublicDocumentTest extends TestCase
{
    use MonteEnvoisWhatsApp, RefreshDatabase;

    /** @var array{token: string, tenant_id: int, user_id: int} */
    private array $atlas;

    private array $facture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->entreprise('Atlas Distribution', 'patron@atlas.ma');
        $client = $this->client($this->atlas['token'], 'Client X', '0661000000');
        $this->facture = $this->piece($this->atlas['token'], $client);
    }

    private function lien(int $documentId, string $hote = 'http://localhost'): string
    {
        return $this->withToken($this->atlas['token'])
            ->getJson("{$hote}/api/v1/ventes/documents/{$documentId}/whatsapp?type=facture")
            ->assertOk()->json('data.lien_document.url');
    }

    /** Le chemin et la requête d'un lien, sans son hôte. */
    private function chemin(string $url): string
    {
        $morceaux = parse_url($url);

        return $morceaux['path'].'?'.$morceaux['query'];
    }

    /** Une requête ANONYME : aucun jeton, aucun en-tête d'authentification. */
    private function ouvrir(string $url): TestResponse
    {
        $this->flushHeaders();

        return $this->get($url);
    }

    private function assertRefusSobre(TestResponse $reponse): void
    {
        $reponse->assertForbidden();
        $this->assertStringStartsWith('text/plain', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString("Ce lien n'est pas valide ou a expiré.", $reponse->getContent());
        $this->assertStringNotContainsString('%PDF', $reponse->getContent());
    }

    public function test_un_lien_valide_sert_le_pdf_en_ligne(): void
    {
        $reponse = $this->ouvrir($this->lien($this->facture['id']));

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        // « inline » : le PDF s'affiche sur le téléphone au lieu d'être téléchargé.
        $this->assertStringStartsWith('inline;', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString($this->facture['code'].'.pdf', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $reponse->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $reponse->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $reponse->headers->get('Referrer-Policy'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_le_pdf_est_rendu_dans_l_entreprise_du_document_puis_le_contexte_est_rendu(): void
    {
        $lien = $this->lien($this->facture['id']);

        // Le rendu est observé de l'intérieur : sans l'entreprise posée, le
        // scope fail-closed ne trouverait ni les lignes ni le client.
        $vu = new \ArrayObject;
        $this->app->bind(DocumentPdfService::class, fn () => new class($vu) extends DocumentPdfService
        {
            public function __construct(private \ArrayObject $vu) {}

            public function rendre(DocumentVente $document): PdfDocument
            {
                $this->vu['tenant'] = app(TenantContext::class)->id();
                $this->vu['lignes'] = $document->lignes()->count();

                return parent::rendre($document);
            }
        });

        $this->ouvrir($lien)->assertOk();

        $this->assertSame($this->atlas['tenant_id'], $vu['tenant']);
        $this->assertSame(1, $vu['lignes']);
        $this->assertFalse(app(TenantContext::class)->check(), 'Le contexte doit être restauré après le rendu.');
    }

    public function test_un_devis_se_partage_aussi(): void
    {
        $devis = $this->piece($this->atlas['token'], $this->facture['tiers_id'], 'devis');
        $lien = $this->withToken($this->atlas['token'])
            ->getJson("/api/v1/ventes/documents/{$devis['id']}/whatsapp?type=devis")
            ->json('data.lien_document.url');

        $this->assertStringStartsWith('%PDF', $this->ouvrir($lien)->assertOk()->getContent());
    }

    public function test_un_lien_expire_est_refuse(): void
    {
        $lien = $this->lien($this->facture['id']);

        // Valable jusqu'à la FIN du trentième jour…
        $this->travelTo(now()->addDays(30)->endOfDay()->subMinute());
        $this->ouvrir($lien)->assertOk();

        // … et plus une minute après.
        $this->travelTo(now()->addMinutes(2));
        $this->assertRefusSobre($this->ouvrir($lien));
    }

    public function test_la_duree_du_lien_se_regle(): void
    {
        config(['communications.lien_document.duree_jours' => 7]);

        $data = $this->withToken($this->atlas['token'])
            ->getJson("/api/v1/ventes/documents/{$this->facture['id']}/whatsapp?type=facture")->json('data');

        $this->assertSame(now()->addDays(7)->endOfDay()->toIso8601String(), $data['lien_document']['expire_le']);
        $this->assertStringContainsString('jusqu\'au '.now()->addDays(7)->format('d/m/Y'), $data['messages']['fr']);
    }

    public function test_un_lien_altere_est_refuse(): void
    {
        $lien = $this->lien($this->facture['id']);
        $autre = $this->piece($this->atlas['token'], $this->facture['tiers_id']);

        // L'identifiant changé : la signature ne couvre plus ce chemin.
        $this->assertRefusSobre($this->ouvrir(str_replace(
            "/documents/{$this->facture['id']}/pdf", "/documents/{$autre['id']}/pdf", $lien,
        )));

        // L'échéance repoussée.
        $this->assertRefusSobre($this->ouvrir(preg_replace('/expires=(\d+)/', 'expires=9999999999', $lien)));

        // La signature retirée, ou abîmée.
        $this->assertRefusSobre($this->ouvrir(preg_replace('/&signature=[0-9a-f]+/', '', $lien)));
        $this->assertRefusSobre($this->ouvrir(preg_replace_callback(
            '/signature=([0-9a-f])/',
            fn (array $m) => 'signature='.($m[1] === '0' ? '1' : '0'),
            $lien,
        )));

        // Un paramètre ajouté.
        $this->assertRefusSobre($this->ouvrir($lien.'&document=1'));

        // Un lien sans date d'expiration, même correctement signé, n'est pas
        // de ceux que l'application émet : il serait valable pour toujours.
        $perpetuel = URL::signedRoute(LienPublicDocument::ROUTE, ['document' => $this->facture['id']], absolute: false);
        $this->assertRefusSobre($this->ouvrir($perpetuel));
    }

    public function test_un_parametre_de_pistage_ne_casse_pas_le_lien(): void
    {
        $lien = $this->lien($this->facture['id']);

        // Un lien re-partagé depuis Facebook revient avec `fbclid`.
        $this->ouvrir($lien.'&fbclid=IwAR0abc&utm_source=whatsapp')->assertOk();
    }

    public function test_le_document_d_une_autre_entreprise_ne_se_forge_pas(): void
    {
        $concurrent = $this->entreprise('Concurrent', 'patron@concurrent.ma');
        $clientAutre = $this->client($concurrent['token'], 'Client Y');
        $factureAutre = $this->piece($concurrent['token'], $clientAutre);

        // Le concurrent ne peut pas obtenir de lien pour la pièce d'Atlas…
        $this->withToken($concurrent['token'])
            ->getJson("/api/v1/ventes/documents/{$this->facture['id']}/whatsapp?type=facture")->assertNotFound();

        // … ni détourner le sien vers elle : la signature ne suit pas.
        $sien = $this->withToken($concurrent['token'])
            ->getJson("/api/v1/ventes/documents/{$factureAutre['id']}/whatsapp?type=facture")
            ->json('data.lien_document.url');
        $this->ouvrir($sien)->assertOk();
        $this->assertRefusSobre($this->ouvrir(str_replace(
            "/documents/{$factureAutre['id']}/pdf", "/documents/{$this->facture['id']}/pdf", $sien,
        )));
    }

    public function test_la_signature_resiste_au_proxy_http_et_a_un_autre_hote(): void
    {
        // Le lien est produit sur l'adresse publique, en https…
        $lien = $this->lien($this->facture['id'], 'https://crm.exemple.ma');
        $this->assertStringStartsWith('https://crm.exemple.ma/api/v1/public/documents/', $lien);

        // … et la requête arrive à l'application en http, sur une adresse
        // interne, comme derrière nginx : la signature relative tient.
        $this->ouvrir('http://10.0.0.7:8080'.$this->chemin($lien))->assertOk();
    }

    public function test_en_production_le_lien_vise_app_url_et_jamais_l_hote_de_la_requete(): void
    {
        config(['app.url' => 'https://crm.mediadesk.ma']);
        $this->app['env'] = 'production';

        try {
            // Un en-tête Host forgé ou une adresse interne ne doivent pas
            // finir dans le message envoyé au client.
            $lien = $this->lien($this->facture['id'], 'http://interne.local:8080');
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertStringStartsWith("https://crm.mediadesk.ma/api/v1/public/documents/{$this->facture['id']}/pdf?expires=", $lien);
        $this->ouvrir('http://interne.local:8080'.$this->chemin($lien))->assertOk();
    }

    public function test_une_entreprise_suspendue_ne_sert_plus_ses_liens(): void
    {
        $lien = $this->lien($this->facture['id']);

        Tenant::whereKey($this->atlas['tenant_id'])->update(['suspended_at' => now()]);

        $this->assertRefusSobre($this->ouvrir($lien));
    }

    public function test_un_brouillon_ou_une_piece_non_partageable_est_refuse_meme_signe(): void
    {
        $brouillon = $this->piece($this->atlas['token'], $this->facture['tiers_id'], valider: false);
        $commande = $this->piece($this->atlas['token'], $this->facture['tiers_id'], 'commande');

        // Signés à la main, avec la vraie clé : seule la nature de la pièce
        // les fait refuser.
        foreach ([$brouillon['id'], $commande['id'], 999999] as $id) {
            $signe = URL::temporarySignedRoute(LienPublicDocument::ROUTE, now()->addDay(), ['document' => $id], absolute: false);
            $this->assertRefusSobre($this->ouvrir($signe));
        }
    }

    public function test_un_identifiant_non_numerique_repond_404(): void
    {
        $this->ouvrir('/api/v1/public/documents/abc/pdf')->assertNotFound();
    }

    public function test_le_lien_public_a_son_limiteur_nomme(): void
    {
        $this->assertNotNull(RateLimiter::limiter('lien-document-public'));
        $this->assertContains(
            'throttle:lien-document-public',
            Route::getRoutes()->getByName(LienPublicDocument::ROUTE)->gatherMiddleware(),
        );

        // Vingt ouvertures dans la minute depuis une adresse, puis le refus —
        // en texte, lisible par le client sur son téléphone.
        $faux = "/api/v1/public/documents/{$this->facture['id']}/pdf?expires=9999999999&signature=faux";
        for ($i = 0; $i < 20; $i++) {
            $this->ouvrir($faux)->assertForbidden();
        }

        $refus = $this->ouvrir($faux);
        $refus->assertStatus(429);
        $this->assertStringStartsWith('text/plain', $refus->headers->get('Content-Type'));
        $this->assertStringContainsString('Trop de demandes', $refus->getContent());
        $this->assertNotNull($refus->headers->get('Retry-After'));
    }

    public function test_une_piece_ne_se_rend_pas_sans_fin_depuis_des_adresses_tournantes(): void
    {
        // Le rendu réel est remplacé par un PDF minuscule : c'est le compteur
        // qu'on éprouve ici, pas dompdf, trente et une fois.
        $this->app->bind(DocumentPdfService::class, fn () => new class extends DocumentPdfService
        {
            public function rendre(DocumentVente $document): PdfDocument
            {
                return app('dompdf.wrapper')->loadHTML('<p>x</p>');
            }
        });

        $lien = $this->lien($this->facture['id']);
        $autre = $this->piece($this->atlas['token'], $this->facture['tiers_id']);
        $lienAutre = $this->lien($autre['id']);

        // Des signatures fausses, depuis une seule adresse : elles ne comptent
        // pas contre la pièce — un tiers ne ferme pas la facture d'un client
        // sans détenir son lien.
        $faux = "/api/v1/public/documents/{$this->facture['id']}/pdf?expires=9999999999&signature=faux";
        for ($i = 0; $i < 15; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200'])->ouvrir($faux)->assertForbidden();
        }

        // Le même lien valable, rejoué depuis trente adresses différentes :
        // chacune reste loin de ses propres plafonds, la pièce atteint le sien.
        for ($i = 1; $i <= 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])->ouvrir($lien)->assertOk();
        }

        $refus = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->ouvrir($lien);
        $refus->assertStatus(429);
        $this->assertStringStartsWith('text/plain', $refus->headers->get('Content-Type'));
        $this->assertNotNull($refus->headers->get('Retry-After'));

        // Une autre pièce garde son propre compteur.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->ouvrir($lienAutre)->assertOk();
    }
}
