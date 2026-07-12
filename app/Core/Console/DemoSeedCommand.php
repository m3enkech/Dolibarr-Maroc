<?php

namespace App\Core\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Catalogue\Services\ProduitService;
use App\Modules\Stock\Services\StockService;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Console\Command;

/**
 * Insère un jeu de données de démonstration réaliste dans le tenant de
 * l'utilisateur donné (tiers, catalogue + stock, factures validées réparties
 * sur l'année avec paiements) — de quoi exercer catalogue, stock, comptabilité
 * et tableau de bord.
 *
 *   php artisan demo:seed client@exemple.ma
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {email} {--force : Ajoute les données même si l\'entreprise en a déjà}';

    protected $description = 'Insère des données de démonstration dans le tenant de l\'utilisateur donné';

    public function handle(
        TenantContext $context,
        TiersService $tiersSvc,
        ProduitService $produitSvc,
        StockService $stockSvc,
        VenteService $venteSvc,
    ): int {
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null || $user->tenant === null) {
            $this->error("Utilisateur ou entreprise introuvable pour : {$this->argument('email')}");

            return self::FAILURE;
        }

        $tenant = $user->tenant;
        $context->set($tenant); // active le scope tenant pour toute la commande

        if (DocumentVente::query()->exists() && ! $this->option('force')) {
            $this->warn("L'entreprise « {$tenant->name} » contient déjà des ventes — seed ignoré (utilisez --force pour ajouter quand même).");

            return self::SUCCESS;
        }

        // 1) Tiers ---------------------------------------------------------
        $clients = collect(['Marjane Retail', 'Café Atlas', 'BTP Souss SARL', 'Pharmacie Al Amal'])
            ->map(fn ($n) => $tiersSvc->create([
                'name' => $n, 'is_client' => true, 'is_supplier' => false,
                'city' => 'Casablanca', 'country' => 'MA',
            ]))->all();

        $fournisseurs = collect(['Distrib Nord SARL', 'Import Med'])
            ->map(fn ($n) => $tiersSvc->create([
                'name' => $n, 'is_client' => false, 'is_supplier' => true,
                'city' => 'Tanger', 'country' => 'MA',
            ]))->all();

        // 2) Catalogue + stock initial ------------------------------------
        $entrepot = $stockSvc->entrepotParDefaut();
        $catalogue = [
            ['name' => 'Ordinateur portable', 'type' => 'product', 'sell_price' => 7500, 'tva_rate' => 20, 'stock_min' => 5],
            ['name' => 'Souris sans fil', 'type' => 'product', 'sell_price' => 150, 'tva_rate' => 20, 'stock_min' => 20],
            ['name' => 'Imprimante laser', 'type' => 'product', 'sell_price' => 2200, 'tva_rate' => 20, 'stock_min' => 3],
            ['name' => 'Cartouche toner', 'type' => 'product', 'sell_price' => 650, 'tva_rate' => 20, 'stock_min' => 10],
            ['name' => 'Prestation installation', 'type' => 'service', 'sell_price' => 1200, 'tva_rate' => 20],
            ['name' => 'Contrat maintenance', 'type' => 'service', 'sell_price' => 3000, 'tva_rate' => 20],
        ];
        $produits = [];
        foreach ($catalogue as $p) {
            $prod = $produitSvc->create($p);
            $produits[$prod->name] = $prod;
            if ($prod->type === 'product') {
                $stockSvc->entree($prod, $entrepot, 50, 'Stock initial démo');
            }
        }

        // 3) Factures validées réparties sur l'année + paiements ----------
        // [index client, mois écoulés, [[produit, qté], …], payée ?]
        $plan = [
            [0, 0, [['Ordinateur portable', 2], ['Souris sans fil', 5]], true],
            [1, 1, [['Contrat maintenance', 1]], true],
            [2, 2, [['Imprimante laser', 3], ['Cartouche toner', 6]], false],
            [0, 3, [['Prestation installation', 2]], true],
            [3, 5, [['Ordinateur portable', 1], ['Cartouche toner', 4]], false],
            [1, 7, [['Souris sans fil', 10]], true],
        ];
        $modes = ['virement', 'cheque', 'especes'];
        $nbFactures = 0;
        $nbPaye = 0;

        foreach ($plan as [$ci, $mois, $items, $paye]) {
            $base = now()->subMonthsNoOverflow($mois)->startOfMonth()->addDays(9);
            $lignes = array_map(function ($it) use ($produits) {
                $prod = $produits[$it[0]];

                return [
                    'produit_id' => $prod->id,
                    'designation' => $prod->name,
                    'quantite' => $it[1],
                    'prix_unitaire' => (float) $prod->sell_price,
                    'tva_rate' => (float) $prod->tva_rate,
                ];
            }, $items);

            $doc = $venteSvc->create([
                'type' => 'facture',
                'tiers_id' => $clients[$ci]->id,
                'date_document' => $base->toDateString(),
                'date_echeance' => $base->copy()->addDays(30)->toDateString(),
                'lignes' => $lignes,
            ]);
            $doc = $venteSvc->valider($doc);
            $nbFactures++;

            if ($paye) {
                $venteSvc->ajouterPaiement($doc, [
                    'montant' => (float) $doc->total_ttc,
                    'mode' => $modes[$nbFactures % 3],
                    'date_paiement' => $base->toDateString(),
                ]);
                $nbPaye++;
            }
        }

        $this->info(sprintf(
            'Démo insérée pour « %s » : %d clients, %d fournisseurs, %d produits, %d factures (%d payées).',
            $tenant->name, count($clients), count($fournisseurs), count($produits), $nbFactures, $nbPaye,
        ));

        return self::SUCCESS;
    }
}
