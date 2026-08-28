<?php

declare(strict_types=1);

/**
 * Présentation web du générateur : le bouton « Publier ».
 *
 * Appelé depuis un favori ou depuis le menu Apps Script du Google Sheet, il
 * retélécharge le Sheet et régénère la carte. Toute la logique est dans
 * src/lib/generateur.php ; ce fichier ne fait que contrôler le secret et
 * mettre le compte rendu en forme pour un navigateur.
 *
 *     https://exemple.fr/publier.php?cle=<secret>
 *     https://exemple.fr/publier.php?cle=<secret>&format=texte
 *
 * Le format texte est celui qu'utilise Apps Script pour afficher la réponse
 * dans une boîte de dialogue du tableur.
 */

date_default_timezone_set('Europe/Paris');

const SECRET_MINIMUM = 16;

/**
 * Le générateur ne se trouve pas au même endroit en local et sur
 * l'hébergement : dans le dépôt, public/ et src/ sont voisins ; sur cPanel,
 * public_html/ est servi par Apache et l'application est rangée à côté, hors
 * de portée du web. On essaie les deux.
 */
function racine_application(): string
{
    $candidats = [
        dirname(__DIR__),                 // dépôt local : ../src
        dirname(__DIR__) . '/auguste',    // cPanel : ~/auguste/src
    ];

    foreach ($candidats as $candidat) {
        if (is_file($candidat . '/src/lib/generateur.php')) {
            return $candidat;
        }
    }

    repondre_erreur_fatale(
        'Le générateur est introuvable.',
        'Aucun de ces dossiers ne contient src/lib/generateur.php : '
        . implode(', ', $candidats)
    );
}

$racine = racine_application();
require $racine . '/src/lib/generateur.php';

$texte = (($_GET['format'] ?? '') === 'texte');

try {
    $config = config_charger($racine);
} catch (RuntimeException $e) {
    repondre(500, 'Publication impossible', 'technique', [$e->getMessage()], $texte);
}

$secret = $config['secret_publication'];

if (strlen($secret) < SECRET_MINIMUM || $secret === 'a-remplacer-par-un-secret-genere') {
    repondre(
        500,
        'Publication impossible',
        'technique',
        ['Aucun secret de publication n\'est configuré sur ce serveur.'],
        $texte
    );
}

// hash_equals compare en temps constant : la durée de la réponse ne dit rien
// sur le nombre de caractères devinés.
if (!hash_equals($secret, (string) ($_GET['cle'] ?? ''))) {
    repondre(403, 'Accès refusé', 'refus', ['Lien de publication invalide.'], $texte);
}

$resultat = generer('sheet', $racine);

if ($resultat['succes']) {
    repondre_succes($resultat, $texte);
}

repondre(
    erreurs_sont_de_donnees($resultat['erreurs']) ? 422 : 500,
    'Publication impossible',
    erreurs_sont_de_donnees($resultat['erreurs']) ? 'donnees' : 'technique',
    lignes_erreurs($resultat['erreurs']),
    $texte
);

// ---------------------------------------------------------------------------

/**
 * Le restaurateur voit deux onglets, pas deux fichiers CSV — et « ligne 12 »
 * ne veut rien dire tant qu'on n'a pas dit dans lequel.
 *
 * @param list<array{fichier: string|null, numero: int|null, message: string}> $erreurs
 * @return list<string>
 */
function lignes_erreurs(array $erreurs): array
{
    $onglets = ['carte.csv' => 'carte', 'infos.csv' => 'infos'];
    $lignes = [];

    foreach ($erreurs as $erreur) {
        $onglet = $onglets[$erreur['fichier']] ?? null;

        if ($onglet === null) {
            $lignes[] = $erreur['message'];

            continue;
        }

        $lignes[] = $erreur['numero'] === null
            ? sprintf('onglet « %s » : %s', $onglet, $erreur['message'])
            : sprintf('onglet « %s », ligne %d : %s', $onglet, $erreur['numero'], $erreur['message']);
    }

    return $lignes;
}

/**
 * @param array<string, mixed> $resultat
 * @return never
 */
function repondre_succes(array $resultat, bool $texte)
{
    $detail = array_map(
        static fn (array $categorie): string => sprintf('%s (%d)', $categorie['nom'], $categorie['plats']),
        $resultat['categories']
    );

    $lignes = [
        sprintf(
            '%d %s en ligne : %s.',
            $resultat['actives'],
            accord($resultat['actives'], 'plat'),
            implode(', ', $detail)
        ),
        sprintf('Mise à jour le %s à %s.', date('d/m/Y'), date('H\hi')),
    ];

    if ($resultat['ignorees'] > 0) {
        $lignes[] = sprintf(
            '%d %s non %s, car la colonne « actif » n\'est pas à « oui ».',
            $resultat['ignorees'],
            accord($resultat['ignorees'], 'ligne'),
            accord($resultat['ignorees'], 'publiée')
        );
    }

    repondre(200, 'Carte publiée', 'succes', $lignes, $texte);
}

/**
 * @param list<string> $lignes
 * @return never
 */
function repondre(int $statut, string $titre, string $genre, array $lignes, bool $texte)
{
    http_response_code($statut);
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    $consigne = consigne($genre);

    if ($texte) {
        header('Content-Type: text/plain; charset=utf-8');

        echo $titre, "\n\n";
        echo implode("\n", $lignes), "\n";

        if ($consigne !== '') {
            echo "\n", $consigne, "\n";
        }

        exit;
    }

    header('Content-Type: text/html; charset=utf-8');

    echo page_html($titre, $genre, $lignes, $consigne);
    exit;
}

/**
 * @return never
 */
function repondre_erreur_fatale(string $titre, string $detail)
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    echo $titre, "\n\n", $detail, "\n";
    exit;
}

function consigne(string $genre): string
{
    return match ($genre) {
        'donnees' => 'Corrigez ces lignes dans le Google Sheet, puis republiez. '
            . 'En attendant, la carte précédente reste en ligne, inchangée.',
        'technique' => 'Le site n\'a pas été modifié. Ce problème ne se corrige pas '
            . 'depuis le tableur : prévenez la personne qui s\'occupe du site.',
        'refus' => 'Vérifiez le lien de publication.',
        default => '',
    };
}

/**
 * Page autonome : c'est un outil d'administration, pas une page du site. Elle
 * n'emprunte donc pas style.css et n'a aucune raison de lui ressembler.
 *
 * @param list<string> $lignes
 */
function page_html(string $titre, string $genre, array $lignes, string $consigne): string
{
    $elements = implode("\n", array_map(
        static fn (string $ligne): string => '      <li>' . e($ligne) . '</li>',
        $lignes
    ));

    $piedConsigne = $consigne === '' ? '' : "\n    <p class=\"consigne\">" . e($consigne) . '</p>';

    return <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="robots" content="noindex, nofollow">
      <title>{$titre}</title>
      <style>
        body { margin: 0 auto; padding: 1.5rem; max-width: 34rem; font-size: 17px; line-height: 1.5; }
        ul { margin: 0; padding-left: 1.2rem; }
        li { margin: 0.3rem 0; }
        .consigne { margin-top: 1.5rem; }
      </style>
    </head>
    <body class="{$genre}">
        <h1>{$titre}</h1>
        <ul>
    {$elements}
        </ul>{$piedConsigne}
    </body>
    </html>

    HTML;
}
