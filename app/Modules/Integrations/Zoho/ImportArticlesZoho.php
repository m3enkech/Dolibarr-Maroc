<?php

namespace App\Modules\Integrations\Zoho;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Services\ProduitService;
use Illuminate\Support\Str;

/**
 * Rapatrie le catalogue de Zoho Books dans les produits Dolibarr.
 *
 * Les articles précèdent les factures, et pas l'inverse : une ligne de facture
 * sans `produit_id` est un libellé mort — elle s'affiche, mais elle ne compte
 * ni dans l'état du stock, ni dans le réappro fondé sur les ventes, ni dans le
 * détail par dépôt. Reprendre d'abord le catalogue, c'est ce qui donne quatre
 * ans d'historique de ventes exploitable.
 *
 * QUATRE PARTIS PRIS.
 *
 * 1. LE SKU DEVIENT LA RÉFÉRENCE DOLIBARR quand il tient dans la colonne et
 *    qu'il est libre. « T-TN211 » est ce que les équipes cherchent et dictent
 *    au téléphone ; leur servir « PR-0042 » à la place leur ferait perdre leur
 *    catalogue. À défaut — SKU vide, trop long, ou déjà pris — la séquence
 *    maison reprend la main, et le rapport le dit.
 *
 * 2. LE TYPE SUIT `product_type`, PAS `track_inventory`. Books ne suit AUCUN
 *    stock dans cette organisation : tous les articles y sont à
 *    `track_inventory: false`. S'y fier ferait de tout le catalogue des
 *    services, c'est-à-dire des articles sans stock — et le type d'un produit
 *    ne se change plus ensuite. `product_type` dit ce que l'article EST
 *    (« goods » ou « service »), indépendamment de ce que Books comptait.
 *
 * 3. UN TAUX DE TVA HORS BARÈME MAROCAIN EST RAMENÉ À 20 %, avec mention au
 *    rapport. Un taux exotique passerait à l'écriture mais rendrait la fiche
 *    produit impossible à enregistrer ensuite : la validation n'accepte que
 *    0, 7, 10, 14 et 20.
 *
 * 4. ON N'ÉCRASE JAMAIS UNE DONNÉE EXISTANTE, et on rapproche d'abord sur
 *    l'identifiant Zoho — mêmes règles que pour les tiers.
 */
class ImportArticlesZoho
{
    /** Longueur de la colonne `code`. */
    private const LONGUEUR_CODE = 20;

    public function __construct(
        private ZohoBooksClient $zoho,
        private ProduitService $produits,
    ) {}

