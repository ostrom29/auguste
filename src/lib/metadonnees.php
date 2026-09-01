<?php

declare(strict_types=1);

/**
 * Ce que les machines lisent : moteurs de recherche, messageries, réseaux.
 *
 * Tout vient du même Sheet que la page visible. Le restaurateur ne saisit rien
 * de plus : ses horaires servent à la fois au pied de page et au bloc que
 * Google affiche dans ses résultats.
 */

require_once __DIR__ . '/texte.php';
require_once __DIR__ . '/transformation.php';

/** L'image de partage, préparée par outils/images.py au format attendu. */
const PARTAGE_IMAGE = 'img/partage.jpg';
const PARTAGE_LARGEUR = 1200;
const PARTAGE_HAUTEUR = 630;

/**
 * Les balises <head> qui décrivent la page aux machines.
 *
 * @param array<string, string> $infos
 * @return list<string>
 */
function metadonnees(array $infos, string $fichier, string $titre, string $description, string $urlSite): array
{
    $balises = [
        '  <meta name="description" content="' . e($description) . '">',
    ];

    // Sans URL de site configurée, on n'émet pas d'URL absolues fausses :
    // une balise canonique erronée fait plus de dégâts que pas de balise.
    if ($urlSite === '') {
        return $balises;
    }

    $url = $urlSite . '/' . ($fichier === 'index.html' ? '' : $fichier);
    // Empreinte comprise : les réseaux gardent l'aperçu en cache eux aussi,
    // et une image de partage changée doit se voir.
    $image = $urlSite . '/' . ressource(PARTAGE_IMAGE);
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);

    return array_merge($balises, [
        '  <link rel="canonical" href="' . e($url) . '">',
        '',
        '  <!-- Ce que voient WhatsApp, Facebook, Slack quand on colle le lien. -->',
        '  <meta property="og:type" content="restaurant.restaurant">',
        '  <meta property="og:locale" content="fr_FR">',
        '  <meta property="og:site_name" content="' . e($nom) . '">',
        '  <meta property="og:title" content="' . e($titre) . '">',
        '  <meta property="og:description" content="' . e($description) . '">',
        '  <meta property="og:url" content="' . e($url) . '">',
        '  <meta property="og:image" content="' . e($image) . '">',
        '  <meta property="og:image:width" content="' . PARTAGE_LARGEUR . '">',
        '  <meta property="og:image:height" content="' . PARTAGE_HAUTEUR . '">',
        '  <meta name="twitter:card" content="summary_large_image">',
    ]);
}

/**
 * La description : celle du Sheet si elle existe, sinon une phrase construite
 * à partir de ce qu'on sait déjà.
 *
 * @param array<string, string> $infos
 */
function description_site(array $infos): string
{
    $sienne = info($infos, 'description');

    if ($sienne !== '') {
        return $sienne;
    }

    $morceaux = [info($infos, 'nom', NOM_PAR_DEFAUT), info($infos, 'accroche', ACCROCHE_PAR_DEFAUT)];
    $adresse = info($infos, 'adresse');

    if ($adresse !== '') {
        $morceaux[] = $adresse;
    }

    return implode(' — ', $morceaux) . '.';
}

/**
 * Le bloc JSON-LD décrivant le restaurant.
 *
 * C'est ce qui permet à Google d'afficher les horaires et l'adresse
 * directement dans ses résultats, et d'alimenter sa fiche Maps.
 *
 * @param array<string, string> $infos
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 */
function json_ld(array $infos, array $categories, string $urlSite): string
{
    if ($urlSite === '') {
        return '';
    }

    $donnees = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Restaurant',
        'name' => info($infos, 'nom', NOM_PAR_DEFAUT),
        'description' => description_site($infos),
        'url' => $urlSite . '/',
        'image' => $urlSite . '/' . PARTAGE_IMAGE,
        'servesCuisine' => 'Française',
        'menu' => $urlSite . '/carte.html',
        'address' => adresse_postale($infos),
        // Le lien Maps et les réseaux nourrissent la fiche que Google affiche
        // à côté des résultats.
        'hasMap' => lien_externe($infos, 'maps') ?: null,
        'sameAs' => array_values(array_filter([
            lien_externe($infos, 'instagram'),
            lien_externe($infos, 'facebook'),
        ])),
        'telephone' => telephone_international($infos),
        'email' => info($infos, 'email') ?: null,
        'priceRange' => gamme_de_prix($categories),
        'openingHoursSpecification' => horaires_structures($infos),
        // Suit l'état réel du site : dire à Google qu'on réserve quand la page
        // n'existe pas enverrait des clients sur une porte fermée.
        'acceptsReservations' => reservation_active($infos) ? 'True' : 'False',
        'potentialAction' => reservation_active($infos) ? action_reserver($urlSite) : null,
    ], static fn ($valeur): bool => $valeur !== null && $valeur !== [] && $valeur !== '');

    return implode("\n", [
        '  <script type="application/ld+json">',
        // JSON_UNESCAPED_UNICODE : les accents restent lisibles à la relecture.
        (string) json_encode($donnees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        '  </script>',
    ]);
}

