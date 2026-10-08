<?php

namespace App\Modules\Tiers\Services;

use App\Core\Format\Csv;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'annuaire des tiers en CSV, pour Excel en français.
 *
 * ÉCRIT AU FIL DE L'EAU, par paquets de 500 : un grossiste a des milliers de
 * tiers, et tout charger avant d'écrire la première ligne, c'était tenir
 * l'annuaire entier en mémoire pendant que le navigateur attend. Chaque
 * paquet lit ses soldes en UN agrégat du grand livre, comme une page de la
 * liste.
 *
 * Les en-têtes et les libellés restent EN FRANÇAIS quelle que soit la langue
 * de l'écran : par décision, les documents remis hors de l'application
 * (factures, relevés, exports) sont en français.
 */
class ExportTiersService
{
    private const PAQUET = 500;

    public const COLONNES = [
        'Code', 'Nom', 'Type', 'Forme', 'ICE', 'IF', 'RC',
        'Téléphone', 'E-mail', 'Ville', 'Actif', 'Solde (MAD)',
    ];

    public function __construct(private EncoursService $encours) {}

    /**
     * @param  resource  $flux
     * @param  Builder<Tiers>  $requete  déjà filtrée ET triée : le découpage en paquets suit son ordre
     */
    public function ecrire($flux, Builder $requete): void
    {
        fwrite($flux, Csv::BOM);
        Csv::ligne($flux, self::COLONNES);

        $requete->chunk(self::PAQUET, function ($paquet) use ($flux) {
            $soldes = $this->encours->soldesAffiches($paquet);

            foreach ($paquet as $tiers) {
                Csv::ligne($flux, [
                    Csv::texte($tiers->code),
                    Csv::texte($tiers->name),
                    self::type($tiers),
                    $tiers->forme === Tiers::FORME_PARTICULIER ? 'Particulier' : 'Entreprise',
                    Csv::texte($tiers->ice),
                    Csv::texte($tiers->if_number),
                    Csv::texte($tiers->rc),
                    Csv::texte($tiers->phone),
                    Csv::texte($tiers->email),
                    Csv::texte($tiers->city),
                    $tiers->is_active ? 'Oui' : 'Non',
                    // Vide, pas « 0,00 », quand le tiers n'a pas de compte
                    // client : même règle que la liste.
                    Csv::montant($soldes[$tiers->id] ?? null),
                ]);
            }

            // Le paquet part vers le navigateur au lieu de s'accumuler.
            fflush($flux);
        });
    }

    /** Ce qu'est le tiers, en clair. Un prospect est aussi `is_client` côté base : il se dit « Prospect ». */
    public static function type(Tiers $tiers): string
    {
        $client = $tiers->is_prospect ? 'Prospect' : ($tiers->is_client ? 'Client' : null);

        return match (true) {
            $client !== null && $tiers->is_supplier => $client.' et fournisseur',
            $client !== null => $client,
            $tiers->is_supplier => 'Fournisseur',
            default => '',
        };
    }
}