    /**
     * @param  bool  $simulation  n'écrit rien, mais rend le même rapport
     * @return array{crees: int, mis_a_jour: int, inchanges: int, details: list<array<string, string>>}
     */
    public function executer(bool $simulation = false, ?callable $progression = null): array
    {
        $rapport = ['crees' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'details' => []];

        // Trois index chargés une fois : une requête par article ferait quinze
        // cents requêtes pour un catalogue de quinze cents références.
        //
        // Les entrées sont des repères `[id, source_id]` et non des modèles :
        // une création SIMULÉE n'a pas d'identifiant, et c'est justement ce qui
        // la distingue. `source_id` dit à qui le produit appartient déjà.
        $tous = Produit::query()->get(['id', 'code', 'name', 'source_id']);
        $repere = fn (Produit $p) => ['id' => $p->id, 'source_id' => $p->source_id];

        $parSource = $tous->filter(fn (Produit $p) => filled($p->source_id))->keyBy('source_id')->map($repere);
        $parCode = $tous->keyBy(fn (Produit $p) => Str::lower($p->code))->map($repere);
        $parNom = $tous->keyBy(fn (Produit $p) => $this->normaliserNom($p->name))->map($repere);

        foreach ($this->zoho->articles() as $article) {
            $resultat = $this->traiter($article, $parSource, $parCode, $parNom, $simulation);

            $rapport[$resultat['issue']]++;
            $rapport['details'][] = $resultat['detail'];

            if ($progression !== null) {
                $progression($resultat['detail']);
            }
        }

        return $rapport;
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{issue: string, detail: array<string, string>}
     */
    private function traiter(array $article, $parSource, $parCode, $parNom, bool $simulation): array
    {
        $nom = trim((string) ($article['name'] ?? ''));
        $zohoId = (string) ($article['item_id'] ?? '');
        $sku = trim((string) ($article['sku'] ?? ''));

        $detail = [
            'zoho_id' => $zohoId,
            'sku' => $sku,
            'nom' => $nom,
        ];

        if ($nom === '') {
            return ['issue' => 'inchanges', 'detail' => $detail + [
                'action' => 'ecarte',
                'raison' => 'article sans nom',
            ]];
        }

        $existant = $this->rapprocher($zohoId, $sku, $nom, $parSource, $parCode, $parNom);

        // Rencontré plus tôt DANS CET IMPORT, alors qu'on ne l'a pas écrit :
        // c'est une création simulée, qui n'a donc pas d'identifiant. Le vrai
        // import rapprochera les deux articles sans rien changer — la
        // simulation doit annoncer le même compte, sans quoi elle promet des
        // créations qui n'auront pas lieu.
        if ($existant !== null && $existant['id'] === null) {
            return ['issue' => 'inchanges', 'detail' => $detail + [
                'action' => 'inchange',
                'raison' => 'même article déjà vu dans cet import',
            ]];
        }

        if ($existant !== null) {
            $produit = Produit::find($existant['id']);

            if ($produit === null) {
                return ['issue' => 'inchanges', 'detail' => $detail + [
                    'action' => 'ecarte', 'raison' => 'produit introuvable après rapprochement',
                ]];
            }

            $changements = $this->champsACompleter($produit, $article, $zohoId);

            if ($changements === []) {
                return ['issue' => 'inchanges', 'detail' => $detail + [
                    'action' => 'inchange', 'raison' => 'déjà présent',
                ]];
            }

            if (! $simulation) {
                $this->produits->update($produit, $changements);
            }

            return ['issue' => 'mis_a_jour', 'detail' => $detail + [
                'action' => 'complete',
                'raison' => implode(', ', array_keys($changements)),
            ]];
        }

        [$code, $raisonCode] = $this->codeALui($sku, $parCode);
        [$tva, $raisonTva] = $this->tvaLegale($article);

        $produit = $simulation
            ? null
            : $this->produits->create($this->donneesDeCreation($article, $nom, $code, $tva, $zohoId));

        // En simulation il n'y a pas d'identifiant à retenir, et c'est ce vide
        // qui trahira le doublon au passage suivant.
        $cree = ['id' => $produit?->id, 'source_id' => $zohoId ?: null];

        if ($zohoId !== '') {
            $parSource->put($zohoId, $cree);
        }
        // La référence réellement attribuée, quand il y en a une : c'est elle
        // qui doit bloquer le SKU du prochain article qui voudrait la même.
        if ($reference = $produit?->code ?? $code) {
            $parCode->put(Str::lower($reference), $cree);
        }

        $parNom->put($this->normaliserNom($nom), $cree);

        return ['issue' => 'crees', 'detail' => $detail + [
            'action' => 'cree',
            'raison' => trim($raisonCode.' '.$raisonTva),
        ]];
    }

    /**
     * Le produit qui correspond à cet article, s'il existe.
     *
     * L'identifiant Zoho d'abord — une certitude. Puis le SKU, puis le nom, qui
     * ne sont que des ressemblances : elles ne servent qu'à reconnaître un
     * produit saisi À LA MAIN dans Dolibarr. Un produit qui appartient DÉJÀ à
     * un AUTRE article de Books n'est pas un candidat : deux articles distincts
     * chez Books doivent rester deux produits distincts ici, même s'ils
     * partagent un SKU ou un libellé. Sans ce garde-fou, le second disparaîtrait
     * dans le premier et ses ventes iraient au mauvais article.
     *
     * @return array{id: ?int, source_id: ?string}|null
     */
    private function rapprocher(string $zohoId, string $sku, string $nom, $parSource, $parCode, $parNom): ?array
    {
        if ($zohoId !== '' && ($parSource->get($zohoId) !== null)) {
            return $parSource->get($zohoId);
        }

        $pistes = [
            $sku !== '' ? $parCode->get(Str::lower($sku)) : null,
            $parNom->get($this->normaliserNom($nom)),
        ];

        foreach ($pistes as $piste) {
            if ($piste !== null && (blank($piste['source_id']) || $piste['source_id'] === $zohoId)) {
                return $piste;
            }
        }

        return null;
    }

    /**
     * La référence du produit : le SKU quand c'est possible, la séquence sinon.
     *
     * @return array{0: ?string, 1: string}  le code (null = séquence), et sa raison
     */
    private function codeALui(string $sku, $parCode): array
    {
        if ($sku === '') {
            return [null, 'référence attribuée (pas de SKU chez Books)'];
        }

        if (mb_strlen($sku) > self::LONGUEUR_CODE) {
            return [null, 'référence attribuée (SKU trop long)'];
        }

        if ($parCode->has(Str::lower($sku))) {
            return [null, 'référence attribuée (SKU déjà utilisé)'];
        }

        return [$sku, 'référence reprise du SKU'];
    }

    /**
     * Le barème marocain n'admet que 0, 7, 10, 14 et 20. Tout le reste est
     * ramené au taux normal, faute de quoi la fiche produit deviendrait
     * inenregistrable par l'interface.
     *
     * @return array{0: float, 1: string}
     */
    private function tvaLegale(array $article): array
    {
        $taux = (float) ($article['tax_percentage'] ?? 20);

        if (in_array($taux, array_map('floatval', Produit::TVA_RATES), true)) {
            return [$taux, ''];
        }

        return [20.0, sprintf('— TVA %s %% hors barème, ramenée à 20 %%', rtrim(rtrim((string) $taux, '0'), '.'))];
    }

    /**
     * @return array<string, mixed>
     */
    private function donneesDeCreation(array $article, string $nom, ?string $code, float $tva, string $zohoId): array
    {
        $donnees = [
            'name' => Str::limit($nom, 250, ''),
            'description' => ($article['description'] ?? '') ?: null,
            'type' => ($article['product_type'] ?? 'goods') === 'service'
                ? Produit::TYPE_SERVICE
                : Produit::TYPE_PRODUCT,
            'sell_price' => round((float) ($article['rate'] ?? 0), 2),
            'buy_price' => ($article['purchase_rate'] ?? 0) > 0
                ? round((float) $article['purchase_rate'], 2)
                : null,
            'tva_rate' => $tva,
            'unit' => Str::limit(trim((string) ($article['unit'] ?? '')), 20, '') ?: null,
            'barcode' => $this->codeBarres($article),
            'is_active' => ($article['status'] ?? 'active') === 'active',
            'source_systeme' => ImportTiersZoho::SOURCE,
            'source_id' => $zohoId ?: null,
        ];

        if ($code !== null) {
            $donnees['code'] = $code;
        }

        return $donnees;
    }

    /**
     * Ce qu'on peut compléter sans rien écraser.
     *
     * Le TYPE et le CODE n'y figurent pas : ils sont immuables une fois posés,
     * et le service les écarte de toute façon.
     *
     * @return array<string, mixed>
     */
    private function champsACompleter(Produit $produit, array $article, string $zohoId): array
    {
        $changements = [];

        if ($zohoId !== '' && blank($produit->source_id)) {
            $changements['source_systeme'] = ImportTiersZoho::SOURCE;
            $changements['source_id'] = $zohoId;
        }

        $candidats = [
            'description' => ($article['description'] ?? '') ?: null,
            'unit' => Str::limit(trim((string) ($article['unit'] ?? '')), 20, '') ?: null,
            'barcode' => $this->codeBarres($article),
            'buy_price' => ($article['purchase_rate'] ?? 0) > 0 ? round((float) $article['purchase_rate'], 2) : null,
        ];

        foreach ($candidats as $champ => $valeur) {
            if (blank($produit->{$champ}) && filled($valeur)) {
                $changements[$champ] = $valeur;
            }
        }

        // Un prix de vente à zéro n'est pas une décision, c'est un champ vide.
        if ((float) $produit->sell_price <= 0 && (float) ($article['rate'] ?? 0) > 0) {
            $changements['sell_price'] = round((float) $article['rate'], 2);
        }

        return $changements;
    }

    private function codeBarres(array $article): ?string
    {
        foreach (['ean', 'upc', 'isbn'] as $champ) {
            $valeur = trim((string) ($article[$champ] ?? ''));

            if ($valeur !== '' && mb_strlen($valeur) <= 30) {
                return $valeur;
            }
        }

        return null;
    }

    /** Casse, accents et espaces neutralisés — repli quand le SKU manque. */
    private function normaliserNom(?string $nom): string
    {
        $sansAccent = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $nom) ?: (string) $nom;

        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($sansAccent)) ?? '';
    }
}
