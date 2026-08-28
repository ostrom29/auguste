<?php

declare(strict_types=1);

/**
 * Rendu HTML des deux pages du site.
 *
 * Rien n'est concaténé sans passer par `e()` : tout ce qui vient du Sheet est
 * échappé au moment de l'écriture.
 */

require_once __DIR__ . '/texte.php';

/** Jours de la semaine, dans l'ordre d'affichage. */
const JOURS_SEMAINE = [
    'lundi' => 'Lundi',
    'mardi' => 'Mardi',
    'mercredi' => 'Mercredi',
    'jeudi' => 'Jeudi',
    'vendredi' => 'Vendredi',
    'samedi' => 'Samedi',
    'dimanche' => 'Dimanche',
];

const NOM_PAR_DEFAUT = 'Chez Auguste';
const ACCROCHE_PAR_DEFAUT = 'Bouillon-brasserie';

/**
 * Lit une info du Sheet, en repliant sur un défaut.
 *
 * Une clé présente mais vide vaut une clé absente : dans un tableur, effacer
 * une cellule laisse la ligne en place. C'est le cas le plus fréquent, et
 * l'opérateur `??` seul ne l'attrape pas — il ne teste que l'absence.
 *
 * @param array<string, string> $infos
 */
function info(array $infos, string $cle, string $defaut = ''): string
{
    $valeur = trim($infos[$cle] ?? '');

    return $valeur !== '' ? $valeur : $defaut;
}

/** La photo d'ambiance, dans ses trois largeurs préparées par outils/images.py. */
const SALLE_LARGEURS = [340, 480, 679];
const SALLE_HAUTEUR = 452;

// ---------------------------------------------------------------------------
// Les deux pages
// ---------------------------------------------------------------------------

/**
 * Page d'accueil : ce qu'on cherche en arrivant, dans l'ordre où on le cherche.
 *
 * @param list<array<string, mixed>> $vedettes
 * @param array<string, string> $infos
 */
function rendre_accueil(array $vedettes, array $infos): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);

    return document(
        'accueil',
        e($nom) . ' — ' . e(info($infos, 'accroche', ACCROCHE_PAR_DEFAUT)),
        implode("\n", array_filter([
            rendre_entete($infos, 'accueil'),
            rendre_banniere($infos),
            '  <main id="contenu">',
            rendre_message($infos),
            rendre_coordonnees($infos),
            rendre_vedettes($vedettes),
            '  </main>',
            rendre_pied($infos),
        ], static fn (string $bloc): bool => $bloc !== ''))
    );
}

/**
 * La carte complète.
 *
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 * @param array<string, string> $infos
 */
function rendre_carte(array $categories, array $infos): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);
    $sections = [];

    foreach ($categories as $categorie) {
        $sections[] = '    <section class="categorie">';
        $sections[] = '      <h2 class="categorie__titre">' . e($categorie['nom']) . '</h2>';
        $sections[] = '      <ul class="categorie__plats">';

        foreach ($categorie['plats'] as $plat) {
            array_push($sections, ...rendre_plat($plat, 3, '        '));
        }

        $sections[] = '      </ul>';
        $sections[] = '    </section>';
    }

    return document(
        'carte',
        'Carte — ' . e($nom),
        implode("\n", array_filter([
            rendre_entete($infos, 'carte'),
            '  <main id="contenu" class="carte">',
            rendre_message($infos),
            implode("\n", $sections),
            '    <p class="note-allergenes">Une information sur les allergènes est disponible en salle. '
                . 'N’hésitez pas à nous interroger.</p>',
            '  </main>',
            rendre_pied($infos),
        ], static fn (string $bloc): bool => $bloc !== ''))
    );
}

// ---------------------------------------------------------------------------
// Blocs partagés
// ---------------------------------------------------------------------------

function document(string $classe, string $titre, string $corps): string
{
    $html = implode("\n", [
        '<!DOCTYPE html>',
        '<html lang="fr">',
        '<head>',
        '  <meta charset="utf-8">',
        '  <meta name="viewport" content="width=device-width, initial-scale=1">',
        '  <title>' . $titre . '</title>',
        '  <link rel="stylesheet" href="style.css">',
        '  <link rel="icon" href="img/favicon.png" type="image/png">',
        '</head>',
        '<body class="page page--' . $classe . '">',
        '  <!-- Page générée par src/build.php : toute modification à la main sera écrasée. -->',
        '  <a class="evitement" href="#contenu">Aller au contenu</a>',
        $corps,
        '</body>',
        '</html>',
        '',
    ]);

    // L'espace insécable est écrit en entité pour rester visible à la relecture.
    return str_replace(ESPACE_INSECABLE, '&#160;', $html);
}

