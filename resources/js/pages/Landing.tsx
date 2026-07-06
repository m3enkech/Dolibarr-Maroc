import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '@/lib/auth';

/* ------------------------------------------------------------------ */
/* Icônes SVG inline (style trait, cohérentes avec l'identité du site) */
/* ------------------------------------------------------------------ */

function Icon({ d, className = 'h-6 w-6' }: { d: string; className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            aria-hidden
        >
            <path d={d} />
        </svg>
    );
}

/** Drapeau marocain dessiné en SVG (les emoji drapeaux ne s'affichent pas sous Windows). */
function MorocFlag({ className = 'h-3.5 w-5' }: { className?: string }) {
    return (
        <svg viewBox="0 0 30 20" className={className} aria-label="Maroc" role="img">
            <rect width="30" height="20" rx="2.5" fill="#c1272d" />
            <path
                d="M15 4.2 L18.53 15.05 L9.29 8.35 L20.71 8.35 L11.47 15.05 Z"
                fill="none"
                stroke="#006233"
                strokeWidth="1"
                strokeLinejoin="round"
            />
        </svg>
    );
}

const icons = {
    fileText: 'M14 3v5h5 M9 13h6 M9 17h6 M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z',
    cart: 'M6 6h15l-1.5 8.5a2 2 0 0 1-2 1.6H9a2 2 0 0 1-2-1.7L5 3H2 M9.5 20.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2z M17.5 20.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
    box: 'M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8z M3.3 7l8.7 5 8.7-5 M12 22V12',
    calculator:
        'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z M8.5 7h7 M8.5 11h.01 M12 11h.01 M15.5 11h.01 M8.5 14.5h.01 M12 14.5h.01 M15.5 14.5h.01 M8.5 18h.01 M12 18h.01 M15.5 18h.01',
    percent:
        'M19 5L5 19 M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z M17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M23 21v-2a4 4 0 0 0-3-3.9 M16 3.1a4 4 0 0 1 0 7.8',
    tag: 'M20.6 13.4L11 3H4v7l9.6 9.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8z M7.5 7.5h.01',
    bank: 'M3 22h18 M6 18v-8 M10 18v-8 M14 18v-8 M18 18v-8 M12 2L3 7h18l-9-5z',
    chart: 'M3 3v18h18 M7 15v3 M11 10v8 M15 6v12 M19 12v6',
    shield: 'M12 22s8-3 8-10V5l-8-3-8 3v7c0 7 8 10 8 10z M9 12l2 2 4-4',
    zap: 'M13 2L3 14h7l-1 8 10-12h-7l1-8z',
    check: 'M20 6L9 17l-5-5',
    globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M3 12h18 M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z',
    download: 'M12 3v12 M7 10l5 5 5-5 M4 21h16',
};

/* ------------------------------------------------------------------ */
/* Contenu                                                             */
/* ------------------------------------------------------------------ */

const features = [
    {
        icon: icons.fileText,
        title: 'Ventes',
        text: 'Devis, commandes et factures enchaînés en un clic. PDF professionnels avec ventilation de la TVA et numérotation conforme.',
    },
    {
        icon: icons.cart,
        title: 'Achats',
        text: 'Commandes fournisseurs, réceptions partielles et factures. Le stock et la comptabilité se mettent à jour automatiquement.',
    },
    {
        icon: icons.box,
        title: 'Stock',
        text: 'Multi-entrepôts, mouvements tracés, quantités en commande et ajustements d\'inventaire en temps réel.',
    },
    {
        icon: icons.calculator,
        title: 'Comptabilité CGNC',
        text: 'Plan comptable marocain pré-chargé, écritures générées automatiquement, lettrage, balance et clôture d\'exercice.',
    },
    {
        icon: icons.percent,
        title: 'TVA & SIMPL',
        text: 'État de TVA mensuel et export Excel au format exact de la DGI (relevé de déductions SIMPL-TVA), prêt à télédéclarer.',
    },
    {
        icon: icons.users,
        title: 'Clients & fournisseurs',
        text: 'Fiches tiers complètes avec identifiants marocains : ICE (validé à 15 chiffres), IF, RC, Patente et CNSS.',
    },
    {
        icon: icons.tag,
        title: 'Catalogue',
        text: 'Produits et services avec les taux de TVA marocains (0, 7, 10, 14, 20 %) et catégories comptables paramétrables.',
    },
    {
        icon: icons.bank,
        title: 'Immobilisations',
        text: 'Plan d\'amortissement linéaire, dotations comptabilisées automatiquement, cessions avec TVA collectée.',
    },
    {
        icon: icons.chart,
        title: 'Bilan & CPC',
        text: 'États de synthèse générés depuis vos écritures, e-facturation UBL prête pour l\'échéance DGI 2026.',
    },
];

