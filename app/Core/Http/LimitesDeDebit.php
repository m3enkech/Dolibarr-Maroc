<?php

namespace App\Core\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limitation de débit des routes exposées à Internet.
 *
 * Le portail acheteur a ouvert sur Internet des routes anonymes qui hachent un
 * mot de passe : bcrypt au coût 12, soit de l'ordre de 300 ms de processeur par
 * appel. Sur quatre instances d'un vCPU, une douzaine d'appels par seconde
 * suffisent à saturer le service entier — une seule machine y parvient. La
 * limitation n'est donc pas un confort anti-devinette : c'est ce qui tient le
 * service debout.
 *
 * QUATRE PARTIS PRIS, chacun contre un piège précis :
 *
 *  1. TOUS LES LIMITEURS SONT NOMMÉS. La clé par défaut de Laravel ne contient
 *     PAS le chemin de la route : quatre `throttle:5,1` posés sur quatre routes
 *     du même hôte ne font pas quatre limites de 5, ils font UNE limite de 5
 *     partagée. On croirait avoir posé quatre garde-fous, on aurait posé un seul
 *     goulot — et le symptôme (« impossible de s'inscrire ») ne ressemblerait en
 *     rien à la cause. Un limiteur nommé entre dans la clé (`md5($nom.$clé)`),
 *     donc chaque route a son propre seau.
 *
 *  2. ON COMPTE LES ÉCHECS, PAS LES TENTATIVES. Dix employés d'un même magasin
 *     qui se connectent correctement le matin depuis une seule adresse ne
 *     consomment rien. Seuls les ratés remplissent le seau. C'est ce qui rend
 *     une limite basse compatible avec un usage professionnel réel.
 *
 *  3. JAMAIS UNE SEULE CLÉ. Compter par identifiant seul transforme la
 *     protection en arme : n'importe qui martèle l'adresse d'un tiers et
 *     l'enferme dehors sans jamais rien deviner. Compter par adresse IP seule
 *     punit tout un quartier, le trafic mobile marocain sortant largement en
 *     NAT partagé. On pose donc les deux, plus un garde-fou long par identifiant
 *     qui est le seul à voir une attaque répartie sur mille adresses.
 *
 *  4. L'IDENTIFIANT EST HACHÉ ET NORMALISÉ DANS LA CLÉ. Haché parce que le
 *     compteur atterrit dans la table `cache` de la base de production : on n'y
 *     écrit pas les adresses des clients en clair. Normalisé ici parce que les
 *     services le font trop tard — le limiteur s'exécute AVANT le contrôleur, et
 *     sans cela `Patron@X.ma` et `patron@x.ma` seraient deux compteurs.
 *
 * ⚠️ DÉPENDANCE INVISIBLE : tout ceci repose sur `trustProxies` dans
 * bootstrap/app.php. Sans cette ligne, `request()->ip()` vaut l'adresse interne
 * du frontal Cloud Run — la même pour la Terre entière — et un seul attaquant
 * fermerait la connexion pour tout le monde. Le 429 deviendrait alors une arme
 * de déni de service, strictement pire que l'absence de limitation.
 */
class LimitesDeDebit
{
    /**
     * Les seuls codes qui valent « mauvais identifiants ».
     *
     * 401 mot de passe refusé · 403 compte suspendu · 422 formulaire invalide.
     * Tout le reste — 5xx en tête — est une panne du service, pas une tentative.
     */
    private const REFUS_D_IDENTIFIANTS = [401, 403, 422];

    public static function definir(): void
    {
        // --- Connexion : ERP et portail, même politique. ---
        foreach (['connexion-erp', 'connexion-portail'] as $nom) {
            RateLimiter::for($nom, fn (Request $r) => [
                // Le bourrinage d'un compte précis depuis une source précise.
                Limit::perMinute(5)->by(self::coupleIdentifiantEtSource($r))
                    ->after(self::seulementLesEchecs())->response(self::refus()),

                // Le bourrinage réparti : mille adresses, un seul compte.
                Limit::perHour(20)->by(self::empreinte($r))
                    ->after(self::seulementLesEchecs())->response(self::refus()),

                // Le plafond de processeur qu'une source peut consommer. Compte
                // TOUT, succès inclus : c'est du bcrypt dans les deux cas.
                Limit::perMinute(30)->by((string) $r->ip())->response(self::refus()),
            ]);
        }

        // --- Création de compte anonyme : ERP et portail. ---
        //
        // Les deux routes les plus chères de l'application. L'inscription au
        // portail enchaîne DEUX bcrypt (elle hache, puis se connecte dans la
        // foulée) ; l'inscription à l'ERP fabrique une ENTREPRISE entière avec
        // son essai de 14 jours, sans la moindre vérification d'adresse.
        // Un vrai commerçant s'inscrit une fois ; trois par heure ne gênent même
        // pas une démonstration commerciale faite sur place.
        foreach (['inscription-erp', 'inscription-portail'] as $nom) {
            RateLimiter::for($nom, fn (Request $r) => [
                Limit::perHour(3)->by((string) $r->ip())->response(self::refus()),
                Limit::perDay(10)->by((string) $r->ip())->response(self::refus()),
            ]);
        }

        // --- Mot de passe oublié. ---
        //
        // Deux dangers, et le second est le pire. D'abord un vrai courriel part
        // à chaque appel, en synchrone : le quota d'envoi et la réputation de
        // l'expéditeur se consomment en quelques minutes. Ensuite, chaque
        // demande ÉCRASE le jeton précédent — sans plafond par adresse, on
        // appelle la route en boucle sur la victime, chaque lien qu'elle reçoit
        // est invalidé par le suivant, et elle ne peut plus jamais reprendre la
        // main sur son compte. Déni de service sur la récupération de compte,
        // sans qu'aucun mot de passe n'ait été deviné.
        RateLimiter::for('mot-de-passe-oublie', fn (Request $r) => [
            Limit::perMinutes(15, 3)->by(self::empreinte($r))->response(self::refus()),
            Limit::perHour(10)->by((string) $r->ip())->response(self::refus()),
        ]);

        // --- Réinitialisation. ---
        //
        // Le jeton fait 64 caractères aléatoires : le forcer est hors de portée,
        // ce n'est pas le sujet. Le sujet est le bcrypt offert gratuitement à
        // chaque tentative, pendant l'heure où la demande reste ouverte.
        RateLimiter::for('reinitialisation', fn (Request $r) => [
            Limit::perHour(5)->by((string) $r->ip())->response(self::refus()),
            Limit::perHour(5)->by(self::empreinte($r))->response(self::refus()),
        ]);

        // --- Invitations d'équipe, retrouvées par jeton. ---
        //
        // Le jeton fait 48 caractères, donc indevinable ; mais une réponse 200
        // révèle l'adresse invitée, le rôle attribué et le NOM DE LA SOCIÉTÉ.
        // Rien ne bornait les tentatives.
        RateLimiter::for('jeton-invitation', fn (Request $r) => Limit::perMinute(20)
            ->by((string) $r->ip())->response(self::refus()));

        // --- Annuaire public des grossistes. ---
        //
        // Aucun hachage ici, l'enjeu est commercial : la route livre la liste
        // complète des entreprises clientes du SaaS, sans authentification. La
        // limite borne l'aspiration et rend le moissonnage visible dans les
        // journaux. Le vrai correctif — ne lister que les grossistes qui
        // acceptent les demandes, et paginer — reste à faire.
        RateLimiter::for('annuaire', fn (Request $r) => Limit::perMinute(30)
            ->by((string) $r->ip())->response(self::refus()));

        // --- Demande d'accès à un grossiste (acheteur authentifié). ---
        //
        // Couplée à l'annuaire qui livre tous les identifiants de grossistes, un
        // seul compte pourrait déposer une demande chez CHAQUE grossiste de la
        // plateforme et noyer la file de validation de tous les tenants d'un
        // coup. Un commerçant réel travaille avec deux à cinq grossistes.
        RateLimiter::for('demande-acces', fn (Request $r) => Limit::perHour(10)
            ->by(self::acheteurOuSource($r))->response(self::refus()));

        // --- Passage de commande depuis l'écran de suivi. ---
        //
        // Route authentifiée qui ÉCRIT : chaque appel peut créer plusieurs
        // commandes fournisseur et déclenche un calcul de réappro. Compté par
        // compte, pas par adresse — c'est l'utilisateur qui commande.
        RateLimiter::for('commande-reappro', fn (Request $r) => Limit::perMinute(10)
            ->by('utilisateur:'.$r->user()?->getAuthIdentifier())->response(self::refus()));

        // --- Tout le périmètre d'un grossiste, côté acheteur. ---
        //
        // C'est ici que se trouve la route la plus lourde du portail : le rendu
        // du PDF d'une facture. Et c'est ici qu'un concurrent muni d'un compte
        // approuvé aspirerait le catalogue et la grille tarifaire complète.
        // Le plafond est large — un écran du portail fait quelques appels — mais
        // il coupe l'aspiration méthodique.
        RateLimiter::for('portail-grossiste', fn (Request $r) => Limit::perMinute(120)
            ->by(self::acheteurOuSource($r))->response(self::refus()));
    }

    /**
     * Empreinte de l'identifiant présenté, normalisée puis hachée.
     *
     * Normalisée ICI : les services normalisent aussi, mais après le limiteur.
     * Hachée : ces clés sont écrites dans la table `cache` de la production.
     */
    private static function empreinte(Request $request): string
    {
        return sha1(Str::lower(trim((string) $request->input('email'))));
    }

    /** Le couple « ce compte, depuis cette source ». */
    private static function coupleIdentifiantEtSource(Request $request): string
    {
        return self::empreinte($request).'|'.$request->ip();
    }

    /**
     * L'acheteur authentifié, à défaut sa source.
     *
     * Sur ces routes le garde `acheteur` s'est déjà exécuté : compter par compte
     * est plus juste que par adresse, plusieurs commerçants pouvant partager une
     * sortie NAT. Le repli sur l'adresse ne sert qu'à ne jamais laisser la clé
     * vide.
     */
    private static function acheteurOuSource(Request $request): string
    {
        $acheteur = $request->user();

        return $acheteur !== null
            ? 'acheteur:'.$acheteur->getAuthIdentifier()
            : (string) $request->ip();
    }

    /**
     * Ne remplir le seau que si la tentative a échoué.
     *
     * C'est ce qui autorise des plafonds bas sans gêner personne : une connexion
     * réussie ne coûte aucun jeton.
     *
     * ⚠️ « Échec » veut dire IDENTIFIANTS REFUSÉS, et rien d'autre. Compter tout
     * ce qui dépasse 400 paraissait plus sûr ; c'est l'inverse. Une panne — base
     * verrouillée, déploiement en cours, dépendance absente — répond 500, et
     * l'utilisateur qui réessaie pendant la panne remplit son propre seau. Au
     * rétablissement il trouve porte close pour une heure, pour des erreurs dont
     * il n'est pas l'auteur. La panne se prolonge alors d'un blocage qu'elle a
     * elle-même fabriqué, et le journal accuse l'utilisateur.
     *
     * Observé en vrai : un import de reprise a gardé le fichier SQLite pendant
     * une heure, la connexion répondait 500, et chaque essai comptait.
     */
    private static function seulementLesEchecs(): callable
    {
        return fn (Response $reponse) => in_array($reponse->getStatusCode(), self::REFUS_D_IDENTIFIANTS, true);
    }

    /**
     * Le refus, dans la langue de l'appelant.
     *
     * Sans cela, Laravel renvoie « Too Many Attempts. » en dur : un acheteur
     * marocain lirait de l'anglais au milieu d'une interface française ou arabe.
     * La convention du projet veut que la CLÉ de traduction soit la phrase
     * française — la traduction arabe vit dans lang/ar.json.
     */
    private static function refus(): callable
    {
        return function (Request $request, array $entetes) {
            $secondes = (int) ($entetes['Retry-After'] ?? 60);

            return response()->json([
                'message' => __('Trop de tentatives. Réessayez dans :secondes secondes.', [
                    'secondes' => $secondes,
                ]),
            ], Response::HTTP_TOO_MANY_REQUESTS, $entetes);
        };
    }
}
