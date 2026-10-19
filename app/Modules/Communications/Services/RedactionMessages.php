<?php

namespace App\Modules\Communications\Services;

/**
 * Les textes envoyés aux clients, en français ET en arabe.
 *
 * Les deux variantes sont toujours produites, quelle que soit la langue de
 * l'interface : c'est la langue du CLIENT qui compte, et seul l'utilisateur la
 * connaît. Ces textes ne passent donc PAS par les traductions de l'interface
 * (lang/ar.json) : changer de langue d'écran ne doit pas changer ce que reçoit
 * le client.
 *
 * Courts, polis, signés du nom de l'entreprise : un message WhatsApp se lit sur
 * un écran de téléphone, entre deux autres conversations.
 *
 * LES ESPACES DES MONTANTS SONT INSÉCABLES (U+00A0), ET CE N'EST PAS DE LA
 * COQUETTERIE. Dans un texte arabe, l'algorithme bidirectionnel d'Unicode
 * traite une espace ordinaire comme une frontière : « 12 345,60 » s'y
 * afficherait « 345,60 12 ». L'espace insécable est un séparateur de nombre,
 * le montant reste d'un bloc. En français, elle évite en prime qu'un retour à
 * la ligne coupe « 12 » de « 345,60 MAD » sur un écran étroit.
 */
class RedactionMessages
{
    private const NBSP = "\u{00A0}";

    /** Mode de règlement, tel qu'il se dit dans une phrase. */
    private const MODES = [
        'especes' => ['fr' => 'en espèces', 'ar' => 'نقدا'],
        'cheque' => ['fr' => 'par chèque', 'ar' => 'بشيك'],
        'virement' => ['fr' => 'par virement', 'ar' => 'بتحويل بنكي'],
        'carte' => ['fr' => 'par carte bancaire', 'ar' => 'بالبطاقة البنكية'],
    ];

    /**
     * @param  array<string, mixed>  $c  contexte préparé par PreparationMessage
     * @return array{fr: string, ar: string}
     */
    public function rediger(string $type, array $c): array
    {
        return match ($type) {
            'relance' => ['fr' => $this->relanceFr($c), 'ar' => $this->relanceAr($c)],
            'facture' => ['fr' => $this->factureFr($c), 'ar' => $this->factureAr($c)],
            'devis' => ['fr' => $this->devisFr($c), 'ar' => $this->devisAr($c)],
            'paiement' => ['fr' => $this->paiementFr($c), 'ar' => $this->paiementAr($c)],
        };
    }

    /** « 12 345,60 MAD » — « 12 345,60 درهم ». */
    public static function montant(float $valeur, string $langue): string
    {
        return number_format($valeur, 2, ',', self::NBSP).self::NBSP.($langue === 'ar' ? 'درهم' : 'MAD');
    }

    /* ---------------------------------------------------------------- */
    /* Relance d'impayé : le ton monte avec le niveau. */
    /* ---------------------------------------------------------------- */

    private function relanceFr(array $c): string
    {
        $retard = $c['jours_retard'] === 1 ? '1 jour de retard' : "{$c['jours_retard']} jours de retard";
        $facture = "la facture {$c['code']} du {$c['date']}";

        $constat = match (true) {
            // Pas encore échue : seul un rappel courtois est possible (le
            // service refuse les niveaux 2 et 3 dans ce cas).
            ! $c['echue'] => "Pour rappel, {$facture} arrive à échéance le {$c['echeance']}.",
            $c['niveau'] === 1 => "Sauf erreur de notre part, {$facture}, échue le {$c['echeance']}, reste impayée à ce jour ({$retard}).",
            $c['niveau'] === 2 => "Malgré notre précédent rappel, {$facture}, échue le {$c['echeance']}, reste impayée ({$retard}).",
            default => "En dépit de nos relances, {$facture}, échue le {$c['echeance']}, demeure impayée ({$retard}).",
        };

        $demande = match ($c['niveau']) {
            1 => 'Si votre règlement est déjà en cours, merci de ne pas tenir compte de ce message.',
            2 => 'Nous vous remercions de procéder à son règlement sous huit jours, ou de nous indiquer la date à laquelle il interviendra.',
            default => 'Nous vous mettons en demeure de régler cette somme sous huit jours. À défaut, nous serons contraints d\'engager une procédure de recouvrement.',
        };

        return $this->assembler([
            'Bonjour,',
            '',
            $constat,
            'Reste à payer : '.self::montant($c['reste'], 'fr').'.',
            '',
            $demande,
            "Votre facture en PDF (lien disponible jusqu'au {$c['lien_expire']}) :",
            $c['lien'],
            '',
            'Cordialement,',
            $c['entreprise'],
        ]);
    }