const marocPoints = [
    {
        title: 'Plan comptable CGNC pré-chargé',
        text: 'Le plan comptable général marocain est installé dès la création de votre compte. Chaque vente et chaque achat génèrent leurs écritures selon vos mappings.',
    },
    {
        title: 'Export SIMPL-TVA au format DGI',
        text: 'Le relevé de déductions (modèle ADC082F) et le chiffre d\'affaires sont exportés en Excel, exactement au format attendu par Simpl-TVA.',
    },
    {
        title: 'Identifiants légaux intégrés',
        text: 'ICE validé à 15 chiffres, IF, RC, Patente, CNSS sur chaque tiers — et repris automatiquement sur vos documents et déclarations.',
    },
    {
        title: 'Prêt pour la facture électronique',
        text: 'Génération de factures électroniques UBL 2.1 pour anticiper l\'obligation DGI qui se déploie à partir de 2026.',
    },
    {
        title: 'Reprise de votre comptabilité',
        text: 'Importez votre balance d\'ouverture depuis Excel ou CSV : les à-nouveaux sont passés automatiquement, en équilibre garanti.',
    },
    {
        title: 'Taux de TVA marocains',
        text: 'Seuls les taux légaux (0, 7, 10, 14 et 20 %) sont proposés. La TVA est calculée ligne par ligne, côté serveur.',
    },
];

interface Plan {
    name: string;
    tagline: string;
    monthly: number;
    annual: number;
    features: string[];
    featured?: boolean;
}

const plans: Plan[] = [
    {
        name: 'Essentiel',
        tagline: 'Pour facturer en toute conformité',
        monthly: 99,
        annual: 79,
        features: [
            '1 utilisateur',
            'Clients & fournisseurs illimités',
            'Devis, commandes et factures PDF',
            'Catalogue produits & services',
            'Taux de TVA marocains',
            'Support par email',
        ],
    },
    {
        name: 'Business',
        tagline: 'Pour piloter toute votre gestion',
        monthly: 249,
        annual: 199,
        featured: true,
        features: [
            '5 utilisateurs',
            'Tout le plan Essentiel',
            'Achats & réceptions fournisseurs',
            'Stock multi-entrepôts',
            'Comptabilité automatique CGNC',
            'État de TVA + export SIMPL-TVA',
            'Balance d\'ouverture (reprise Excel)',
            'Support prioritaire',
        ],
    },
    {
        name: 'Premium',
        tagline: 'Pour une conformité totale',
        monthly: 499,
        annual: 399,
        features: [
            'Utilisateurs illimités',
            'Tout le plan Business',
            'Immobilisations & amortissements',
            'Lettrage & clôture d\'exercice',
            'Bilan & CPC',
            'E-facturation UBL (DGI 2026)',
            'Accompagnement à la mise en route',
        ],
    },
];

