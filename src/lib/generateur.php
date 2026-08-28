<?php

declare(strict_types=1);

/**
 * Le générateur, sans aucun affichage.
 *
 * `generer()` n'écrit jamais sur la sortie standard, ne connaît ni STDERR ni
 * les codes de sortie, et ne s'arrête jamais brutalement : elle rend un compte
 * rendu que l'appelant présente comme il veut. C'est ce qui permet de la
 * lancer aussi bien depuis le terminal (src/build.php) que depuis le web
 * (public/publier.php), sans dupliquer une ligne de logique.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/source.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/transformation.php';
require_once __DIR__ . '/rendu.php';

/**
 * Régénère la page. Valide d'abord, écrit ensuite.
 *
 * @return array{
 *     succes: bool,
 *     source: string,
 *     erreurs: list<array{fichier: string|null, numero: int|null, message: string}>,
 *     avertissements: list<string>,
 *     lues: int, actives: int, ignorees: int,
 *     categories: list<array{nom: string, plats: int}>,
 *     sortie: string, pages: list<string>, octets: int
 * }
 */
function generer(string $source, string $racine): array
{
    $resultat = [
        'succes' => false,
        'source' => $source,
        'erreurs' => [],
        'avertissements' => [],
        'lues' => 0,
        'actives' => 0,
        'ignorees' => 0,
        'categories' => [],
        'sortie' => '',
        'pages' => [],
        'octets' => 0,
    ];

    try {
        $config = config_charger($racine);
        $dossier = config_exiger($config, 'sortie_dossier', 'pour savoir où écrire les pages');
        $resultat['sortie'] = $dossier;

        $brut = source_charger($source, $racine, $config);
        $resultat['source'] = $brut['description'];

        $carteCsv = csv_lire($brut['carte']);
        $infosCsv = csv_lire($brut['infos']);

        // 1. Valider. Aucune écriture tant que ce n'est pas propre.
        $erreurs = array_merge(valider_carte($carteCsv), valider_infos($infosCsv));

        if ($erreurs !== []) {
            $resultat['erreurs'] = $erreurs;

            return $resultat;
        }

        // 2. Transformer.
        $carte = transformer_carte($carteCsv['lignes']);
        $infos = transformer_infos($infosCsv['lignes']);

        // 3. Rendre les deux pages, puis les écrire.
        $pages = [
            'index.html' => rendre_accueil($carte['vedettes'], $infos),
            'carte.html' => rendre_carte($carte['categories'], $infos),
        ];

        foreach ($pages as $fichier => $html) {
            $resultat['octets'] += ecrire($dossier . '/' . $fichier, $html);
            $resultat['pages'][] = $fichier;
        }

        $resultat['lues'] = $carte['lues'];
        $resultat['actives'] = $carte['actives'];
        $resultat['ignorees'] = $carte['ignorees'];
        $resultat['categories'] = array_map(
            static fn (array $categorie): array => [
                'nom' => $categorie['nom'],
                'plats' => count($categorie['plats']),
            ],
            $carte['categories']
        );
        $resultat['avertissements'] = avertissements_infos($infos);
        $resultat['succes'] = true;
    } catch (RuntimeException $e) {
        $resultat['erreurs'] = [erreur_generale($e->getMessage())];
    }

    return $resultat;
}

/**
 * Écrit la page via un fichier temporaire renommé, dans le même dossier pour
 * que le renommage reste atomique : si l'écriture échoue en cours de route, la
 * version précédente n'est pas entamée.
 */
function ecrire(string $chemin, string $html): int
{
    $dossier = dirname($chemin);

    // Notation octale historique : 0o775 demanderait PHP 8.1, et l'hébergement
    // n'est garanti qu'en PHP 8.
    if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
        throw new RuntimeException(sprintf('Création du dossier impossible : %s', $dossier));
    }

    if (!is_writable($dossier)) {
        throw new RuntimeException(sprintf(
            "Dossier de sortie non inscriptible : %s\n"
            . '  Vérifiez les droits du dossier sur l\'hébergement.',
            $dossier
        ));
    }

    $temporaire = $chemin . '.tmp';
    $octets = file_put_contents($temporaire, $html);

    if ($octets === false) {
        throw new RuntimeException(sprintf('Écriture impossible : %s', $temporaire));
    }

    if (!rename($temporaire, $chemin)) {
        @unlink($temporaire);

        throw new RuntimeException(sprintf('Remplacement impossible : %s', $chemin));
    }

    return $octets;
}

/**
 * Une erreur qui ne se rattache à aucune ligne d'aucun fichier : réseau,
 * configuration, droits d'écriture.
 *
 * @return array{fichier: null, numero: null, message: string}
 */
function erreur_generale(string $message): array
{
    return ['fichier' => null, 'numero' => null, 'message' => $message];
}

/**
 * Distingue une carte mal remplie d'une panne d'installation : la première se
 * corrige dans le tableur, la seconde demande un développeur.
 *
 * @param list<array{fichier: string|null, numero: int|null, message: string}> $erreurs
 */
function erreurs_sont_de_donnees(array $erreurs): bool
{
    foreach ($erreurs as $erreur) {
        if ($erreur['fichier'] === null) {
            return false;
        }
    }

    return $erreurs !== [];
}
