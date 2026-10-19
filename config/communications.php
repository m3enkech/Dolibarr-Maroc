<?php

/*
 * Messages envoyés aux clients (WhatsApp aujourd'hui, d'autres canaux demain).
 */
return [

    'lien_document' => [
        /*
         * Durée de vie du lien PUBLIC vers le PDF d'une facture ou d'un devis,
         * glissé dans le message. Ce lien s'ouvre sans compte : quiconque le
         * détient lit le document. Trente jours couvrent un cycle de paiement
         * ordinaire ; au-delà, on renvoie un message, qui porte un lien neuf.
         * Le lien reste valable jusqu'à la FIN du dernier jour : la date
         * annoncée dans le message est donc tenue toute la journée.
         */
        'duree_jours' => (int) env('LIEN_DOCUMENT_DUREE_JOURS', 30),
    ],

    /*
     * Longueur maximale d'un message journalisé. Les modèles font quelques
     * centaines de caractères ; la borne laisse la place aux retouches de
     * l'utilisateur sans laisser grossir le journal sans limite — et un lien
     * wa.me démesuré finit tronqué par le navigateur ou par WhatsApp.
     */
    'message_longueur_max' => 2000,

];