const faqs = [
    {
        q: 'Le logiciel est-il conforme à la réglementation marocaine ?',
        a: 'Oui. La comptabilité suit le plan comptable CGNC, les taux de TVA sont les taux légaux marocains, et l\'état de TVA s\'exporte au format Excel attendu par Simpl-TVA (relevé de déductions ADC082F). La facture électronique UBL 2.1 est déjà prête pour l\'échéance DGI.',
    },
    {
        q: 'Puis-je reprendre ma comptabilité existante ?',
        a: 'Oui. Vous importez votre balance d\'ouverture depuis un fichier Excel ou CSV (colonnes Compte / Libellé / Débit / Crédit). L\'écriture d\'à-nouveaux est générée automatiquement, avec vérification de l\'équilibre, et les comptes manquants sont créés.',
    },
    {
        q: 'Faut-il être comptable pour l\'utiliser ?',
        a: 'Non. Vous créez vos devis, factures et achats normalement : les écritures comptables sont générées automatiquement en arrière-plan. Votre comptable retrouve ensuite une comptabilité propre, lettrable et prête pour la liasse.',
    },
    {
        q: 'Mes données sont-elles en sécurité ?',
        a: 'Chaque entreprise dispose d\'un espace strictement isolé. Les connexions sont chiffrées et vos données vous appartiennent : vous pouvez exporter vos écritures et déclarations à tout moment.',
    },
    {
        q: 'Puis-je changer de plan en cours de route ?',
        a: 'Oui, à tout moment. Le changement est immédiat et la facturation est ajustée au prorata. Vous pouvez aussi résilier quand vous le souhaitez : il n\'y a aucun engagement de durée.',
    },
    {
        q: 'Comment démarrer ?',
        a: 'Créez votre compte en 2 minutes : votre entreprise, votre email, un mot de passe. Vous profitez de 14 jours d\'essai gratuit sur toutes les fonctionnalités, sans carte bancaire.',
    },
];

/* ------------------------------------------------------------------ */
/* Sections                                                            */
/* ------------------------------------------------------------------ */

function Logo({ dark = false }: { dark?: boolean }) {
    return (
        <span className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 text-sm font-bold text-white">
                DM
            </span>
            <span className={`text-lg font-semibold ${dark ? 'text-white' : 'text-slate-900'}`}>
                Dolibarr <span className="text-emerald-600">Maroc</span>
            </span>
        </span>
    );
}

