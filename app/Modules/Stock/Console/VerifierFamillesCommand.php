<?php

namespace App\Modules\Stock\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Stock\Services\VerificationFamilles;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Diagnostic des sorties de stock par famille de documents de vente.
 *
 *   php artisan stock:verifier-familles client@exemple.ma
 *
 * Liste, par famille et par article, l'écart entre ce qui est sorti et ce qui
 * aurait dû sortir : sous-sorties (le second BL partiel d'une commande ne
 * sortait rien) et sur-sorties (BL et facture frères sortant chacun, avant la
 * règle de famille). LECTURE SEULE, sans option de correction : un inventaire
 * fait depuis a pu remettre le stock réel d'aplomb, et seul quelqu'un qui
 * connaît le dépôt peut dire s'il faut encore ajuster.
 */
class VerifierFamillesCommand extends Command
{
    protected $signature = 'stock:verifier-familles
        {email : un utilisateur de l\'entreprise concernée}';

    protected $description = 'Lecture seule : écarts entre le stock sorti et le stock dû, par famille de documents de vente';

    private const TYPES = [
        DocumentVente::TYPE_DEVIS => 'devis',
        DocumentVente::TYPE_COMMANDE => 'commande',
        DocumentVente::TYPE_BON_LIVRAISON => 'bon de livraison',
        DocumentVente::TYPE_FACTURE => 'facture',
        DocumentVente::TYPE_AVOIR => 'avoir',
    ];

    public function handle(TenantContext $contexte, VerificationFamilles $verification): int
    {
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null || $user->tenant === null) {
            $this->error("Utilisateur ou entreprise introuvable pour : {$this->argument('email')}");

            return self::FAILURE;
        }

        // Contexte posé EXPLICITEMENT : sans lui, le scope multi-entreprises est
        // fail-closed et la vérification conclurait « aucun écart » sur un
        // périmètre vide — le pire des faux rassurants.
        $rapport = $contexte->runAs($user->tenant, fn () => $verification->ecarts());

        $this->line(sprintf('Vérification des sorties de stock de « %s » — lecture seule, rien ne sera modifié.', OutputFormatter::escape($user->tenant->name)));
        $this->line(sprintf('%d famille(s) de documents examinée(s).', $rapport['familles']));

        if ($rapport['ecarts'] === []) {
            $this->newLine();
            $this->info('Aucun écart : chaque famille a sorti exactement ce que la règle demande.');

            return self::SUCCESS;
        }

        $sous = 0;
        $couverts = 0;
        // Net par article ET par entrepôt : additionner des sacs et des
        // bouteilles ne donne pas une quantité, et un ajustement se fait
        // article par article, dans un dépôt.
        $nets = [];

        foreach ($rapport['ecarts'] as $ecart) {
            $manque = $ecart['ecart'] < 0;
            $sous += $manque ? 1 : 0;
            $inventaire = $ecart['inventaire'];
            $dejaCompte = $inventaire !== null && $inventaire['posterieur'];

            if ($dejaCompte) {
                $couverts++;
            } else {
                $cle = $ecart['produit'].' · '.$ecart['entrepot'];
                $nets[$cle] = round(($nets[$cle] ?? 0.0) + $ecart['ecart'], 3);
            }

            // Codes et libellés sont saisis par l'utilisateur, d'où escape() : un
            // article « Vis <M8> » serait sinon lu comme une balise de style.
            $this->newLine();
            $this->line(sprintf(
                '<options=bold>%s</> (%s) · %s · %s · %s : dû %s, sorti %s → %s de %s (stock affiché trop %s)',
                OutputFormatter::escape($ecart['racine']),
                self::TYPES[$ecart['racine_type']] ?? $ecart['racine_type'],
                OutputFormatter::escape($ecart['client'] ?? 'client inconnu'),
                OutputFormatter::escape($ecart['produit']),
                OutputFormatter::escape($ecart['entrepot']),
                $this->nombre($ecart['du']),
                $this->nombre($ecart['sorti']),
                $manque ? '<fg=red>SOUS-SORTIE</>' : '<fg=yellow>SUR-SORTIE</>',
                $this->nombre(abs($ecart['ecart'])),
                $manque ? 'haut' : 'bas',
            ));

            foreach ($ecart['pieces'] as $piece) {
                $this->line(sprintf(
                    '    %-18s %-17s %-10s porte %-8s %s',
                    OutputFormatter::escape($piece['code']),
                    self::TYPES[$piece['type']] ?? $piece['type'],
                    $piece['date'] ?? '—',
                    $this->nombre($piece['porte']),
                    $piece['du'] === null
                        ? 'rentré '.$this->nombre($piece['rentre'])
                        : sprintf('dû %-8s sorti %s', $this->nombre($piece['du']), $this->nombre($piece['sorti'])),
                ));
            }

            $this->line(match (true) {
                $inventaire === null => '    Aucun inventaire validé n\'a compté cet article dans cet entrepôt.',
                $dejaCompte => sprintf(
                    '    <fg=green>Inventaire %s du %s (%s), postérieur à ces pièces : le comptage a probablement '
                    .'déjà corrigé cet écart.</> Ne pas ajuster sans vérifier.',
                    OutputFormatter::escape($inventaire['code']),
                    $inventaire['date'],
                    OutputFormatter::escape($inventaire['entrepot']),
                ),
                default => sprintf(
                    '    Dernier inventaire de l\'article : %s du %s (%s), antérieur à l\'écart.',
                    OutputFormatter::escape($inventaire['code']),
                    $inventaire['date'],
                    OutputFormatter::escape($inventaire['entrepot']),
                ),
            });
        }

        $this->newLine();
        $this->warn(sprintf(
            '%d écart(s) : %d sous-sortie(s), %d sur-sortie(s).',
            count($rapport['ecarts']),
            $sous,
            count($rapport['ecarts']) - $sous,
        ));

        $this->line('Net par article et par entrepôt, hors écarts déjà suivis d\'un inventaire :');

        if ($nets === []) {
            $this->line('    Aucun : chaque écart précède un inventaire qui a compté l\'article.');
        }

        ksort($nets);

        foreach ($nets as $cle => $net) {
            $this->line(sprintf('    %s : %s', OutputFormatter::escape($cle), match (true) {
                abs($net) < 0.0005 => 'les écarts se compensent',
                $net < 0 => 'stock affiché trop haut de '.$this->nombre(abs($net)),
                default => 'stock affiché trop bas de '.$this->nombre($net),
            }));
        }

        if ($couverts > 0) {
            $this->line(sprintf(
                '%d écart(s) suivi(s) d\'un inventaire, exclu(s) du net : le comptage les a probablement déjà absorbés.',
                $couverts,
            ));
        }

        $this->line('Rien n\'a été modifié. Une sous-sortie laisse le stock affiché plus HAUT que la réalité, '
            .'une sur-sortie plus BAS. Avant d\'ajuster, vérifier au dépôt : un inventaire fait depuis a pu '
            .'déjà corriger le stock réel.');
        $this->line('La composition des kits est lue telle qu\'elle est aujourd\'hui : un kit recomposé depuis sa '
            .'vente peut faire paraître un écart qui n\'en est pas un.');

        return self::SUCCESS;
    }

    /** 4 → « 4 », 2.5 → « 2,5 » : des quantités, pas des montants. */
    private function nombre(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 3, ',', ' '), '0'), ',');
    }
}
