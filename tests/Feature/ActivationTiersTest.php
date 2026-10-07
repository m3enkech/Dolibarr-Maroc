<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le menu « Plus » de la fiche tiers : désactiver, réactiver, convertir,
 * supprimer.
 *
 * « Désactiver » n'a pas de route à lui : la fiche envoie la mise à jour
 * existante avec `is_active` SEUL. Ce qui suit verrouille ce contrat — une
 * mise à jour partielle qui remettrait à vide les champs absents effacerait
 * l'ICE et l'adresse d'un client pour l'avoir simplement mis en sommeil.
 */
class ActivationTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private int $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $inscription = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        $this->jeton = $inscription->json('token');
        $this->entreprise = $inscription->json('tenant.id');
    }

    /** @return array<string, mixed> */
    private function client(array $attributs = []): array
    {
        return $this->withToken($this->jeton)->postJson('/api/v1/tiers', array_merge([
            'name' => 'CLIM & COOL', 'is_client' => true, 'is_supplier' => false,
            'ice' => '001234567000089', 'address' => '12 rue Allal Ben Abdellah',
            'city' => 'Casablanca', 'phone' => '+212522000000', 'plafond_credit' => 50000,
        ], $attributs))->assertCreated()->json('data');
    }

    private function jetonDuRole(string $role): string
    {
        return User::factory()->create([
            'tenant_id' => $this->entreprise, 'role' => $role, 'is_active' => true,
        ])->createToken('spa')->plainTextToken;
    }

    /** @return list<int> */
    private function idsListes(string $actif): array
    {
        return array_column(
            $this->withToken($this->jeton)->getJson("/api/v1/tiers?actif={$actif}")->assertOk()->json('data'),
            'id',
        );
    }

    public function test_desactiver_ne_change_que_l_activation(): void
    {
        // Relu et non pris à la création : la réponse de `store` ne porte pas
        // les défauts posés par la base (`is_prospect` y vaut null).
        $client = $this->withToken($this->jeton)
            ->getJson('/api/v1/tiers/'.$this->client()['id'])
            ->assertOk()
            ->json('data');

        $maj = $this->withToken($this->jeton)
            ->putJson("/api/v1/tiers/{$client['id']}", ['is_active' => false])
            ->assertOk()
            ->json('data');

        $this->assertFalse($maj['is_active']);
        // Tout le reste est intact : code, rôles, identifiants, crédit.
        foreach (['code', 'name', 'is_client', 'is_supplier', 'is_prospect', 'ice', 'address', 'city', 'phone', 'plafond_credit'] as $champ) {
            $this->assertSame($client[$champ], $maj[$champ], "Le champ {$champ} ne doit pas bouger.");
        }

        // Il sort des listes filtrées sur les actifs, sans disparaître des autres.
        $this->assertNotContains($client['id'], $this->idsListes('1'));
        $this->assertContains($client['id'], $this->idsListes('0'));
        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client['id']}")->assertOk();
    }

    public function test_reactiver_le_remet_parmi_les_actifs(): void
    {
        $client = $this->client(['is_active' => false]);

        $this->withToken($this->jeton)
            ->putJson("/api/v1/tiers/{$client['id']}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.ice', $client['ice']);

        $this->assertContains($client['id'], $this->idsListes('1'));
    }

    public function test_une_activation_qui_n_est_pas_un_booleen_est_refusee(): void
    {
        $client = $this->client();

        $this->withToken($this->jeton)
            ->putJson("/api/v1/tiers/{$client['id']}", ['is_active' => 'peut-etre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    /**
     * La fiche masque le menu « Plus » aux rôles qui ne font que lire les
     * tiers ; le serveur, lui, doit refuser quand même — un menu masqué n'a
     * jamais protégé une route.
     */
    public function test_les_roles_en_lecture_ne_peuvent_ni_desactiver_ni_convertir_ni_supprimer(): void
    {
        $prospect = $this->client(['name' => 'Futur client', 'is_prospect' => true]);

        foreach (['comptable', 'caissier', 'lecture'] as $role) {
            $jeton = $this->jetonDuRole($role);

            $this->withToken($jeton)->putJson("/api/v1/tiers/{$prospect['id']}", ['is_active' => false])
                ->assertForbidden("Le rôle {$role} ne doit pas pouvoir désactiver.");
            $this->withToken($jeton)->postJson("/api/v1/tiers/{$prospect['id']}/convertir")
                ->assertForbidden("Le rôle {$role} ne doit pas pouvoir convertir.");
            $this->withToken($jeton)->deleteJson("/api/v1/tiers/{$prospect['id']}")
                ->assertForbidden("Le rôle {$role} ne doit pas pouvoir supprimer.");
        }

        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$prospect['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_prospect', true);

        // Le commercial, lui, écrit sur les tiers : il convertit son prospect.
        $this->withToken($this->jetonDuRole('commercial'))->postJson("/api/v1/tiers/{$prospect['id']}/convertir")
            ->assertOk()
            ->assertJsonPath('data.is_prospect', false)
            ->assertJsonPath('data.is_client', true);
    }

    /**
     * « Supprimer » voisine avec « Désactiver » dans le menu : un client qui a
     * des pièces ne doit pas pouvoir être effacé — ses factures perdaient leur
     * tiers, leur PDF tombait en erreur 500 et l'impayé sortait de la liste.
     * Le refus dit quoi faire à la place, dans la langue de l'écran.
     */
    public function test_un_tiers_qui_a_des_pieces_de_vente_ne_se_supprime_pas(): void
    {
        $client = $this->client();

        $this->withToken($this->jeton)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'Étude', 'quantite' => 1, 'prix_unitaire' => 500, 'tva_rate' => 20]],
        ])->assertSuccessful();

        $this->withToken($this->jeton)->deleteJson("/api/v1/tiers/{$client['id']}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Ce tiers a des pièces ou des écritures : désactivez-le plutôt.');

        $this->withToken($this->jeton)->withHeader('Accept-Language', 'ar')
            ->deleteJson("/api/v1/tiers/{$client['id']}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لهذا الزبون أو المورد وثائق أو قيود محاسبية: قم بتعطيله بدلًا من حذفه.');

        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client['id']}")->assertOk();
        $this->assertContains($client['id'], $this->idsListes(''));
    }

    public function test_un_fournisseur_qui_a_des_achats_ne_se_supprime_pas(): void
    {
        $fournisseur = $this->client(['name' => 'Konica Maroc', 'is_client' => false, 'is_supplier' => true]);

        $this->withToken($this->jeton)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $fournisseur['id'],
            'lignes' => [['designation' => 'Toner', 'quantite' => 1, 'prix_unitaire' => 10, 'tva_rate' => 20]],
        ])->assertSuccessful();

        $this->withToken($this->jeton)->deleteJson("/api/v1/tiers/{$fournisseur['id']}")->assertUnprocessable();
        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$fournisseur['id']}")->assertOk();
    }

    /**
     * Sans trace, la suppression passe — y compris quand la seule pièce était
     * un brouillon supprimé depuis : personne ne le voit plus, refuser en son
     * nom ne s'expliquerait pas.
     */
    public function test_un_tiers_sans_piece_se_supprime(): void
    {
        $client = $this->client();

        $brouillon = $this->withToken($this->jeton)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'Étude', 'quantite' => 1, 'prix_unitaire' => 500, 'tva_rate' => 20]],
        ])->assertSuccessful()->json('data');
        $this->withToken($this->jeton)->deleteJson("/api/v1/ventes/documents/{$brouillon['id']}")->assertOk();

        $this->withToken($this->jeton)->deleteJson("/api/v1/tiers/{$client['id']}")->assertOk();
        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client['id']}")->assertNotFound();
    }

    public function test_un_tiers_d_une_autre_entreprise_ne_se_desactive_pas(): void
    {
        $client = $this->client();

        $autre = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Beta', 'name' => 'Admin', 'email' => 'beta@test.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');

        $this->withToken($autre)->putJson("/api/v1/tiers/{$client['id']}", ['is_active' => false])->assertNotFound();

        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }
}
