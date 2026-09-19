<?php

namespace Tests\Unit;

use App\Core\Format\CleDeRapprochement;
use PHPUnit\Framework\TestCase;

/**
 * Les clés qui servent à reconnaître deux fois le même tiers ou le même article.
 *
 * Le défaut qui a motivé cette classe : `iconv(…ASCII//TRANSLIT//IGNORE…)` rend
 * une chaîne VIDE pour un nom arabe. Dans un index construit par `keyBy`, tous
 * les noms arabes se ramassaient donc dans la même case et le dernier écrasait
 * les autres — la facture d'une société était alors attribuée en silence à une
 * société sans rapport. Sur une application qui s'affiche en arabe comme en
 * français, ce n'est pas un cas de bord.
 */
class CleDeRapprochementTest extends TestCase
{
    public function test_la_casse_les_accents_et_la_ponctuation_ne_comptent_pas(): void
    {
        $attendu = CleDeRapprochement::nom('Épicerie Zahra');

        $this->assertSame($attendu, CleDeRapprochement::nom('EPICERIE  ZAHRA'));
        $this->assertSame($attendu, CleDeRapprochement::nom('épicerie-zahra'));
        $this->assertSame('epiceriezahra', $attendu);
    }

    /** LE défaut : un nom arabe ne doit jamais retomber sur la chaîne vide. */
    public function test_un_nom_arabe_donne_une_cle_non_vide(): void
    {
        $cle = CleDeRapprochement::nom('شركة الأمل للتجارة');

        $this->assertNotSame('', $cle, 'Une clé vide ferait collisionner tous les noms arabes.');
    }

    public function test_deux_noms_arabes_differents_ne_se_confondent_pas(): void
    {
        $this->assertNotSame(
            CleDeRapprochement::nom('شركة الأمل للتجارة'),
            CleDeRapprochement::nom('مؤسسة النور'),
            'Deux sociétés distinctes ne doivent pas partager la même clé.',
        );
    }

    public function test_le_meme_nom_arabe_se_reconnait_malgre_la_mise_en_forme(): void
    {
        $this->assertSame(
            CleDeRapprochement::nom('شركة الأمل'),
            CleDeRapprochement::nom('  شركة   الأمل  '),
        );
    }

    /** Vide seulement quand il n'y a vraiment rien de significatif. */
    public function test_un_nom_sans_aucun_caractere_significatif_rend_une_cle_vide(): void
    {
        $this->assertSame('', CleDeRapprochement::nom('   '));
        $this->assertSame('', CleDeRapprochement::nom('--- ...'));
        $this->assertSame('', CleDeRapprochement::nom(null));
    }

    public function test_l_ice_se_compare_sur_ses_chiffres(): void
    {
        $this->assertSame('001234567000089', CleDeRapprochement::chiffres('0012 3456-7000 089'));
        $this->assertSame('', CleDeRapprochement::chiffres(''));
        $this->assertSame('', CleDeRapprochement::chiffres(null));
    }
}
