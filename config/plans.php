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

    // price = abonnement mensuel indicatif (MAD HT), utilisé pour estimer le
    // revenu récurrent dans la console d'administration.
    'plans' => [
        'free' => [
            'label' => 'Découverte',
            'included_seats' => 1,
            'price' => 0,
        ],
        'essentiel' => [
            'label' => 'Essentiel',
            'included_seats' => 2,
            'price' => 99,
        ],
        'business' => [
            'label' => 'Business',
            'included_seats' => 8,
            'price' => 249,
        ],
        'premium' => [
            'label' => 'Premium',
            'included_seats' => 25,
            'price' => 499,
        ],
    ],
];