    private function relanceAr(array $c): string
    {
        // « أيام التأخير: 16 » plutôt que « 16 يوما » : l'accord du nom avec le
        // nombre change à 1, 2, de 3 à 10 et au-delà — cette tournure n'en a
        // besoin d'aucun.
        $retard = "أيام التأخير: {$c['jours_retard']}";
        $facture = "الفاتورة رقم {$c['code']} بتاريخ {$c['date']}";

        $constat = match (true) {
            ! $c['echue'] => "نذكركم بأن أجل أداء {$facture} يحل في {$c['echeance']}.",
            $c['niveau'] === 1 => "ما لم نكن مخطئين، فإن {$facture}، التي حل أجل أدائها في {$c['echeance']}، لا تزال غير مؤداة إلى حدود اليوم ({$retard}).",
            $c['niveau'] === 2 => "رغم تذكيرنا السابق، لا تزال {$facture}، التي حل أجل أدائها في {$c['echeance']}، غير مؤداة ({$retard}).",
            default => "رغم تذكيراتنا المتكررة، لا تزال {$facture}، التي حل أجل أدائها في {$c['echeance']}، غير مؤداة ({$retard}).",
        };

        $demande = match ($c['niveau']) {
            1 => 'إذا كان أداؤكم في طور الإنجاز، نرجو عدم اعتبار هذه الرسالة.',
            2 => 'نرجو منكم تسوية هذا المبلغ داخل أجل ثمانية أيام، أو إخبارنا بالتاريخ المرتقب للأداء.',
            default => 'نوجه إليكم إنذارا بأداء هذا المبلغ داخل أجل ثمانية أيام، وإلا سنضطر إلى مباشرة إجراءات التحصيل.',
        };

        return $this->assembler([
            'السلام عليكم،',
            '',
            $constat,
            'المبلغ المتبقي: '.self::montant($c['reste'], 'ar').'.',
            '',
            $demande,
            "فاتورتكم بصيغة PDF (الرابط متاح إلى غاية {$c['lien_expire']}):",
            $c['lien'],
            '',
            'مع خالص التحيات،',
            $c['entreprise'],
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Envoi de facture. */
    /* ---------------------------------------------------------------- */

    private function factureFr(array $c): string
    {
        $phrase = "Veuillez trouver ci-dessous votre facture {$c['code']} du {$c['date']}, d'un montant de "
            .self::montant($c['ttc'], 'fr').' TTC';

        // L'échéance n'a de sens que pour ce qui reste dû — et ce qu'une
        // traite couvre n'est plus à régler par le client. Dépassée, on ne
        // demande pas de régler « au plus tard » à une date déjà passée : on
        // la constate, sans le ton d'une relance.
        $phrase .= match (true) {
            $c['echeance_saisie'] === null || $c['soldee'] || $c['effets'] !== [] => '.',
            $c['echue'] => ", échue le {$c['echeance_saisie']}.",
            default => ", à régler au plus tard le {$c['echeance_saisie']}.",
        };

        return $this->assembler([
            'Bonjour,',
            '',
            $phrase,
            $c['soldee'] ? 'Elle est déjà réglée : merci.' : null,
            ...$this->effets($c, 'fr'),
            $c['partiellement_reglee'] ? 'Reste à payer : '.self::montant($c['reste'], 'fr').'.' : null,
            "Votre facture en PDF (lien disponible jusqu'au {$c['lien_expire']}) :",
            $c['lien'],
            '',
            'Merci de votre confiance.',
            $c['entreprise'],
        ]);
    }

    private function factureAr(array $c): string
    {
        $phrase = "تجدون أسفله فاتورتكم رقم {$c['code']} بتاريخ {$c['date']}، بمبلغ "
            .self::montant($c['ttc'], 'ar').' (مع احتساب الرسوم)';

        $phrase .= match (true) {
            $c['echeance_saisie'] === null || $c['soldee'] || $c['effets'] !== [] => '.',
            $c['echue'] => "، حل أجل أدائها في {$c['echeance_saisie']}.",
            default => "، يرجى أداؤها في أجل أقصاه {$c['echeance_saisie']}.",
        };

        return $this->assembler([
            'السلام عليكم،',
            '',
            $phrase,
            $c['soldee'] ? 'وقد تم أداؤها بالكامل، شكرا لكم.' : null,
            ...$this->effets($c, 'ar'),
            $c['partiellement_reglee'] ? 'المبلغ المتبقي للأداء: '.self::montant($c['reste'], 'ar').'.' : null,
            "فاتورتكم بصيغة PDF (الرابط متاح إلى غاية {$c['lien_expire']}):",
            $c['lien'],
            '',
            'شكرا على ثقتكم.',
            $c['entreprise'],
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Envoi de devis. */
    /* ---------------------------------------------------------------- */

    private function devisFr(array $c): string
    {
        // Un devis n'a pas d'autre date limite que la case « Échéance » du
        // formulaire : pour lui, c'est la fin de sa validité. Sans elle, le
        // message n'en promet aucune. Dépassée, il ne la présente pas comme
        // encore valable : une offre périmée se renégocie, elle ne s'accepte
        // pas en l'état.
        $validite = match (true) {
            $c['echeance_saisie'] === null => '',
            $c['validite_depassee'] => ". Sa validité a pris fin le {$c['echeance_saisie']} : contactez-nous pour l'actualiser",
            default => ", valable jusqu'au {$c['echeance_saisie']}",
        };

        return $this->assembler([
            'Bonjour,',
            '',
            "Veuillez trouver ci-dessous notre devis {$c['code']} du {$c['date']}, d'un montant de "
                .self::montant($c['ttc'], 'fr')." TTC{$validite}.",
            "Votre devis en PDF (lien disponible jusqu'au {$c['lien_expire']}) :",
            $c['lien'],
            '',
            'Nous restons à votre disposition pour toute question.',
            'Cordialement,',
            $c['entreprise'],
        ]);
    }

    private function devisAr(array $c): string
    {
        $validite = match (true) {
            $c['echeance_saisie'] === null => '',
            $c['validite_depassee'] => ". انتهت صلاحيته في {$c['echeance_saisie']}، يرجى التواصل معنا لتحيينه",
            default => "، صالح إلى غاية {$c['echeance_saisie']}",
        };

        return $this->assembler([
            'السلام عليكم،',
            '',
            // « عرض الثمن », le mot de l'interface arabe pour un devis.
            "تجدون أسفله عرض الثمن رقم {$c['code']} بتاريخ {$c['date']}، بمبلغ "
                .self::montant($c['ttc'], 'ar')." (مع احتساب الرسوم){$validite}.",
            "عرض الثمن بصيغة PDF (الرابط متاح إلى غاية {$c['lien_expire']}):",
            $c['lien'],
            '',
            'نبقى رهن إشارتكم لأي استفسار.',
            'مع خالص التحيات،',
            $c['entreprise'],
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Confirmation de paiement. */
    /* ---------------------------------------------------------------- */

    private function paiementFr(array $c): string
    {
        $p = $c['paiement'];
        $mode = $this->mode($p['mode'], $p['reference'], 'fr');
        $date = $p['date'] !== null ? ", en date du {$p['date']}" : '';

        return $this->assembler([
            'Bonjour,',
            '',
            'Nous avons bien reçu votre paiement de '.self::montant($p['montant'], 'fr')
                ."{$mode}{$date}, pour la facture {$c['code']}.",
            ...$this->effets($c, 'fr'),
            match (true) {
                $c['soldee'] => 'La facture est désormais soldée.',
                $c['reste'] > 0.009 => 'Reste à payer à ce jour : '.self::montant($c['reste'], 'fr').'.',
                default => null,
            },
            '',
            "Votre facture en PDF (lien disponible jusqu'au {$c['lien_expire']}) :",
            $c['lien'],
            '',
            'Merci pour votre règlement.',
            $c['entreprise'],
        ]);
    }

    private function paiementAr(array $c): string
    {
        $p = $c['paiement'];
        $mode = $this->mode($p['mode'], $p['reference'], 'ar');
        $date = $p['date'] !== null ? " بتاريخ {$p['date']}" : '';

        return $this->assembler([
            'السلام عليكم،',
            '',
            'نؤكد لكم توصلنا بأدائكم بمبلغ '.self::montant($p['montant'], 'ar')
                ."{$mode}{$date}، عن الفاتورة رقم {$c['code']}.",
            ...$this->effets($c, 'ar'),
            match (true) {
                $c['soldee'] => 'أصبحت الفاتورة مؤداة بالكامل.',
                $c['reste'] > 0.009 => 'المبلغ المتبقي إلى حدود اليوم: '.self::montant($c['reste'], 'ar').'.',
                default => null,
            },
            '',
            "فاتورتكم بصيغة PDF (الرابط متاح إلى غاية {$c['lien_expire']}):",
            $c['lien'],
            '',
            'شكرا على أدائكم.',
            $c['entreprise'],
        ]);
    }

    /**
     * Une ligne par traite EN PORTEFEUILLE : ce qu'elle couvre n'est plus à
     * régler par le client, il faut le lui dire plutôt que le lui réclamer.
     * (Encaissée, elle vaut règlement : PreparationMessage l'a déjà déduite.)
     *
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private function effets(array $c, string $langue): array
    {
        return array_map(function (array $e) use ($langue) {
            $montant = self::montant($e['montant'], $langue);

            if ($langue === 'ar') {
                $echeance = $e['echeance'] !== null ? "، التي يحل أجلها في {$e['echeance']}،" : '';

                return "كمبيالتكم رقم {$e['code']}{$echeance} تغطي {$montant} من هذه الفاتورة.";
            }

            $echeance = $e['echeance'] !== null ? ", à échéance du {$e['echeance']}," : '';

            return "Votre effet n° {$e['code']}{$echeance} couvre {$montant} de cette facture.";
        }, $c['effets']);
    }

    /** « par chèque (réf. 1234567) » ; rien pour le mode « autre ». */
    private function mode(?string $mode, ?string $reference, string $langue): string
    {
        $texte = self::MODES[$mode][$langue] ?? null;

        if ($texte === null) {
            return '';
        }

        $reference = trim((string) $reference);
        if ($reference !== '') {
            $texte .= $langue === 'ar' ? " (المرجع: {$reference})" : " (réf. {$reference})";
        }

        return ' '.$texte;
    }

    /**
     * Une ligne nulle disparaît ; une ligne vide reste (c'est un saut de
     * paragraphe).
     *
     * @param  list<string|null>  $lignes
     */
    private function assembler(array $lignes): string
    {
        return implode("\n", array_filter($lignes, fn (?string $l) => $l !== null));
    }
}
