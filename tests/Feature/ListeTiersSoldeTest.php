<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\EncoursService;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La liste des tiers montre ce que chaque client doit — ou ce qu'on lui doit.
 *
 * Même définition que l'encours de la caisse et du portail (grand livre,
 * comptes clients + effets à recevoir, hors à-nouveaux) mais SIGNÉE : un
 * client créditeur affiché « 0,00 » serait relancé pour rien et jamais
 * remboursé. Le calcul tient en un agrégat par page, quelle que soit sa taille.
 */
class ListeTiersSoldeTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jeton = $this->inscrire('Media Desk', 'admin@mediadesk.ma');
        $this->entreprise = User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant;
        $this->dansEntreprise($this->entreprise);
    }

    private function inscrire(string $societe, string $email): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => $societe, 'name' => 'Admin', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    /**
     * Chaque appel HTTP laisse derrière lui le contexte de l'entreprise du
     * jeton utilisé : on le repose explicitement avant de travailler par les
     * services, pour ne jamais écrire chez la mauvaise.
     */
    private function dansEntreprise(Tenant $entreprise): void
    {
        app(TenantContext::class)->set($entreprise);
    }

    private function tiers(string $nom, array $attributs = []): Tiers
    {
        return app(TiersService::class)->create(array_merge([
            'name' => $nom, 'is_client' => true, 'is_supplier' => false,
        ], $attributs));
    }

    /** Pièce validée d'une ligne : prix HT + 20 % de TVA. */
    private function piece(Tiers $tiers, string $type, float $prixHt): DocumentVente
    {
        $document = app(VenteService::class)->create([
            'type' => $type,
            'tiers_id' => $tiers->id,
            'date_document' => now()->toDateString(),
            'lignes' => [[
                'designation' => 'Maintenance copieurs', 'quantite' => 1,
                'prix_unitaire' => $prixHt, 'tva_rate' => 20,
            ]],
        ]);

        return app(VenteService::class)->valider($document);
    }

    /** @return array<int, array<string, mixed>> lignes de la liste, indexées par id */
    private function liste(string $parametres = '', ?string $jeton = null): array
    {
        return collect(
            $this->withToken($jeton ?? $this->jeton)
                ->getJson('/api/v1/tiers?avec_solde=1&per_page=100'.$parametres)
                ->assertOk()
                ->json('data'),
        )->keyBy('id')->all();
    }

    private function compterRequetes(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($this->jeton)->getJson($url)->assertOk();

        $nombre = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $nombre;
    }

    /* ---------------------------------------------------------------- */

    public function test_le_solde_suit_facture_reglement_partiel_puis_avoir_jusqu_au_negatif(): void
    {
        $client = $this->tiers('CLIM & COOL');

        $facture = $this->piece($client, DocumentVente::TYPE_FACTURE, 200); // 240 TTC au débit
        $this->assertSame('240.00', $this->liste()[$client->id]['solde']);

        $this->dansEntreprise($this->entreprise);
        app(VenteService::class)->ajouterPaiement($facture, ['montant' => 90, 'mode' => 'virement']);
        $this->assertSame('150.00', $this->liste()[$client->id]['solde']);

        // Un avoir de 240 TTC passe le compte au crédit : c'est nous qui devons 90.
        $this->dansEntreprise($this->entreprise);
        $this->piece($client, DocumentVente::TYPE_AVOIR, 200);
        $this->assertSame('-90.00', $this->liste()[$client->id]['solde']);

        // Les appelants historiques (plafond de crédit, caisse, portail) gardent
        // leur plancher à zéro : la variante signée ne leur a rien changé.
        $this->dansEntreprise($this->entreprise);
        $this->assertSame(0.0, app(EncoursService::class)->pour($client));
        $this->assertSame([$client->id => 0.0], app(EncoursService::class)->parTiers([$client->id]));
        $this->assertSame([$client->id => -90.0], app(EncoursService::class)->soldesSignes([$client->id]));
    }

    public function test_le_journal_des_a_nouveaux_n_est_pas_compte(): void
    {
        $client = $this->tiers('CLIM & COOL');
        $this->piece($client, DocumentVente::TYPE_FACTURE, 100); // 120 TTC

        // Une reprise d'à-nouveaux portant le tiers : la clôture reporte déjà
        // ce solde en ligne agrégée, le compter ici le doublerait.
        $compta = app(ComptaService::class);
        $compta->ecrire('AN', now()->toDateString(), 'Reprise', [
            ['compte' => $compta->compteParDefaut('clients'), 'debit' => 500, 'credit' => 0, 'tiers_id' => $client->id],
            ['compte' => $compta->compteParDefaut('banque'), 'debit' => 0, 'credit' => 500],
        ]);

        $this->assertSame('120.00', $this->liste()[$client->id]['solde']);
    }

    public function test_sans_objet_pour_un_fournisseur_pur_ou_un_prospect_sans_ecriture(): void
    {
        $client = $this->tiers('Client sans pièce');
        $fournisseur = $this->tiers('Fournisseur pur', ['is_client' => false, 'is_supplier' => true]);
        $prospect = $this->tiers('Prospect', ['is_prospect' => true]);

        $liste = $this->liste();

        $this->assertSame('0.00', $liste[$client->id]['solde'], 'Un client sans écriture est réellement à zéro.');
        $this->assertNull($liste[$fournisseur->id]['solde']);
        $this->assertNull($liste[$prospect->id]['solde']);
    }

    public function test_une_dette_au_compte_clients_s_affiche_meme_sans_la_case_client(): void
    {
        // Rien n'interdit de facturer un tiers enregistré comme fournisseur :
        // la vente ne contrôle que son existence.
        $fournisseur = $this->tiers('Fournisseur facturé', ['is_client' => false, 'is_supplier' => true]);
        $this->piece($fournisseur, DocumentVente::TYPE_FACTURE, 200); // 240 TTC au débit

        // Ni de décocher « client » sur quelqu'un qui doit encore.
        $this->dansEntreprise($this->entreprise);
        $ancienClient = $this->tiers('Ancien client');
        $this->piece($ancienClient, DocumentVente::TYPE_FACTURE, 100); // 120 TTC
        $ancienClient->update(['is_client' => false, 'is_supplier' => true]);

        $liste = $this->liste();
        $this->assertSame('240.00', $liste[$fournisseur->id]['solde']);
        $this->assertSame('120.00', $liste[$ancienClient->id]['solde']);

        // La même définition que l'encours du tiers : les deux écrans s'accordent.
        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$fournisseur->id}/encours")
            ->assertOk()->assertJsonPath('data.encours', '240.00');
    }

    /**
     * Une traite tirée fait passer la créance de 3421 à 3425 et LETTRE la
     * facture : sans le compte des effets dans le périmètre, le client qui
     * n'a pas encore payé sa traite s'afficherait à jour.
     */
    public function test_la_creance_passee_en_effet_reste_due_jusqu_a_l_encaissement(): void
    {
        $this->withToken($this->jeton)
            ->putJson('/api/v1/parametres', ['features' => ['effets' => true]])->assertOk();

        $this->dansEntreprise($this->entreprise);
        $client = $this->tiers('CLIM & COOL');
        $facture = $this->piece($client, DocumentVente::TYPE_FACTURE, 200); // 240 TTC
        $this->assertSame('240.00', $this->liste()[$client->id]['solde']);

        $tirer = fn (DocumentVente $f) => $this->withToken($this->jeton)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $f->id, 'date_echeance' => now()->addDays(60)->toDateString(),
        ])->assertCreated()->json('data.id');

        $effet = $tirer($facture);
        $this->assertSame('240.00', $this->liste()[$client->id]['solde'], 'La traite n\'est pas encore encaissée.');

        $this->withToken($this->jeton)->postJson("/api/v1/effets/{$effet}/encaisser")->assertOk();
        $this->assertSame('0.00', $this->liste()[$client->id]['solde']);

        // Une seconde traite, revenue impayée : la créance retourne au 3421.
        $this->dansEntreprise($this->entreprise);
        $autre = $tirer($this->piece($client, DocumentVente::TYPE_FACTURE, 200));
        $this->assertSame('240.00', $this->liste()[$client->id]['solde']);

        $this->withToken($this->jeton)->postJson("/api/v1/effets/{$autre}/impaye")->assertOk();
        $this->assertSame('240.00', $this->liste()[$client->id]['solde']);
    }

    /**
     * Le caissier (tiers en lecture, ventes fermées) lit le solde : c'est
     * l'encours que la caisse lui montre pour vendre à crédit, et la fiche
     * le lui répète dans son compte client. Une seule politique, la même sur
     * la liste, la fiche et la caisse — ce qui lui reste caché, c'est le
     * chiffre d'affaires (voir VueEnsembleTiersTest).
     */
    public function test_le_caissier_lit_le_meme_solde_que_la_fiche_et_la_caisse(): void
    {
        $client = $this->tiers('CLIM & COOL');
        $this->piece($client, DocumentVente::TYPE_FACTURE, 200); // 240 TTC

        $caissier = User::factory()->create([
            'tenant_id' => $this->entreprise->id, 'role' => 'caissier', 'is_active' => true,
        ])->createToken('spa')->plainTextToken;

        $this->assertSame('240.00', $this->liste(jeton: $caissier)[$client->id]['solde']);
        $this->withToken($caissier)->getJson("/api/v1/tiers/{$client->id}/vue-ensemble")
            ->assertOk()->assertJsonPath('data.compte.creances', '240.00')->assertJsonMissingPath('data.revenus');
        $this->withToken($caissier)->getJson("/api/v1/tiers/{$client->id}/encours")
            ->assertOk()->assertJsonPath('data.encours', '240.00');
    }

    public function test_le_solde_n_est_calcule_que_sur_demande(): void
    {
        $this->tiers('CLIM & COOL');

        $this->withToken($this->jeton)->getJson('/api/v1/tiers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.solde');
    }

    public function test_le_nombre_de_requetes_ne_depend_pas_de_la_taille_de_la_page(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->dansEntreprise($this->entreprise);
            $this->piece($this->tiers(sprintf('Client %02d', $i)), DocumentVente::TYPE_FACTURE, 100);
        }

        // Premier passage à blanc : caches de permissions, plan comptable…
        $this->compterRequetes('/api/v1/tiers?avec_solde=1&per_page=2');

        $unSeul = $this->compterRequetes('/api/v1/tiers?avec_solde=1&per_page=1');
        $petitePage = $this->compterRequetes('/api/v1/tiers?avec_solde=1&per_page=2');
        $grandePage = $this->compterRequetes('/api/v1/tiers?avec_solde=1&per_page=12');

        $this->assertSame($unSeul, $petitePage, 'Une requête par tiers affiché : le N+1 est revenu.');
        $this->assertSame($petitePage, $grandePage, 'Une requête par tiers affiché : le N+1 est revenu.');

        // Et la grande page a bien reçu un solde pour chacun.
        $soldes = array_column($this->liste('&per_page=12'), 'solde');
        $this->assertSame(array_fill(0, 12, '120.00'), $soldes);
    }

    public function test_aucune_ecriture_d_une_autre_entreprise_ne_se_mele_au_solde(): void
    {
        $client = $this->tiers('CLIM & COOL');
        $this->piece($client, DocumentVente::TYPE_FACTURE, 200); // 240 TTC
        $compteClientsA = app(ComptaService::class)->compteParDefaut('clients')->id;

        $jetonB = $this->inscrire('Autre Société', 'admin@autre.ma');
        $entrepriseB = User::withoutGlobalScopes()->firstWhere('email', 'admin@autre.ma')->tenant;
        $this->dansEntreprise($entrepriseB);
        $clientB = $this->tiers('Client de B');
        $this->piece($clientB, DocumentVente::TYPE_FACTURE, 1000); // 1 200 TTC

        // Une écriture de B qui viserait le tiers ET le compte de A — ce que
        // seule une corruption ou un import fautif produirait. Le filtre
        // d'entreprise passe par l'écriture parente : c'est lui qu'on éprouve.
        $ecriture = DB::table('ecritures')->insertGetId([
            'tenant_id' => $entrepriseB->id, 'journal' => 'OD', 'numero' => 'OD-INTRUS-1',
            'date_ecriture' => now()->toDateString(), 'libelle' => 'Intrus', 'is_auto' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ecriture_lignes')->insert([
            'ecriture_id' => $ecriture, 'compte_id' => $compteClientsA, 'tiers_id' => $client->id,
            'debit' => 5000, 'credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $listeA = $this->liste();
        $this->assertSame([$client->id], array_keys($listeA));
        $this->assertSame('240.00', $listeA[$client->id]['solde']);

        $listeB = $this->liste(jeton: $jetonB);
        $this->assertSame([$clientB->id], array_keys($listeB));
        $this->assertSame('1200.00', $listeB[$clientB->id]['solde']);
    }

    public function test_per_page_est_borne(): void
    {
        $this->tiers('CLIM & COOL');
        $this->tiers('Copieurs du Nord');

        $this->withToken($this->jeton)->getJson('/api/v1/tiers?per_page=100000')
            ->assertOk()->assertJsonPath('meta.per_page', 500);

        // Laravel ignore une limite négative : sans borne basse, `-1` rendait
        // toute la table, plafond contourné. Nulle, négative ou illisible, la
        // taille retombe sur le défaut d'Eloquent — pas sur une ligne par page.
        foreach (['-1', '0', 'abc'] as $taille) {
            $this->withToken($this->jeton)->getJson("/api/v1/tiers?per_page={$taille}")
                ->assertOk()->assertJsonPath('meta.per_page', 15)->assertJsonCount(2, 'data');
        }

        // La caisse lit son annuaire par pages de 500 : elle doit les obtenir.
        $this->withToken($this->jeton)->getJson('/api/v1/tiers?per_page=500')
            ->assertOk()->assertJsonPath('meta.per_page', 500);
    }

    public function test_filtre_actif(): void
    {
        $actif = $this->tiers('Client actif');
        $inactif = $this->tiers('Ancien client', ['is_active' => false]);

        $this->assertSame([$actif->id], array_keys($this->liste('&actif=1')));
        $this->assertSame([$inactif->id], array_keys($this->liste('&actif=0')));

        // Absent ou vide : tout le monde — un ancien client peut encore devoir.
        $this->assertEqualsCanonicalizing([$actif->id, $inactif->id], array_keys($this->liste()));
        $this->assertEqualsCanonicalizing([$actif->id, $inactif->id], array_keys($this->liste('&actif=')));
    }
}