/**
 * @param array<string, string> $infos
 */
function rendre_entete(array $infos, string $page): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);
    $accroche = info($infos, 'accroche', ACCROCHE_PAR_DEFAUT);

    // Sur l'accueil le logo est le titre de la page ; sur la carte, il n'est
    // qu'un lien de retour, et le titre revient à la carte elle-même.
    $logo = '<img class="entete__logo" src="img/logo.png" alt="' . e($nom) . '"'
        . ' width="440" height="322" srcset="img/logo.png 440w, img/logo-2x.png 880w"'
        . ' sizes="(min-width: 40rem) 20rem, 60vw">';

    if ($page === 'accueil') {
        return implode("\n", [
            '  <header class="entete">',
            '    <h1 class="entete__titre">' . $logo . '</h1>',
            '    <p class="entete__accroche">' . e($accroche) . '</p>',
            '  </header>',
        ]);
    }

    return implode("\n", [
        '  <header class="entete entete--compacte">',
        '    <a class="entete__retour" href="index.html">' . $logo . '</a>',
        '    <h1 class="entete__titre">La carte</h1>',
        '  </header>',
    ]);
}

/**
 * @param array<string, string> $infos
 */
function rendre_banniere(array $infos): string
{
    $legende = info($infos, 'legende_photo');

    // Échappatoire : « photo » à « non » dans le Sheet retire la bannière.
    if (mb_strtolower(info($infos, 'photo', 'oui'), 'UTF-8') === 'non') {
        return '';
    }

    $webp = [];
    $jpeg = [];

    foreach (SALLE_LARGEURS as $largeur) {
        $webp[] = sprintf('img/salle-%1$d.webp %1$dw', $largeur);
        $jpeg[] = sprintf('img/salle-%1$d.jpg %1$dw', $largeur);
    }

    $tailles = '(min-width: 46rem) 44rem, 100vw';
    $pleine = max(SALLE_LARGEURS);

    $lignes = [
        '  <figure class="banniere">',
        '    <picture>',
        '      <source type="image/webp" srcset="' . implode(', ', $webp) . '" sizes="' . $tailles . '">',
        '      <img src="img/salle-' . $pleine . '.jpg" srcset="' . implode(', ', $jpeg) . '"'
            . ' sizes="' . $tailles . '" width="' . $pleine . '" height="' . SALLE_HAUTEUR . '"'
            . ' alt="' . e($legende !== '' ? $legende : 'La salle du restaurant') . '" decoding="async">',
        '    </picture>',
    ];

    if ($legende !== '') {
        $lignes[] = '    <figcaption class="banniere__legende">' . e($legende) . '</figcaption>';
    }

    $lignes[] = '  </figure>';

    return implode("\n", $lignes);
}

/**
 * Le message du Sheet — fermeture annuelle, jour férié. Rien s'il est vide.
 *
 * @param array<string, string> $infos
 */
function rendre_message(array $infos): string
{
    $message = info($infos, 'message');

    if ($message === '') {
        return '';
    }

    return '    <p class="message" role="status">' . e($message) . '</p>';
}

/**
 * @param array<string, string> $infos
 */
function rendre_coordonnees(array $infos): string
{
    $adresse = info($infos, 'adresse');
    $acces = info($infos, 'acces');
    $telephone = info($infos, 'telephone');
    $lignes = [];

    if ($adresse !== '') {
        $lignes[] = '        <p class="coordonnees__adresse">' . e($adresse) . '</p>';
    }

    if ($acces !== '') {
        $lignes[] = '        <p class="coordonnees__acces">' . e($acces) . '</p>';
    }

    if ($lignes === [] && $telephone === '') {
        return '';
    }

    $bloc = [
        '    <section class="coordonnees">',
        '      <h2 class="coordonnees__titre">Nous trouver</h2>',
        '      <address class="coordonnees__adresse-bloc">',
    ];

    array_push($bloc, ...$lignes);
    $bloc[] = '      </address>';

    if ($telephone !== '') {
        $bloc[] = '      <p class="coordonnees__appel">'
            . '<a class="bouton" href="tel:' . e(lien_telephone($telephone)) . '">'
            . 'Appeler le ' . e($telephone) . '</a></p>';
    }

    $bloc[] = '    </section>';

    return implode("\n", $bloc);
}

