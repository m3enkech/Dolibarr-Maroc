<?php

namespace Tests\Unit;

use App\Core\Format\NumeroWhatsApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ce que les gens tapent dans une fiche, ramené au format de wa.me.
 *
 * Un numéro mal ramené n'échoue pas bruyamment : WhatsApp ouvre une
 * conversation avec un inconnu. Chaque format rencontré a donc son cas.
 */
class NumeroWhatsAppTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function numerosValides(): array
    {
        return [
            'espaces' => ['06 12 34 56 78', '212612345678', 'mobile'],
            'points' => ['06.12.34.56.78', '212612345678', 'mobile'],
            'tirets' => ['06-12-34-56-78', '212612345678', 'mobile'],
            'international' => ['+212 6 12 34 56 78', '212612345678', 'mobile'],
            'international avec (0)' => ['+212 (0)6 12 34 56 78', '212612345678', 'mobile'],
            'international avec le zéro recopié' => ['+212 06 12 34 56 78', '212612345678', 'mobile'],
            'double zéro' => ['00212612345678', '212612345678', 'mobile'],
            'double zéro espacé' => ['00 212 6 12 34 56 78', '212612345678', 'mobile'],
            'déjà au format' => ['212612345678', '212612345678', 'mobile'],
            'zéro oublié' => ['612345678', '212612345678', 'mobile'],
            'mobile en 07' => ['07 00 11 22 33', '212700112233', 'mobile'],
            'chiffres arabes' => ['٠٦١٢٣٤٥٦٧٨', '212612345678', 'mobile'],
            'étiquette Tél' => ['Tél : 06 12 34 56 78', '212612345678', 'mobile'],
            'étiquette GSM' => ['GSM 0612345678', '212612345678', 'mobile'],
            'étiquette WhatsApp' => ['WhatsApp: +212612345678', '212612345678', 'mobile'],
            'étranger +33' => ['+33 6 12 34 56 78', '33612345678', 'etranger'],
            'étranger 0033' => ['0033 6 12 34 56 78', '33612345678', 'etranger'],
            'étranger entre parenthèses' => ['(+33) 6 12 34 56 78', '33612345678', 'etranger'],
            'étranger (0)' => ['+33 (0)6 12 34 56 78', '33612345678', 'etranger'],
            'Canada' => ['+1 514 555 0199', '15145550199', 'etranger'],
            'fixe marocain' => ['05 22 30 30 30', '212522303030', 'fixe'],
            'fixe marocain international' => ['+212 5 22 30 30 30', '212522303030', 'fixe'],
            'numéro spécial 08' => ['0801 00 20 30', '212801002030', 'fixe'],
            // Collés depuis les contacts d'un iPhone, WhatsApp ou une interface
            // arabe : des marques de direction invisibles entourent le numéro.
            'étranger entre LRE et PDF' => ["\u{202A}+33 6 12 34 56 78\u{202C}", '33612345678', 'etranger'],
            'étranger précédé de LRM' => ["\u{200E}+33 6 12 34 56 78", '33612345678', 'etranger'],
            'étranger entre LRI et PDI' => ["\u{2066}+44 20 7946 0958\u{2069}", '442079460958', 'etranger'],
            'marocain entre RLE et PDF' => ["\u{202B}06 12 34 56 78\u{202C}", '212612345678', 'mobile'],
            'étiquette après une marque' => ["\u{200F}Tél : +33 6 12 34 56 78", '33612345678', 'etranger'],
            // Un tiret ENTRE les groupes d'un seul numéro ne le coupe pas.
            'tirets espacés dans un seul numéro' => ['06 - 12 - 34 - 56 - 78', '212612345678', 'mobile'],
        ];
    }

    #[DataProvider('numerosValides')]
    public function test_un_numero_valide_est_ramene_au_format_wa_me(string $saisie, string $attendu, string $nature): void
    {
        $numero = NumeroWhatsApp::depuis($saisie);

        $this->assertTrue($numero->valide(), "« {$saisie} » refusé : ".$numero->raison);
        $this->assertSame($attendu, $numero->numero);
        $this->assertSame($nature, $numero->nature);
        $this->assertNull($numero->raison);
        $this->assertSame($saisie, $numero->saisie, 'La saisie est gardée telle quelle, pour l\'afficher.');
    }

    public function test_un_fixe_est_accepte_mais_signale(): void
    {
        $fixe = NumeroWhatsApp::depuis('0522 30 30 30');

        $this->assertTrue($fixe->valide());
        $this->assertSame(NumeroWhatsApp::NATURE_FIXE, $fixe->nature);
        $this->assertSame('fixe — WhatsApp peu probable', $fixe->avertissement);

        $this->assertNull(NumeroWhatsApp::depuis('0612345678')->avertissement, 'Un mobile n\'a rien à signaler.');
        $this->assertNull(NumeroWhatsApp::depuis('+33612345678')->avertissement);
    }

    /** @return array<string, array{0: string|null, 1: string}> */
    public static function numerosInvalides(): array
    {
        return [
            'null' => [null, NumeroWhatsApp::RAISON_VIDE],
            'vide' => ['', NumeroWhatsApp::RAISON_VIDE],
            'blancs' => ['   ', NumeroWhatsApp::RAISON_VIDE],
            'ponctuation seule' => ['-- . --', NumeroWhatsApp::RAISON_VIDE],
            'trop court' => ['06 12 34 56', NumeroWhatsApp::RAISON_LONGUEUR_MAROC],
            'trop long' => ['061234567890', NumeroWhatsApp::RAISON_LONGUEUR_MAROC],
            'tronqué sans zéro' => ['12345', NumeroWhatsApp::RAISON_LONGUEUR_MAROC],
            'international trop court' => ['+212 6 12 34 56', NumeroWhatsApp::RAISON_LONGUEUR_MAROC],
            'préfixe inconnu' => ['09 12 34 56 78', NumeroWhatsApp::RAISON_PREFIXE_MAROC],
            'préfixe 01' => ['+212 1 12 34 56 78', NumeroWhatsApp::RAISON_PREFIXE_MAROC],
            'extension' => ['0522 30 30 30 poste 12', NumeroWhatsApp::RAISON_TEXTE],
            'texte seul' => ['néant', NumeroWhatsApp::RAISON_TEXTE],
            'nom collé' => ['0612345678 (Ahmed)', NumeroWhatsApp::RAISON_TEXTE],
            'étranger sans indicatif' => ['33612345678', NumeroWhatsApp::RAISON_INDICATIF],
            'plus devant un national' => ['+0612345678', NumeroWhatsApp::RAISON_INDICATIF],
            'international trop court étranger' => ['+33 6', NumeroWhatsApp::RAISON_LONGUEUR_INTERNATIONALE],
            'au-delà de E.164' => ['+1234567890123456', NumeroWhatsApp::RAISON_LONGUEUR_INTERNATIONALE],
        ];
    }

    #[DataProvider('numerosInvalides')]
    public function test_un_numero_invalide_est_refuse_avec_sa_raison(?string $saisie, string $raison): void
    {
        $numero = NumeroWhatsApp::depuis($saisie);

        $this->assertFalse($numero->valide());
        $this->assertNull($numero->numero);
        $this->assertNull($numero->nature);
        $this->assertNull($numero->affichage());
        $this->assertSame($raison, $numero->raison);
    }

    public function test_une_saisie_qui_n_est_pas_de_l_utf8_est_refusee_sans_planter(): void
    {
        $numero = NumeroWhatsApp::depuis("06\xff\xfe12345678");

        $this->assertFalse($numero->valide());
        $this->assertSame(NumeroWhatsApp::RAISON_TEXTE, $numero->raison);
    }

    public function test_deux_numeros_dans_une_case(): void
    {
        // depuis() retient le premier, presque toujours le mobile.
        $this->assertSame('212612345678', NumeroWhatsApp::depuis('0612345678 / 0522303030')->numero);

        $tous = NumeroWhatsApp::plusieurs('0612345678 / 05 22 30 30 30 ; +33 6 12 34 56 78 ou 0661000000');

        $this->assertSame(
            ['212612345678', '212522303030', '33612345678', '212661000000'],
            array_map(fn (NumeroWhatsApp $n) => $n->numero, $tous),
        );
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function separateursAmbigus(): array
    {
        return [
            'tiret espacé' => ['0661234567 - 0522303030', ['212661234567', '212522303030']],
            'demi-cadratin espacé' => ['0661 23 45 67 – 0522 30 30 30', ['212661234567', '212522303030']],
            'demi-cadratin collé' => ['0661234567–0522303030', ['212661234567', '212522303030']],
            'cadratin' => ['+33 6 12 34 56 78 — 0661234567', ['33612345678', '212661234567']],
            'saut de ligne' => ["0612345678\n0522303030", ['212612345678', '212522303030']],
            'saut de ligne Windows' => ["Tél : 0612345678\r\nFixe : 0522303030", ['212612345678', '212522303030']],
            'mêlés aux séparateurs nets' => ['0612345678 / 0661000000 - 0522303030', ['212612345678', '212661000000', '212522303030']],
        ];
    }

    /** @param  list<string>  $attendus */
    #[DataProvider('separateursAmbigus')]
    public function test_un_separateur_ambigu_coupe_entre_deux_numeros_complets(string $saisie, array $attendus): void
    {
        $this->assertSame($attendus, array_map(fn (NumeroWhatsApp $n) => $n->numero, NumeroWhatsApp::plusieurs($saisie)));
        $this->assertSame($attendus[0], NumeroWhatsApp::depuis($saisie)->numero);
    }

    public function test_un_separateur_ambigu_ne_coupe_pas_un_numero_en_morceaux(): void
    {
        // Un morceau trop court pour être un numéro : la case reste d'un bloc,
        // et son texte est refusé comme avant (« poste 12 » n'est pas un numéro).
        $poste = NumeroWhatsApp::plusieurs('0522303030 - poste 12');
        $this->assertCount(1, $poste);
        $this->assertSame(NumeroWhatsApp::RAISON_TEXTE, $poste[0]->raison);

        // Un tiret collé groupe toujours les chiffres.
        $this->assertCount(1, NumeroWhatsApp::plusieurs('06-12-34-56-78'));
    }

    public function test_un_reste_d_etiquette_n_est_pas_un_numero_refuse(): void
    {
        $tous = NumeroWhatsApp::plusieurs('GSM / WhatsApp : 06 12 34 56 78');

        $this->assertCount(1, $tous);
        $this->assertSame('212612345678', $tous[0]->numero);
    }

    public function test_une_case_vide_ne_donne_aucun_numero(): void
    {
        $this->assertSame([], NumeroWhatsApp::plusieurs(null));
        $this->assertSame([], NumeroWhatsApp::plusieurs('  '));

        // Du texte seul reste un refus, avec sa raison.
        $texte = NumeroWhatsApp::plusieurs('voir avec le gérant');
        $this->assertCount(1, $texte);
        $this->assertSame(NumeroWhatsApp::RAISON_TEXTE, $texte[0]->raison);
    }

    public function test_affichage_lisible(): void
    {
        $this->assertSame('+212 6 12 34 56 78', NumeroWhatsApp::depuis('0612345678')->affichage());
        $this->assertSame('+212 5 22 30 30 30', NumeroWhatsApp::depuis('0522303030')->affichage());
        // Un numéro étranger n'est pas découpé : on ne connaît pas son plan.
        $this->assertSame('+33612345678', NumeroWhatsApp::depuis('+33 6 12 34 56 78')->affichage());
    }
}