function Header() {
    const { isAuthenticated } = useAuth();
    const [open, setOpen] = useState(false);

    const links = [
        { href: '#fonctionnalites', label: 'Fonctionnalités' },
        { href: '#maroc', label: 'Conçu pour le Maroc' },
        { href: '#tarifs', label: 'Tarifs' },
        { href: '#faq', label: 'FAQ' },
    ];

    return (
        <header className="sticky top-0 z-40 border-b border-slate-200/70 bg-white/85 backdrop-blur">
            <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6">
                <a href="#" className="shrink-0">
                    <Logo />
                </a>
                <nav className="hidden items-center gap-7 md:flex">
                    {links.map((l) => (
                        <a
                            key={l.href}
                            href={l.href}
                            className="text-sm font-medium text-slate-600 transition hover:text-slate-900"
                        >
                            {l.label}
                        </a>
                    ))}
                </nav>
                <div className="hidden items-center gap-3 md:flex">
                    {isAuthenticated ? (
                        <Link
                            to="/dashboard"
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                        >
                            Ouvrir mon espace →
                        </Link>
                    ) : (
                        <>
                            <Link
                                to="/login"
                                className="rounded-lg px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
                            >
                                Se connecter
                            </Link>
                            <Link
                                to="/register"
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700"
                            >
                                Essai gratuit
                            </Link>
                        </>
                    )}
                </div>
                <button
                    onClick={() => setOpen((o) => !o)}
                    className="rounded-md p-2 text-slate-600 hover:bg-slate-100 md:hidden"
                    aria-label="Menu"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="h-6 w-6">
                        {open ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
                    </svg>
                </button>
            </div>
            {open && (
                <div className="border-t border-slate-200 bg-white px-4 py-3 md:hidden">
                    <nav className="flex flex-col gap-1">
                        {links.map((l) => (
                            <a
                                key={l.href}
                                href={l.href}
                                onClick={() => setOpen(false)}
                                className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            >
                                {l.label}
                            </a>
                        ))}
                        <div className="mt-2 flex gap-2 border-t border-slate-100 pt-3">
                            {isAuthenticated ? (
                                <Link
                                    to="/dashboard"
                                    className="flex-1 rounded-lg bg-emerald-600 px-4 py-2 text-center text-sm font-medium text-white"
                                >
                                    Ouvrir mon espace
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        to="/login"
                                        className="flex-1 rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-700"
                                    >
                                        Se connecter
                                    </Link>
                                    <Link
                                        to="/register"
                                        className="flex-1 rounded-lg bg-emerald-600 px-4 py-2 text-center text-sm font-medium text-white"
                                    >
                                        Essai gratuit
                                    </Link>
                                </>
                            )}
                        </div>
                    </nav>
                </div>
            )}
        </header>
    );
}

/** Maquette stylisée du tableau de bord, dessinée en CSS (aucune image). */
function HeroMockup() {
    const bars = [42, 58, 38, 70, 55, 82, 64, 90, 74, 60, 88, 96];
    return (
        <div className="relative">
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/10">
                {/* Barre de fenêtre */}
                <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
                    <span className="h-2.5 w-2.5 rounded-full bg-red-300" />
                    <span className="h-2.5 w-2.5 rounded-full bg-amber-300" />
                    <span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
                    <span className="ml-3 hidden rounded-md bg-white px-2 py-0.5 text-[10px] text-slate-400 ring-1 ring-slate-200 sm:block">
                        app.dolibarr-maroc.ma
                    </span>
                </div>
                <div className="flex">
                    {/* Mini sidebar */}
                    <div className="hidden w-32 shrink-0 flex-col gap-1 bg-slate-900 p-3 sm:flex">
                        <div className="mb-2 h-2 w-16 rounded bg-slate-700" />
                        {['bg-emerald-500 text-white', '', '', '', '', ''].map((c, i) => (
                            <div
                                key={i}
                                className={`h-6 rounded-md ${c ? 'bg-emerald-600' : 'bg-slate-800'}`}
                            />
                        ))}
                    </div>
                    {/* Contenu */}
                    <div className="flex-1 space-y-3 p-4">
                        <div className="grid grid-cols-3 gap-3">
                            {[
                                { label: 'CA du mois', value: '128 450 DH', accent: 'text-emerald-600' },
                                { label: 'Factures impayées', value: '3', accent: 'text-amber-600' },
                                { label: 'TVA due', value: '12 340 DH', accent: 'text-slate-800' },
                            ].map((c) => (
                                <div key={c.label} className="rounded-lg border border-slate-100 bg-slate-50 p-2.5">
                                    <div className="text-[9px] font-medium uppercase tracking-wide text-slate-400">
                                        {c.label}
                                    </div>
                                    <div className={`mt-1 text-sm font-bold ${c.accent}`}>{c.value}</div>
                                </div>
                            ))}
                        </div>
                        {/* Graphique en barres */}
                        <div className="rounded-lg border border-slate-100 p-3">
                            <div className="mb-2 flex items-center justify-between">
                                <div className="h-2 w-20 rounded bg-slate-200" />
                                <div className="h-2 w-10 rounded bg-emerald-200" />
                            </div>
                            <div className="flex h-20 items-end gap-1.5">
                                {bars.map((h, i) => (
                                    <div
                                        key={i}
                                        style={{ height: `${h}%` }}
                                        className={`flex-1 rounded-t ${i === bars.length - 1 ? 'bg-emerald-500' : 'bg-emerald-200'}`}
                                    />
                                ))}
                            </div>
                        </div>
                        {/* Lignes de tableau */}
                        <div className="space-y-1.5">
                            {['FA-2026-00042', 'FA-2026-00041', 'FA-2026-00040'].map((ref, i) => (
                                <div
                                    key={ref}
                                    className="flex items-center justify-between rounded-md border border-slate-100 px-2.5 py-1.5"
                                >
                                    <span className="text-[10px] font-medium text-slate-600">{ref}</span>
                                    <span className="h-1.5 w-16 rounded bg-slate-100" />
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[9px] font-medium ${
                                            i === 1
                                                ? 'bg-amber-50 text-amber-700'
                                                : 'bg-emerald-50 text-emerald-700'
                                        }`}
                                    >
                                        {i === 1 ? 'En attente' : 'Payée'}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
            {/* Badges flottants */}
            <div className="absolute -left-4 -bottom-5 hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-lg sm:flex">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <Icon d={icons.check} className="h-4 w-4" />
                </span>
                <div>
                    <div className="text-[11px] font-semibold text-slate-800">Écriture comptable générée</div>
                    <div className="text-[10px] text-slate-400">Journal VT · 3411 / 7111 / 4441</div>
                </div>
            </div>
            <div className="absolute -right-3 -top-5 hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-lg lg:flex">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <Icon d={icons.download} className="h-4 w-4" />
                </span>
                <div className="text-[11px] font-semibold text-slate-800">SIMPL-TVA exporté</div>
            </div>
        </div>
    );
}

function Hero() {
    return (
        <section className="relative overflow-hidden bg-gradient-to-b from-emerald-50/60 via-white to-white">
            {/* Halo décoratif */}
            <div
                className="pointer-events-none absolute inset-0"
                style={{
                    background:
                        'radial-gradient(600px 300px at 20% 0%, rgba(16,185,129,0.10), transparent 70%), radial-gradient(500px 300px at 90% 10%, rgba(16,185,129,0.08), transparent 70%)',
                }}
            />
            <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
                <div>
                    <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                        <span aria-hidden>🇲🇦</span> Conforme CGNC · SIMPL-TVA · E-facture 2026
                    </div>
                    <h1 className="text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
                        La gestion de votre entreprise, <span className="text-emerald-600">conforme au Maroc</span>
                    </h1>
                    <p className="mt-5 max-w-xl text-lg leading-relaxed text-slate-600">
                        Devis, factures, achats, stock et comptabilité CGNC dans une seule plateforme. Les
                        écritures se passent toutes seules, la TVA s'exporte au format DGI.
                    </p>
                    <div className="mt-8 flex flex-wrap items-center gap-3">
                        <Link
                            to="/register"
                            className="rounded-lg bg-emerald-600 px-6 py-3 text-sm font-semibold text-white shadow-md shadow-emerald-600/20 transition hover:bg-emerald-700"
                        >
                            Commencer gratuitement
                        </Link>
                        <a
                            href="#tarifs"
                            className="rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            Voir les tarifs
                        </a>
                    </div>
                    <p className="mt-4 text-sm text-slate-500">
                        14 jours d'essai gratuit · Sans carte bancaire · Sans engagement
                    </p>
                </div>
                <HeroMockup />
            </div>
        </section>
    );
}

function TrustBar() {
    const items = [
        { icon: icons.shield, label: 'Plan comptable CGNC' },
        { icon: icons.download, label: 'Export SIMPL-TVA (DGI)' },
        { icon: icons.users, label: 'ICE · IF · RC · CNSS' },
        { icon: icons.zap, label: 'E-facturation UBL 2.1' },
    ];
    return (
        <div className="border-y border-slate-200 bg-slate-50">
            <div className="mx-auto grid max-w-6xl grid-cols-2 gap-4 px-4 py-6 sm:px-6 md:grid-cols-4">
                {items.map((i) => (
                    <div key={i.label} className="flex items-center justify-center gap-2 text-sm font-medium text-slate-600">
                        <span className="text-emerald-600">
                            <Icon d={i.icon} className="h-5 w-5" />
                        </span>
                        {i.label}
                    </div>
                ))}
            </div>
        </div>
    );
}

function Features() {
    return (
        <section id="fonctionnalites" className="scroll-mt-20 py-20">
            <div className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="mx-auto max-w-2xl text-center">
                    <h2 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                        Tout votre cycle de gestion, connecté
                    </h2>
                    <p className="mt-4 text-lg text-slate-600">
                        Chaque module alimente les autres : une facture validée met à jour le stock, la
                        comptabilité et votre état de TVA. Zéro ressaisie.
                    </p>
                </div>
                <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {features.map((f) => (
                        <div
                            key={f.title}
                            className="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-emerald-200 hover:shadow-lg hover:shadow-emerald-600/5"
                        >
                            <div className="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 transition group-hover:bg-emerald-600 group-hover:text-white">
                                <Icon d={f.icon} />
                            </div>
                            <h3 className="text-base font-semibold text-slate-900">{f.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-slate-600">{f.text}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function MarocSection() {
    return (
        <section id="maroc" className="scroll-mt-20 bg-slate-900 py-20">
            <div className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="mx-auto max-w-2xl text-center">
                    <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-400">
                        <span aria-hidden>🇲🇦</span> 100 % réglementation marocaine
                    </div>
                    <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Conçu pour le Maroc, pas adapté après coup
                    </h2>
                    <p className="mt-4 text-lg text-slate-400">
                        Les logiciels génériques ignorent la DGI, le CGNC et l'ICE. Dolibarr Maroc les intègre
                        nativement, du devis jusqu'à la télédéclaration.
                    </p>
                </div>
                <div className="mt-14 grid gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
                    {marocPoints.map((p) => (
                        <div key={p.title} className="flex gap-4">
                            <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-400">
                                <Icon d={icons.check} className="h-3.5 w-3.5" />
                            </span>
                            <div>
                                <h3 className="font-semibold text-white">{p.title}</h3>
                                <p className="mt-1.5 text-sm leading-relaxed text-slate-400">{p.text}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Pricing() {
    const [annual, setAnnual] = useState(false);
    return (
        <section id="tarifs" className="scroll-mt-20 bg-slate-50 py-20">
            <div className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="mx-auto max-w-2xl text-center">
                    <h2 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                        Des tarifs simples et transparents
                    </h2>
                    <p className="mt-4 text-lg text-slate-600">
                        14 jours d'essai gratuit sur tous les plans. Sans carte bancaire, sans engagement.
                    </p>
                    {/* Bascule mensuel / annuel */}
                    <div className="mt-8 inline-flex items-center rounded-full border border-slate-200 bg-white p-1 text-sm font-medium">
                        <button
                            onClick={() => setAnnual(false)}
                            className={`rounded-full px-4 py-1.5 transition ${
                                !annual ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600'
                            }`}
                        >
                            Mensuel
                        </button>
                        <button
                            onClick={() => setAnnual(true)}
                            className={`rounded-full px-4 py-1.5 transition ${
                                annual ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600'
                            }`}
                        >
                            Annuel <span className={annual ? 'text-emerald-100' : 'text-emerald-600'}>−20 %</span>
                        </button>
                    </div>
                </div>
                <div className="mt-12 grid gap-8 lg:grid-cols-3">
                    {plans.map((p) => (
                        <div
                            key={p.name}
                            className={`relative flex flex-col rounded-2xl border bg-white p-8 ${
                                p.featured
                                    ? 'border-emerald-600 shadow-xl shadow-emerald-600/10 lg:-my-3'
                                    : 'border-slate-200 shadow-sm'
                            }`}
                        >
                            {p.featured && (
                                <span className="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-emerald-600 px-4 py-1 text-xs font-semibold text-white">
                                    Le plus populaire
                                </span>
                            )}
                            <h3 className="text-lg font-semibold text-slate-900">{p.name}</h3>
                            <p className="mt-1 text-sm text-slate-500">{p.tagline}</p>
                            <div className="mt-6 flex items-baseline gap-1.5">
                                <span className="text-4xl font-bold tracking-tight text-slate-900">
                                    {annual ? p.annual : p.monthly}
                                </span>
                                <span className="text-sm font-medium text-slate-500">DH HT / mois</span>
                            </div>
                            <p className="mt-1 text-xs text-slate-400">
                                {annual ? 'Facturé annuellement' : `ou ${p.annual} DH/mois en annuel`}
                            </p>
                            <ul className="mt-7 flex-1 space-y-3">
                                {p.features.map((f) => (
                                    <li key={f} className="flex items-start gap-2.5 text-sm text-slate-700">
                                        <span className="mt-0.5 text-emerald-600">
                                            <Icon d={icons.check} className="h-4 w-4" />
                                        </span>
                                        {f}
                                    </li>
                                ))}
                            </ul>
                            <Link
                                to="/register"
                                className={`mt-8 rounded-lg px-4 py-2.5 text-center text-sm font-semibold transition ${
                                    p.featured
                                        ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/20 hover:bg-emerald-700'
                                        : 'border border-slate-300 text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                Commencer l'essai gratuit
                            </Link>
                        </div>
                    ))}
                </div>
                <p className="mt-10 text-center text-sm text-slate-500">
                    Besoin d'un déploiement sur mesure ou de plus d'utilisateurs ?{' '}
                    <a href="mailto:contact@dolibarr-maroc.ma" className="font-medium text-emerald-600 hover:underline">
                        Contactez-nous
                    </a>
                </p>
            </div>
        </section>
    );
}

function Faq() {
    return (
        <section id="faq" className="scroll-mt-20 py-20">
            <div className="mx-auto max-w-3xl px-4 sm:px-6">
                <div className="text-center">
                    <h2 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                        Questions fréquentes
                    </h2>
                </div>
                <div className="mt-10 space-y-3">
                    {faqs.map((f) => (
                        <details
                            key={f.q}
                            className="group rounded-xl border border-slate-200 bg-white px-5 py-4 open:border-emerald-200 open:shadow-sm"
                        >
                            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-slate-900 [&::-webkit-details-marker]:hidden">
                                {f.q}
                                <span className="shrink-0 text-slate-400 transition group-open:rotate-45 group-open:text-emerald-600">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="h-5 w-5">
                                        <path d="M12 5v14M5 12h14" />
                                    </svg>
                                </span>
                            </summary>
                            <p className="mt-3 text-sm leading-relaxed text-slate-600">{f.a}</p>
                        </details>
                    ))}
                </div>
            </div>
        </section>
    );
}

function FinalCta() {
    return (
        <section className="px-4 pb-20 sm:px-6">
            <div className="mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 to-emerald-700 px-6 py-14 text-center shadow-xl shadow-emerald-600/20 sm:px-14">
                <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                    Prêt à moderniser votre gestion ?
                </h2>
                <p className="mx-auto mt-4 max-w-xl text-lg text-emerald-50">
                    Créez votre espace en 2 minutes et testez toutes les fonctionnalités gratuitement
                    pendant 14 jours.
                </p>
                <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <Link
                        to="/register"
                        className="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-emerald-700 shadow-md transition hover:bg-emerald-50"
                    >
                        Créer mon compte gratuitement
                    </Link>
                    <Link
                        to="/login"
                        className="rounded-lg border border-emerald-400/60 px-6 py-3 text-sm font-semibold text-white transition hover:bg-emerald-600/60"
                    >
                        J'ai déjà un compte
                    </Link>
                </div>
            </div>
        </section>
    );
}

function Footer() {
    return (
        <footer className="border-t border-slate-800 bg-slate-900">
            <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
                <div className="grid gap-10 md:grid-cols-4">
                    <div className="md:col-span-2">
                        <Logo dark />
                        <p className="mt-4 max-w-sm text-sm leading-relaxed text-slate-400">
                            L'ERP en ligne des PME marocaines : ventes, achats, stock et comptabilité CGNC,
                            avec la TVA prête pour la DGI.
                        </p>
                    </div>
                    <div>
                        <h3 className="text-sm font-semibold text-white">Produit</h3>
                        <ul className="mt-4 space-y-2.5 text-sm text-slate-400">
                            <li>
                                <a href="#fonctionnalites" className="transition hover:text-white">
                                    Fonctionnalités
                                </a>
                            </li>
                            <li>
                                <a href="#tarifs" className="transition hover:text-white">
                                    Tarifs
                                </a>
                            </li>
                            <li>
                                <a href="#faq" className="transition hover:text-white">
                                    FAQ
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h3 className="text-sm font-semibold text-white">Compte</h3>
                        <ul className="mt-4 space-y-2.5 text-sm text-slate-400">
                            <li>
                                <Link to="/login" className="transition hover:text-white">
                                    Se connecter
                                </Link>
                            </li>
                            <li>
                                <Link to="/register" className="transition hover:text-white">
                                    Créer un compte
                                </Link>
                            </li>
                            <li>
                                <a href="mailto:contact@dolibarr-maroc.ma" className="transition hover:text-white">
                                    Contact
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div className="mt-10 flex flex-col items-center justify-between gap-3 border-t border-slate-800 pt-6 text-xs text-slate-500 sm:flex-row">
                    <span>© 2026 Dolibarr Maroc. Tous droits réservés.</span>
                    <span>Fait avec soin au Maroc 🇲🇦</span>
                </div>
            </div>
        </footer>
    );
}

/* ------------------------------------------------------------------ */

export default function Landing() {
    return (
        <div className="bg-white text-slate-900">
            <Header />
            <main>
                <Hero />
                <TrustBar />
                <Features />
                <MarocSection />
                <Pricing />
                <Faq />
                <FinalCta />
            </main>
            <Footer />
        </div>
    );
}
