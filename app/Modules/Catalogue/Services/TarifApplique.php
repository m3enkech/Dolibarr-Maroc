<?php

namespace App\Modules\Catalogue\Services;

/**
 * Prix retenu par TarifService, avec ce qui l'a fait retenir.
 *
 * L'origine existe pour l'écran de saisie : un vendeur qui voit 72,00 au lieu
 * des 100,00 du catalogue doit savoir pourquoi, sinon il « corrige » un prix
 * négocié qu'il croit faux.
 */
final class TarifApplique
{
    /** Prix négocié pour CE client. */
    public const CLIENT = 'client';

    /** Prix de la catégorie tarifaire du client, ou de la catégorie par défaut. */
    public const CATEGORIE = 'categorie';

    /** Prix de vente de la fiche article : aucun tarif ne s'applique. */
    public const CATALOGUE = 'catalogue';

    public function __construct(
        public readonly float $prix,
        public readonly string $origine,
        /** Quantité minimale du palier retenu ; `null` pour le prix catalogue. */
        public readonly ?float $palier = null,
        /** Catégorie tarifaire dont vient le prix (origine CATEGORIE seulement). */
        public readonly ?int $categorieId = null,
        /**
         * Vrai quand cette catégorie est celle PAR DÉFAUT de l'entreprise, faute
         * de client ou de catégorie sur sa fiche : ce n'est pas un tarif propre
         * au client, et l'écran ne doit pas le présenter comme tel.
         */
        public readonly bool $categorieParDefaut = false,
    ) {}
}
