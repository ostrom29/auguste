<?php

declare(strict_types=1);

/**
 * Rendu HTML.
 *
 * Rien n'est concaténé sans passer par `e()` : tout ce qui vient du Sheet est
 * échappé au moment de l'écriture.
 */

/** Jours de la semaine, dans l'ordre d'affichage du pied de page. */
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

/**
 * Assemble la page complète.
 *
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 * @param array<string, string> $infos
 */
function rendre_page(array $categories, array $infos): string
{
    $nom = $infos['nom'] ?? NOM_PAR_DEFAUT;

    $html = implode("\n", [
        '<!DOCTYPE html>',
        '<html lang="fr">',
        '<head>',
        '  <meta charset="utf-8">',
        '  <meta name="viewport" content="width=device-width, initial-scale=1">',
        '  <title>Carte — ' . e($nom) . '</title>',
        '  <link rel="stylesheet" href="style.css">',
        '</head>',
        '<body>',
        '  <!-- Page générée par src/build.php : toute modification à la main sera écrasée. -->',
        rendre_entete($nom, $infos),
        rendre_carte($categories),
        rendre_pied($infos),
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
function rendre_entete(string $nom, array $infos): string
{
    $lignes = [
        '  <header class="entete">',
        '    <h1 class="entete__nom">' . e($nom) . '</h1>',
    ];

    $coordonnees = [];

    if (($infos['adresse'] ?? '') !== '') {
        $coordonnees[] = '      <p class="entete__adresse">' . e($infos['adresse']) . '</p>';
    }

    if (($infos['acces'] ?? '') !== '') {
        $coordonnees[] = '      <p class="entete__acces">' . e($infos['acces']) . '</p>';
    }

    if (($infos['telephone'] ?? '') !== '') {
        $coordonnees[] = '      <p class="entete__telephone"><a href="tel:'
            . e(lien_telephone($infos['telephone'])) . '">'
            . e($infos['telephone']) . '</a></p>';
    }

    if ($coordonnees !== []) {
        $lignes[] = '    <address class="entete__coordonnees">';
        array_push($lignes, ...$coordonnees);
        $lignes[] = '    </address>';
    }

    if (($infos['message'] ?? '') !== '') {
        $lignes[] = '    <p class="entete__message">' . e($infos['message']) . '</p>';
    }

    $lignes[] = '  </header>';

    return implode("\n", $lignes);
}

/**
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 */
function rendre_carte(array $categories): string
{
    $lignes = ['  <main class="carte">'];

    foreach ($categories as $categorie) {
        $lignes[] = '    <section class="categorie">';
        $lignes[] = '      <h2 class="categorie__titre">' . e($categorie['nom']) . '</h2>';
        $lignes[] = '      <ul class="categorie__plats">';

        foreach ($categorie['plats'] as $plat) {
            array_push($lignes, ...rendre_plat($plat));
        }

        $lignes[] = '      </ul>';
        $lignes[] = '    </section>';
    }

    $lignes[] = '  </main>';

    return implode("\n", $lignes);
}

/**
 * @param array<string, mixed> $plat
 * @return list<string>
 */
function rendre_plat(array $plat): array
{
    $lignes = [
        '        <li class="plat">',
        '          <div class="plat__entete">',
        '            <h3 class="plat__nom">' . e((string) $plat['nom']) . '</h3>',
        '            <p class="plat__prix">' . e((string) $plat['prix']) . '</p>',
        '          </div>',
    ];

    // Une description vide ne produit aucun élément.
    if ((string) $plat['description'] !== '') {
        $lignes[] = '          <p class="plat__description">' . e((string) $plat['description']) . '</p>';
    }

    if ($plat['allergenes'] !== []) {
        $lignes[] = '          <p class="plat__allergenes">Allergènes : '
            . e(implode(', ', $plat['allergenes'])) . '</p>';
    }

    $lignes[] = '        </li>';

    return $lignes;
}

/**
 * @param array<string, string> $infos
 */
function rendre_pied(array $infos): string
{
    $jours = [];

    foreach (JOURS_SEMAINE as $cle => $libelle) {
        $horaire = $infos['horaires_' . $cle] ?? '';

        if ($horaire === '') {
            continue;
        }

        $jours[] = '      <div class="horaires__jour">';
        $jours[] = '        <dt class="horaires__nom">' . e($libelle) . '</dt>';
        $jours[] = '        <dd class="horaires__valeur">' . e($horaire) . '</dd>';
        $jours[] = '      </div>';
    }

    if ($jours === []) {
        return '  <footer class="pied"></footer>';
    }

    return implode("\n", array_merge(
        [
            '  <footer class="pied">',
            '    <h2 class="pied__titre">Horaires</h2>',
            '    <dl class="horaires">',
        ],
        $jours,
        [
            '    </dl>',
            '  </footer>',
        ]
    ));
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

/**
 * Échappe & < > et le guillemet double.
 *
 * L'apostrophe est laissée telle quelle : elle n'a rien de dangereux dans du
 * texte ni dans un attribut entre guillemets doubles — et le gabarit n'en
 * utilise pas d'autres — alors qu'un « d&#039;Isigny » rendrait le HTML
 * généré illisible à la relecture, dans une carte française pleine
 * d'apostrophes.
 */
function e(string $texte): string
{
    return htmlspecialchars($texte, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
}