/**
 * @param list<array<string, mixed>> $vedettes
 */
function rendre_vedettes(array $vedettes): string
{
    if ($vedettes === []) {
        return '';
    }

    $lignes = [
        '    <section class="vedettes">',
        '      <h2 class="vedettes__titre">Quelques incontournables</h2>',
        '      <ul class="vedettes__plats">',
    ];

    foreach ($vedettes as $plat) {
        array_push($lignes, ...rendre_plat($plat, 3, '        '));
    }

    $lignes[] = '      </ul>';
    $lignes[] = '      <p class="vedettes__lien"><a class="bouton bouton--discret" href="carte.html">'
        . 'Voir toute la carte</a></p>';
    $lignes[] = '    </section>';

    return implode("\n", $lignes);
}

/**
 * Un plat. Le niveau de titre change selon la page, la structure non.
 *
 * @param array<string, mixed> $plat
 * @return list<string>
 */
function rendre_plat(array $plat, int $niveau, string $marge): array
{
    $h = 'h' . $niveau;

    $lignes = [
        $marge . '<li class="plat">',
        $marge . '  <div class="plat__entete">',
        $marge . '    <' . $h . ' class="plat__nom">' . e((string) $plat['nom']) . '</' . $h . '>',
        $marge . '    <p class="plat__prix">' . e((string) $plat['prix']) . '</p>',
        $marge . '  </div>',
    ];

    // Une description vide ne produit aucun élément.
    if ((string) $plat['description'] !== '') {
        $lignes[] = $marge . '  <p class="plat__description">' . e((string) $plat['description']) . '</p>';
    }

    if ($plat['allergenes'] !== []) {
        $lignes[] = $marge . '  <p class="plat__allergenes">Allergènes : '
            . e(implode(', ', $plat['allergenes'])) . '</p>';
    }

    $lignes[] = $marge . '</li>';

    return $lignes;
}

/**
 * @param array<string, string> $infos
 */
function rendre_pied(array $infos): string
{
    $jours = [];

    foreach (JOURS_SEMAINE as $cle => $libelle) {
        $horaire = info($infos, 'horaires_' . $cle);

        if ($horaire === '') {
            continue;
        }

        // data-jour sert au script qui met le jour courant en évidence. Sans
        // JavaScript, la semaine reste entièrement lisible.
        $jours[] = '        <div class="horaires__jour" data-jour="' . e($cle) . '">';
        $jours[] = '          <dt class="horaires__nom">' . e($libelle) . '</dt>';
        $jours[] = '          <dd class="horaires__valeur">' . e($horaire) . '</dd>';
        $jours[] = '        </div>';
    }

    $lignes = ['  <footer class="pied">'];

    if ($jours !== []) {
        $lignes[] = '    <section class="horaires">';
        $lignes[] = '      <h2 class="horaires__titre">Horaires</h2>';
        $lignes[] = '      <dl class="horaires__liste">';
        array_push($lignes, ...$jours);
        $lignes[] = '      </dl>';
        $lignes[] = '    </section>';
    }

    $telephone = info($infos, 'telephone');

    if ($telephone !== '') {
        $lignes[] = '    <p class="pied__telephone"><a href="tel:'
            . e(lien_telephone($telephone)) . '">' . e($telephone) . '</a></p>';
    }

    $lignes[] = '    <script src="jour.js" defer></script>';
    $lignes[] = '  </footer>';

    return implode("\n", $lignes);
}

/**
 * « 01 23 45 67 89 » → « +33123456789 », utilisable dans un href tel:.
 */
function lien_telephone(string $telephone): string
{
    $chiffres = preg_replace('/[^0-9+]/', '', $telephone) ?? '';

    if (strlen($chiffres) === 10 && str_starts_with($chiffres, '0')) {
        return '+33' . substr($chiffres, 1);
    }

    return $chiffres;
}
