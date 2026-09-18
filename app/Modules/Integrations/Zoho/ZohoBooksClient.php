<?php

namespace App\Modules\Integrations\Zoho;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Accès en lecture à Zoho Books.
 *
 * Volontairement minimal : s'authentifier, paginer, rien d'autre. La traduction
 * vers le domaine Dolibarr appartient aux services d'import, pas ici.
 *
 * TROIS PARTIS PRIS.
 *
 * 1. LE JETON D'ACCÈS EST MIS EN CACHE, PAS LE JETON DE RAFRAÎCHISSEMENT. Le
 *    premier vaut une heure et se redemande ; le second ne périme pas et reste
 *    dans .env. Les redemander à chaque appel épuiserait le quota d'API pour
 *    rien — et le cache est partagé entre instances (magasin `database`).
 *
 * 2. LA PAGINATION EST UN GÉNÉRATEUR. Un import de plusieurs milliers de
 *    factures ne doit jamais tenir en mémoire d'un coup : l'appelant consomme
 *    page par page et n'en garde que ce qu'il écrit.
 *
 * 3. AUCUN SECRET NE PART DANS UN JOURNAL. Les erreurs remontent le statut et
 *    le message de Zoho, jamais le corps de la requête d'authentification.
 */
class ZohoBooksClient
{
    /** Le jeton vaut une heure ; on le renouvelle un peu avant, par prudence. */
    private const DUREE_JETON_SECONDES = 3300;

    public function estConfigure(): bool
    {
        foreach (['client_id', 'client_secret', 'refresh_token', 'organization_id'] as $cle) {
            if (blank(config("zoho.books.{$cle}"))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les contacts d'un type donné, page après page.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function contacts(string $type): Generator
    {
        yield from $this->paginer('/contacts', 'contacts', ['contact_type' => $type]);
    }

    /**
     * Les articles du catalogue, page après page.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function articles(array $filtres = []): Generator
    {
        yield from $this->paginer('/items', 'items', $filtres);
    }

    /**
     * Les factures, éventuellement bornées dans le temps.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function factures(array $filtres = []): Generator
    {
        yield from $this->paginer('/invoices', 'invoices', $filtres);
    }

    /** Le détail d'une facture, avec ses lignes — la liste ne les porte pas. */
    public function facture(string $id): array
    {
        return $this->requete()->get($this->url("/invoices/{$id}"), [
            'organization_id' => config('zoho.books.organization_id'),
        ])->throw()->json('invoice', []);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function paginer(string $chemin, string $cleListe, array $params = []): Generator
    {
        $page = 1;
        $pause = (int) config('zoho.books.pause_entre_pages_ms');

        do {
            $reponse = $this->requete()->get($this->url($chemin), array_merge($params, [
                'organization_id' => config('zoho.books.organization_id'),
                'page' => $page,
                'per_page' => 200,
            ]));

            if ($reponse->failed()) {
                throw new RuntimeException(sprintf(
                    'Zoho Books a refusé %s (page %d) : %s %s',
                    $chemin,
                    $page,
                    $reponse->status(),
                    (string) $reponse->json('message', ''),
                ));
            }

            // Un `yield from` sur un tableau réémettrait les clés 0, 1, 2… à
            // chaque page : les pages s'écraseraient mutuellement dès qu'un
            // appelant rassemble le générateur en tableau, et l'import ne
            // verrait qu'un enregistrement par page sans rien signaler.
            foreach ($reponse->json($cleListe, []) as $enregistrement) {
                yield $enregistrement;
            }

            $encore = (bool) $reponse->json('page_context.has_more_page', false);
            $page++;

            if ($encore && $pause > 0) {
                usleep($pause * 1000);
            }
        } while ($encore);
    }

    /**
     * Jeton d'accès, obtenu du jeton de rafraîchissement.
     *
     * La clé de cache porte l'empreinte du client : changer d'application Zoho
     * dans .env ne doit pas ressusciter le jeton de la précédente.
     */
    private function jeton(): string
    {
        $empreinte = substr(sha1((string) config('zoho.books.client_id')), 0, 12);

        return Cache::remember("zoho:books:jeton:{$empreinte}", self::DUREE_JETON_SECONDES, function (): string {
            $reponse = Http::asForm()->post(
                rtrim((string) config('zoho.books.accounts_url'), '/').'/oauth/v2/token',
                [
                    'grant_type' => 'refresh_token',
                    'client_id' => config('zoho.books.client_id'),
                    'client_secret' => config('zoho.books.client_secret'),
                    'refresh_token' => config('zoho.books.refresh_token'),
                ],
            );

            $jeton = $reponse->json('access_token');

            if (! $reponse->successful() || blank($jeton)) {
                // ⚠️ Zoho répond 200 même pour un refus : c'est l'absence
                // d'access_token qui fait foi, pas le code HTTP.
                //
                // On remonte le code d'erreur de Zoho et sa cause probable,
                // jamais le corps envoyé : il contient le secret.
                $erreur = (string) $reponse->json('error', 'réponse sans access_token');

                throw new RuntimeException(sprintf(
                    'Zoho a refusé le jeton (%s) : %s. %s',
                    $reponse->status(),
                    $erreur,
                    $this->causeProbable($erreur),
                ));
            }

            return $jeton;
        });
    }

    /**
     * Traduire le code d'erreur de Zoho en cause concrète.
     *
     * Ces trois refus se ressemblent et n'ont rien à voir : envoyer quelqu'un
     * vérifier le centre de données alors que son jeton est simplement périmé
     * lui fait perdre une heure. Chaque cas a sa piste.
     */
    private function causeProbable(string $erreur): string
    {
        return match ($erreur) {
            'invalid_client' => 'Le plus souvent le CENTRE DE DONNÉES : un compte européen ne répond pas sur '
                .'accounts.zoho.com. Vérifiez ZOHO_ACCOUNTS_URL, puis le client_id et le client_secret.',

            'invalid_code' => 'ZOHO_REFRESH_TOKEN n\'est pas un jeton de rafraîchissement valide. Deux causes '
                .'fréquentes : (1) c\'est le CODE D\'AUTORISATION qui a été collé — il a exactement la même forme, '
                .'mais ne sert qu\'une fois et expire en quelques minutes ; (2) le client_secret a été régénéré '
                .'APRÈS la création du jeton, ce qui l\'invalide. Dans les deux cas : régénérez un code, '
                .'échangez-le, et collez le champ « refresh_token » de la réponse.',

            'invalid_grant' => 'Le jeton a été révoqué côté Zoho, ou l\'application a été supprimée. Il faut en '
                .'engendrer un nouveau.',

            default => 'Vérifiez ZOHO_ACCOUNTS_URL, le client_id, le client_secret et le refresh_token.',
        };
    }

    private function requete(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$this->jeton()])
            ->timeout(30)
            ->retry(2, 500);
    }

    private function url(string $chemin): string
    {
        return rtrim((string) config('zoho.books.api_url'), '/').$chemin;
    }
}
