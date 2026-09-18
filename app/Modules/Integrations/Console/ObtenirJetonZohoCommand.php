<?php

namespace App\Modules\Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Échange un code d'autorisation Zoho contre un jeton de rafraîchissement, et
 * l'écrit dans .env.
 *
 * POURQUOI CETTE COMMANDE EXISTE. Un code d'autorisation et un jeton de
 * rafraîchissement ont EXACTEMENT la même forme — `1000.` + 32 caractères + `.`
 * + 32 caractères — et la réponse de l'échange contient en plus un jeton
 * d'accès de la même allure. Trois valeurs indistinguables à l'œil, dont une
 * seule convient. Recopier la mauvaise ne donne aucun message utile : Zoho
 * répond « invalid_code » bien plus tard, au premier appel d'API.
 *
 * On ne demande donc que le CODE — la valeur la moins sensible, qui expire en
 * quelques minutes et ne sert qu'une fois — et la commande se charge de
 * prendre le bon champ et de l'écrire au bon endroit.
 *
 *   php artisan zoho:jeton 1000.xxxx.yyyy
 *
 * Le jeton n'est JAMAIS affiché : il va directement dans .env.
 */
class ObtenirJetonZohoCommand extends Command
{
    protected $signature = 'zoho:jeton
        {code : le code d\'autorisation fraîchement engendré (Self Client → Generate Code)}
        {--afficher : affiche le jeton au lieu de l\'écrire dans .env}';

    protected $description = 'Échange un code d\'autorisation Zoho contre un jeton de rafraîchissement';

    public function handle(): int
    {
        foreach (['client_id', 'client_secret'] as $cle) {
            if (blank(config("zoho.books.{$cle}"))) {
                $this->error("ZOHO_".strtoupper($cle)." manque dans .env.");

                return self::FAILURE;
            }
        }

        $url = rtrim((string) config('zoho.books.accounts_url'), '/').'/oauth/v2/token';

        $this->line("Échange auprès de {$url}…");

        $reponse = Http::asForm()->post($url, [
            'grant_type' => 'authorization_code',
            'client_id' => config('zoho.books.client_id'),
            'client_secret' => config('zoho.books.client_secret'),
            'code' => trim($this->argument('code')),
        ]);

        $jeton = $reponse->json('refresh_token');

        if (blank($jeton)) {
            $erreur = (string) $reponse->json('error', 'réponse sans refresh_token');

            $this->error("Zoho a refusé l'échange : {$erreur}");
            $this->newLine();

            $this->line(match ($erreur) {
                'invalid_code' => "Le code a expiré (quelques minutes) ou a DÉJÀ été échangé — il ne sert qu'une fois. "
                    .'Engendrez-en un nouveau et relancez aussitôt.',
                'invalid_client', 'invalid_client_secret' => 'client_id ou client_secret incorrect, ou mauvais centre '
                    .'de données : un compte européen exige accounts.zoho.eu.',
                default => "Vérifiez que le code vient bien de l'application dont le client_id est dans .env.",
            });

            // Le cas le plus fréquent, et le plus déroutant : Zoho rend aussi un
            // access_token, de forme identique. S'il est là sans refresh_token,
            // c'est que le code avait déjà servi.
            if (filled($reponse->json('access_token'))) {
                $this->newLine();
                $this->warn('Zoho a renvoyé un jeton d\'accès mais AUCUN jeton de rafraîchissement : '
                    .'ce code avait déjà été échangé. Il en faut un neuf.');
            }

            return self::FAILURE;
        }

        if ($this->option('afficher')) {
            $this->newLine();
            $this->warn('Jeton de rafraîchissement (à garder secret) :');
            $this->line($jeton);

            return self::SUCCESS;
        }

        $this->ecrireDansEnv($jeton);

        $this->newLine();
        $this->info('ZOHO_REFRESH_TOKEN écrit dans .env. Le jeton n\'a pas été affiché.');
        $this->line('Vérifiez maintenant avec :');
        $this->line('  php artisan zoho:import-tiers <email> --simulation');

        return self::SUCCESS;
    }

    /** Remplace la ligne si elle existe, l'ajoute sinon. */
    private function ecrireDansEnv(string $jeton): void
    {
        $chemin = base_path('.env');
        $contenu = file_get_contents($chemin);
        $ligne = "ZOHO_REFRESH_TOKEN={$jeton}";

        $contenu = preg_match('/^ZOHO_REFRESH_TOKEN=.*$/m', $contenu)
            ? preg_replace('/^ZOHO_REFRESH_TOKEN=.*$/m', $ligne, $contenu)
            : rtrim($contenu, "\n")."\n".$ligne."\n";

        file_put_contents($chemin, $contenu);
    }
}
