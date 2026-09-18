<?php

namespace Tests\Unit;

use App\Core\Format\MontantEnLettres;
use PHPUnit\Framework\TestCase;

/**
 * La mention en toutes lettres des factures marocaines (article 145 du CGI).
 *
 * C'est elle qui fait foi en cas de litige sur un chiffre mal imprimé : une
 * erreur ici ne se voit pas à la relecture et se découvre devant un tribunal.
 */
class MontantEnLettresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\NumberFormatter::class)) {
            $this->markTestSkipped('L\'extension intl est absente de cet environnement.');
        }
    }

    public function test_le_montant_de_la_facture_de_reference(): void
    {
        // La facture MDK26-00263 : 720,00 DH.
        $this->assertSame('Sept cent vingt dirhams', MontantEnLettres::pour(720));
    }

    public function test_la_devise_est_nommee_et_accordee(): void
    {
        $this->assertSame('Un dirham', MontantEnLettres::pour(1));
        $this->assertSame('Deux dirhams', MontantEnLettres::pour(2));
        $this->assertSame('Zéro dirham', MontantEnLettres::pour(0));
    }

    /**
     * LE défaut du modèle d'origine : il écrivait « Sept cent vingt » pour
     * 720,50. La mention censée lever l'ambiguïté en créait une de cinquante
     * centimes.
     */
    public function test_les_centimes_ne_disparaissent_pas(): void
    {
        $this->assertSame('Sept cent vingt dirhams et cinquante centimes', MontantEnLettres::pour(720.5));
        $this->assertSame('Sept cent vingt dirhams et un centime', MontantEnLettres::pour(720.01));
    }

    /**
     * Le piège de la virgule flottante : (0.29 - 0) * 100 vaut 28,999… en
     * binaire. Sans arrondi, la facture annoncerait vingt-huit centimes.
     */
    public function test_les_centimes_ne_perdent_pas_une_unite_par_arrondi(): void
    {
        foreach ([0.29, 1.29, 12.29, 123.29, 1234.29] as $montant) {
            $this->assertStringEndsWith(
                'vingt-neuf centimes',
                MontantEnLettres::pour($montant),
                "Centimes faux pour {$montant}.",
            );
        }
    }

    /** 720,999 fait 721 dirhams, jamais « sept cent vingt et cent centimes ». */
    public function test_un_montant_qui_arrondit_au_dirham_superieur(): void
    {
        $this->assertSame('Sept cent vingt-et-un dirhams', MontantEnLettres::pour(720.999));
    }

    /**
     * Les pièges du français : quatre-vingts qui prend un s seul, cent qui
     * s'accorde sauf devant un autre nombre. ICU écrit « soixante-et-onze » et
     * « vingt-et-un » avec traits d'union — c'est l'orthographe rectifiée de
     * 1990, valide et aujourd'hui recommandée.
     */
    public function test_la_grammaire_francaise_des_nombres(): void
    {
        $this->assertSame('Quatre-vingts dirhams', MontantEnLettres::pour(80));
        $this->assertSame('Quatre-vingt-un dirhams', MontantEnLettres::pour(81));
        $this->assertSame('Soixante-et-onze dirhams', MontantEnLettres::pour(71));
        $this->assertSame('Deux cents dirhams', MontantEnLettres::pour(200));
        $this->assertSame('Deux cent un dirhams', MontantEnLettres::pour(201));
    }

    public function test_un_montant_de_facture_realiste(): void
    {
        $this->assertSame(
            'Quatre mille sept cent soixante-huit dirhams et quatre-vingt-dix centimes',
            MontantEnLettres::pour(4768.90),
        );
    }

    /** Un avoir porte un montant négatif ; la mention doit rester lisible. */
    public function test_un_montant_negatif_se_dit(): void
    {
        $this->assertSame('Moins cent dirhams', MontantEnLettres::pour(-100));
    }

    public function test_une_autre_devise_change_le_nom_et_la_subdivision(): void
    {
        $this->assertSame('Dix euros et cinq centimes', MontantEnLettres::pour(10.05, 'EUR'));
        $this->assertSame('Dix dollars et cinq cents', MontantEnLettres::pour(10.05, 'USD'));
    }

    /** Une devise inconnue ne fait pas tomber la facture : on reste en dirhams. */
    public function test_une_devise_inconnue_retombe_sur_le_dirham(): void
    {
        $this->assertSame('Dix dirhams', MontantEnLettres::pour(10, 'XYZ'));
    }
}
