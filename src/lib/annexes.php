<?php

declare(strict_types=1);

/**
 * Les fichiers annexes du site : robots.txt, sitemap.xml, et le gabarit que
 * partage la seule page dynamique, le formulaire de contact.
 */

require_once __DIR__ . '/rendu.php';

/**
 * Les pages à proposer aux moteurs, avec leur importance relative.
 *
 * @param array<string, string> $infos
 * @return array<string, string>
 */
function pages_indexees(array $infos): array
{
    $pages = ['' => '1.0', 'carte.html' => '0.9'];

    // Une page absente du site n'a rien à faire dans le plan qu'on donne aux
    // moteurs : ils la visiteraient pour recevoir un 404.
    if (reservation_active($infos)) {
        $pages['reservation.php'] = '0.7';
    }

    return $pages + [
        'contact.php' => '0.5',
        'mentions-legales.html' => '0.2',
        'confidentialite.html' => '0.2',
    ];
}

/** Une table se prend au moins une demi-heure avant la fermeture. */
const DERNIERE_ARRIVEE = 30;

/** Pas des créneaux proposés, en minutes. */
const PAS_RESERVATION = 30;

/**
 * Les plages d'ouverture, jour par jour, telles quelles.
 *
 * On ne les interprète pas en « midi » et « soir » : un bouillon peut servir
 * en continu, et ce découpage n'aurait alors aucun sens. La demande de
 * réservation s'en sert pour ne proposer que des heures possibles, et pour
 * refuser tout de suite un jour de fermeture.
 *
 * @param array<string, string> $infos
 * @return array<string, list<array{0: string, 1: string}>>
 */
function horaires_par_jour(array $infos): array
{
    $par_jour = [];

    foreach (array_keys(JOURS_SEMAINE) as $jour) {
        $par_jour[$jour] = decouper_creneaux(info($infos, 'horaires_' . $jour));
    }

    return $par_jour;
}

/**
 * Toutes les heures proposables, tous jours confondus.
 *
 * Sans JavaScript, la liste ne peut pas s'adapter à la date choisie : on
 * propose donc l'union, et le serveur refuse précisément ce qui ne convient
 * pas au jour demandé.
 *
 * @param array<string, list<array{0: string, 1: string}>> $horaires
 * @return list<string>
 */
function heures_proposables(array $horaires): array
{
    $minutes = [];

    foreach ($horaires as $plages) {
        foreach ($plages as [$debut, $fin]) {
            $depuis = en_minutes($debut);
            $jusqu = en_minutes($fin) - DERNIERE_ARRIVEE;

            // On arrondit au pas supérieur pour ne pas proposer 11h07.
            for ($m = (int) ceil($depuis / PAS_RESERVATION) * PAS_RESERVATION; $m <= $jusqu; $m += PAS_RESERVATION) {
                $minutes[$m] = true;
            }
        }
    }

    ksort($minutes);

    return array_map(
        static fn (int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60),
        array_keys($minutes)
    );
}

function en_minutes(string $heure): int
{
    [$h, $m] = array_map('intval', explode(':', $heure));

    return $h * 60 + $m;
}

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

/**
 * @param array<string, string> $infos
 */
function rendre_sitemap(string $urlSite, array $infos): string
{
    $jour = date('Y-m-d');

    $lignes = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ];

    foreach (pages_indexees($infos) as $page => $priorite) {
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
    $horaires = horaires_par_jour($infos);

    // Un en-tête par page dynamique : chacune doit voir son entrée de menu
    // marquée comme courante.
    $entetes = [];

    foreach (['contact.php', 'reservation.php'] as $page) {
        $entetes[$page] = rendre_entete($infos, 'interieure', $page);
    }

    $telephone = info($infos, 'telephone');

    $donnees = [
        'entetes' => $entetes,
        'pied' => rendre_pied($infos),
        'style' => ressource('style.css'),
        'nom' => info($infos, 'nom', NOM_PAR_DEFAUT),
        'email' => info($infos, 'email'),
        'telephone' => $telephone,
        'url_site' => $urlSite,
        // Les plages d'ouverture, jour par jour, déduites des horaires du
        // Sheet. La demande de réservation ne propose que des heures
        // possibles, et refuse tout de suite un jour de fermeture plutôt que
        // de laisser le client attendre une réponse.
        'horaires' => $horaires,
        'heures' => heures_proposables($horaires),
        // reservation.php se ferme lui-même quand la prise de réservation est
        // éteinte : le fichier reste déployé, mais il ne sert plus de
        // formulaire. Rien n'est supprimé, tout se rallume par le Sheet.
        'reservation_active' => reservation_active($infos),
        'telephone_lien' => $telephone === '' ? '' : lien_telephone($telephone),
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
