<?php

declare(strict_types=1);

/**
 * Les fichiers annexes du site : robots.txt, sitemap.xml, et le gabarit que
 * partage la seule page dynamique, le formulaire de contact.
 */

require_once __DIR__ . '/rendu.php';

/** Les pages à proposer aux moteurs, avec leur importance relative. */
const PAGES_INDEXEES = [
    '' => '1.0',
    'carte.html' => '0.9',
    'contact.php' => '0.5',
    'mentions-legales.html' => '0.2',
    'confidentialite.html' => '0.2',
];

function rendre_robots(string $urlSite): string
{
    $lignes = [
        '# Tout le site est public, rien à cacher aux moteurs.',
        'User-agent: *',
        'Allow: /',
        '',
        '# Le point de publication n\'a rien à faire dans un index.',
        'Disallow: /publier.php',
        '',
    ];

    if ($urlSite !== '') {
        $lignes[] = 'Sitemap: ' . $urlSite . '/sitemap.xml';
        $lignes[] = '';
    }

    return implode("\n", $lignes);
}

function rendre_sitemap(string $urlSite): string
{
    $jour = date('Y-m-d');

    $lignes = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ];

    foreach (PAGES_INDEXEES as $page => $priorite) {
        $lignes[] = '  <url>';
        $lignes[] = '    <loc>' . e($urlSite . '/' . $page) . '</loc>';
        $lignes[] = '    <lastmod>' . $jour . '</lastmod>';
        $lignes[] = '    <priority>' . $priorite . '</priority>';
        $lignes[] = '  </url>';
    }

    $lignes[] = '</urlset>';
    $lignes[] = '';

    return implode("\n", $lignes);
}

/**
 * La page d'erreur 404.
 *
 * Elle est servie depuis n'importe quelle adresse, y compris /un/chemin/faux :
 * ses liens sont donc absolus depuis la racine, sans quoi la feuille de style
 * et le logo seraient cherchés au mauvais endroit.
 *
 * @param array<string, string> $infos
 */
function rendre_404(array $infos): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);

    return implode("\n", [
        '<!DOCTYPE html>',
        '<html lang="fr">',
        '<head>',
        '  <meta charset="utf-8">',
        '  <meta name="viewport" content="width=device-width, initial-scale=1">',
        '  <title>Page introuvable — ' . e($nom) . '</title>',
        '  <meta name="robots" content="noindex">',
        '  <link rel="stylesheet" href="/style.css">',
        '  <link rel="icon" href="/img/favicon.png" type="image/png">',
        '</head>',
        '<body class="page page--erreur">',
        '  <header class="entete entete--compacte">',
        '    <a class="entete__retour" href="/">',
        '      <img class="entete__logo" src="/img/logo.png" alt="' . e($nom) . '"',
        '           width="440" height="322">',
        '    </a>',
        '  </header>',
        '  <main class="texte">',
        '    <h1 class="texte__titre">Cette page n’existe pas</h1>',
        '    <p>Le lien est peut-être ancien, ou mal recopié.</p>',
        '    <p class="formulaire__envoi">',
        '      <a class="bouton" href="/">Retour à l’accueil</a>',
        '      <a class="bouton bouton--discret" href="/carte.html">Voir la carte</a>',
        '    </p>',
        '  </main>',
        '</body>',
        '</html>',
        '',
    ]);
}

/**
 * Le décor partagé, écrit en PHP plutôt qu'en HTML.
 *
 * contact.php ne peut pas être pré-généré — il change selon ce que le
 * visiteur envoie. Mais il ne doit pas non plus reconstruire l'en-tête et le
 * pied de son côté : ils divergeraient au premier changement. Le build lui
 * livre donc les deux fragments déjà rendus, dans un fichier qu'il inclut.
 *
 * @param array<string, string> $infos
 */
function rendre_gabarit(array $infos, string $urlSite): string
{
    $donnees = [
        // L'en-tête est rendu pour contact.php : c'est la seule page dynamique,
        // et son entrée de menu doit s'afficher comme la page courante.
        'entete' => rendre_entete($infos, 'interieure', 'contact.php'),
        'pied' => rendre_pied($infos),
        'style' => ressource('style.css'),
        'nom' => info($infos, 'nom', NOM_PAR_DEFAUT),
        'email' => info($infos, 'email'),
        'telephone' => info($infos, 'telephone'),
        'url_site' => $urlSite,
    ];

    return implode("\n", [
        '<?php',
        '',
        '// Fichier généré par src/build.php — ne pas modifier à la main.',
        '// Il ne contient que des données : y accéder directement n\'affiche rien.',
        '',
        'return ' . var_export($donnees, true) . ';',
        '',
    ]);
}
