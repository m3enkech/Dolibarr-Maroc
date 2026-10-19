<?php

namespace Tests\Concerns;

use App\Models\User;

/**
 * Décor commun aux tests des messages WhatsApp : une entreprise, un client et
 * ses contacts, des pièces validées — montés par les VRAIES routes, pour que
 * le cloisonnement entre entreprises soit celui de la production.
 */
trait MonteEnvoisWhatsApp
{
    /** @return array{token: string, tenant_id: int, user_id: int} */
    protected function entreprise(string $nom, string $email): array
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'company_name' => $nom, 'name' => 'Patron '.$nom, 'email' => $email, 'password' => 'password123',
        ])->assertCreated();

        return ['token' => $r->json('token'), 'tenant_id' => $r->json('tenant.id'), 'user_id' => $r->json('user.id')];
    }

    protected function jetonPourRole(int $tenantId, string $role): string
    {
        return User::factory()->create(['tenant_id' => $tenantId, 'role' => $role, 'is_active' => true])
            ->createToken('spa')->plainTextToken;
    }

    protected function client(string $token, string $nom = 'Client X', ?string $telephone = null): int
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', ['name' => $nom, 'phone' => $telephone])
            ->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $donnees */
    protected function contact(string $token, int $tiersId, array $donnees): int
    {
        return $this->withToken($token)->postJson("/api/v1/tiers/{$tiersId}/contacts", $donnees)
            ->assertCreated()->json('data.id');
    }

    /** Une pièce de vente (1 000 HT, TVA 20 % : 1 200 TTC), validée par défaut. */
    protected function piece(
        string $token,
        int $tiersId,
        string $type = 'facture',
        ?string $date = null,
        ?string $echeance = null,
        float $ht = 1000,
        bool $valider = true,
    ): array {
        $piece = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => $type,
            'tiers_id' => $tiersId,
            'date_document' => $date ?? now()->toDateString(),
            'date_echeance' => $echeance,
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => $ht, 'tva_rate' => 20]],
        ])->assertCreated()->json('data');

        if ($valider) {
            $piece = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$piece['id']}/valider")
                ->assertOk()->json('data');
        }

        return $piece;
    }

    /** Une facture échue depuis $joursRetard jours. */
    protected function factureEchue(string $token, int $tiersId, int $joursRetard = 30): array
    {
        return $this->piece($token, $tiersId, 'facture', now()->subDays($joursRetard + 30)->toDateString(), now()->subDays($joursRetard)->toDateString());
    }

    /** L'id du paiement créé. */
    protected function paiement(string $token, int $documentId, float $montant, string $mode = 'virement', ?string $reference = null): int
    {
        $paiements = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$documentId}/paiements", [
            'montant' => $montant, 'mode' => $mode, 'reference' => $reference, 'date_paiement' => now()->toDateString(),
        ])->assertOk()->json('data.paiements');

        return (int) collect($paiements)->sortByDesc('id')->first()['id'];
    }

    /** « 1 200,00 MAD » avec ses espaces insécables, comme dans les messages. */
    protected function mad(string $montant, string $unite = 'MAD'): string
    {
        return str_replace(' ', "\u{00A0}", $montant)."\u{00A0}".$unite;
    }
}
