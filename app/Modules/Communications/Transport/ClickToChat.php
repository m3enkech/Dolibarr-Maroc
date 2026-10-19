<?php

namespace App\Modules\Communications\Transport;

use App\Modules\Communications\Services\MessagePrepare;

/**
 * Le transport WhatsApp d'aujourd'hui : le lien « click-to-chat ».
 *
 * https://wa.me/<numéro>?text=<message> ouvre WhatsApp — l'application sur un
 * téléphone, WhatsApp Web ou Desktop sur un ordinateur — sur la conversation
 * du client, message déjà écrit. L'utilisateur relit et appuie lui-même sur
 * Envoyer, depuis SON numéro. Gratuit, sans compte Meta, sans modèle de
 * message à faire approuver.
 *
 * Isolé ici parce que c'est la seule pièce qui changera le jour où l'API
 * WhatsApp Business enverra sans intervention humaine : la préparation du
 * message (MessagePrepare : numéros, textes, lien du PDF) et le journal
 * resteront les mêmes, un autre transport prendra le relais.
 */
final class ClickToChat
{
    public const BASE = 'https://wa.me/';

    /**
     * Sans numéro, wa.me ouvre WhatsApp sur le choix du contact, message
     * prêt : le client n'a pas de numéro en fiche, l'utilisateur le connaît.
     *
     * `rawurlencode` et non `urlencode` : ce dernier code l'espace en « + »,
     * convention des formulaires que rien n'oblige un client WhatsApp à
     * relire comme une espace ; `%20` ne laisse aucune place au doute. C'est
     * aussi ce que produit `encodeURIComponent` côté navigateur.
     */
    public static function lien(?string $numero, string $message): string
    {
        return self::BASE.($numero ?? '').'?text='.rawurlencode($message);
    }

    /**
     * Les deux liens d'un message préparé (français, arabe), vers le numéro
     * présélectionné. Une commodité : dès que l'utilisateur change de numéro
     * ou retouche le texte, le navigateur reconstruit le lien lui-même.
     *
     * @return array{fr: string, ar: string}
     */
    public static function liens(MessagePrepare $message): array
    {
        $numero = $message->numeroSelectionne();

        return [
            'fr' => self::lien($numero, $message->messages['fr']),
            'ar' => self::lien($numero, $message->messages['ar']),
        ];
    }
}
