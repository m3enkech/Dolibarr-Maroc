<?php

namespace Tests\Concerns;

/**
 * Décor commun aux tests du portail : un grossiste, un acheteur, et le
 * rattachement approuvé qui relie les deux.
 *
 * Monté par HTTP à travers les vraies routes plutôt qu'en base : c'est
 * précisément l'étanchéité de ces routes que les tests du portail mesurent.
 */
trait MontePortail
{
    /** @return array{token: string, slug: string} */
    protected function grossiste(string $company, string $email): array
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company, 'name' => 'Patron', 'email' => $email, 'password' => 'password123',
        ])->assertCreated();

        return ['token' => $r->json('token'), 'slug' => $r->json('tenant.slug')];
    }

    protected function acheteur(string $email, string $nom = 'Épicerie'): string
    {
        return $this->postJson('/api/portail/v1/auth/inscription', [
            'name' => $nom, 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    /**
     * Rattache l'acheteur au grossiste et renvoie l'id du compte client créé
     * chez lui. La demande est retrouvée par l'EMAIL de l'acheteur, jamais par
     * sa position dans la liste : deux demandes déposées dans la même seconde
     * rendraient le tri ambigu.
     */
    protected function rattacher(string $tokenAcheteur, array $g, string $email, ?int $tiersId = null): int
    {
        $this->withToken($tokenAcheteur)
            ->postJson('/api/portail/v1/demander-acces', ['slug' => $g['slug']])->assertCreated();

        $demande = collect($this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')->json('data'))
            ->firstWhere('acheteur.email', $email);

        $this->assertNotNull($demande, "Demande introuvable pour {$email}.");

        $this->withToken($g['token'])
            ->postJson("/api/v1/portail/adhesions/{$demande['id']}/approuver", ['tiers_id' => $tiersId])
            ->assertOk();

        return collect($this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')->json('data'))
            ->firstWhere('acheteur.email', $email)['client']['id'];
    }
}
