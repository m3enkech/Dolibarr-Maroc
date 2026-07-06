<?php

/**
 * Offres commerciales (packs) et sièges utilisateurs inclus.
 *
 * La limite d'utilisateurs actifs d'une entreprise = sièges inclus par son
 * plan + tenants.extra_seats (sièges additionnels achetés). Au-delà,
 * l'invitation est bloquée (le client doit monter de plan ou acheter des
 * sièges). La facturation d'abonnement (Stripe/CMI) reste un chantier séparé :
 * ici on ne pose que le plafond et le prix indicatif du siège extra.
 */
return [
    // Prix indicatif d'un siège utilisateur supplémentaire (MAD HT / mois).
    'extra_seat_price' => 40,

    'plans' => [
        'free' => [
            'label' => 'Découverte',
            'included_seats' => 1,
        ],
        'essentiel' => [
            'label' => 'Essentiel',
            'included_seats' => 2,
        ],
        'business' => [
            'label' => 'Business',
            'included_seats' => 8,
        ],
        'premium' => [
            'label' => 'Premium',
            'included_seats' => 25,
        ],
    ],
];