/**
 * Déclare l'adresse où réserver : c'est ce qui permet à Google de proposer
 * l'action plutôt que de la deviner.
 *
 * @return array<string, mixed>
 */
function action_reserver(string $urlSite): array
{
    return [
        '@type' => 'ReserveAction',
        'target' => [
            '@type' => 'EntryPoint',
            'urlTemplate' => $urlSite . '/reservation.php',
            'inLanguage' => 'fr',
            'actionPlatform' => [
                'https://schema.org/DesktopWebPlatform',
                'https://schema.org/MobileWebPlatform',
            ],
        ],
        'result' => ['@type' => 'FoodEstablishmentReservation', 'name' => 'Demande de réservation'],
    ];
}

/**
 * L'adresse découpée comme schema.org l'attend.
 *
 * On ne devine que ce qui est sûr : le code postal et la ville se lisent à la
 * fin d'une adresse française. Si la forme ne s'y prête pas, on ne découpe pas.
 *
 * @param array<string, string> $infos
 * @return array<string, string>|null
 */
function adresse_postale(array $infos): ?array
{
    $adresse = info($infos, 'adresse');

    if ($adresse === '') {
        return null;
    }

    $postale = ['@type' => 'PostalAddress', 'addressCountry' => 'FR'];

    if (preg_match('/^(.*?),?\s*(\d{5})\s+(.+)$/u', $adresse, $trouve) === 1) {
        $postale['streetAddress'] = trim($trouve[1], " \t,");
        $postale['postalCode'] = $trouve[2];
        $postale['addressLocality'] = trim($trouve[3]);

        return $postale;
    }

    $postale['streetAddress'] = $adresse;

    return $postale;
}

/**
 * @param array<string, string> $infos
 */
function telephone_international(array $infos): ?string
{
    $telephone = info($infos, 'telephone');

    return $telephone === '' ? null : lien_telephone($telephone);
}

/**
 * « 1,80 € à 15,90 € » devient « €€ » : schema.org attend une gamme, pas un
 * tarif. Un bouillon est bon marché, et c'est un argument.
 *
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 */
function gamme_de_prix(array $categories): ?string
{
    $maximum = 0.0;

    foreach ($categories as $categorie) {
        foreach ($categorie['plats'] as $plat) {
            $maximum = max($maximum, prix_numerique((string) $plat['prix']));
        }
    }

    if ($maximum <= 0.0) {
        return null;
    }

    // Seuils usuels de schema.org pour la restauration en France.
    return match (true) {
        $maximum < 15.0 => '€',
        $maximum < 30.0 => '€€',
        $maximum < 60.0 => '€€€',
        default => '€€€€',
    };
}

/**
 * Relit un prix déjà mis en forme (« 12,90 € ») pour le comparer.
 */
function prix_numerique(string $formate): float
{
    $nombre = str_replace([ESPACE_INSECABLE, '€', ' '], '', $formate);

    return (float) str_replace(',', '.', $nombre);
}

/**
 * Les horaires du Sheet, découpés en créneaux que schema.org sait lire.
 *
 * « 12h00-14h30, 19h00-22h30 » donne deux créneaux ; « fermé » n'en donne
 * aucun, ce qui est la bonne façon de dire qu'on est fermé.
 *
 * @param array<string, string> $infos
 * @return list<array<string, string>>
 */
function horaires_structures(array $infos): array
{
    $anglais = [
        'lundi' => 'Monday', 'mardi' => 'Tuesday', 'mercredi' => 'Wednesday',
        'jeudi' => 'Thursday', 'vendredi' => 'Friday', 'samedi' => 'Saturday',
        'dimanche' => 'Sunday',
    ];

    $creneaux = [];

    foreach ($anglais as $jour => $traduction) {
        foreach (decouper_creneaux(info($infos, 'horaires_' . $jour)) as $creneau) {
            $creneaux[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/' . $traduction,
                'opens' => $creneau[0],
                'closes' => $creneau[1],
            ];
        }
    }

    return $creneaux;
}

/**
 * « 12h00-14h30, 19h00-22h30 » → [['12:00','14:30'], ['19:00','22:30']].
 *
 * @return list<array{0: string, 1: string}>
 */
function decouper_creneaux(string $horaire): array
{
    $creneaux = [];
    $motif = '/(\d{1,2})\s*[h:]\s*(\d{2})?\s*[-–—]\s*(\d{1,2})\s*[h:]\s*(\d{2})?/u';

    if (preg_match_all($motif, $horaire, $trouves, PREG_SET_ORDER) === 0) {
        return $creneaux;
    }

    foreach ($trouves as $t) {
        $creneaux[] = [
            sprintf('%02d:%s', (int) $t[1], $t[2] !== '' ? $t[2] : '00'),
            sprintf('%02d:%s', (int) $t[3], ($t[4] ?? '') !== '' ? $t[4] : '00'),
        ];
    }

    return $creneaux;
}
