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
require_once __DIR__ . '/pages_legales.php';
require_once __DIR__ . '/annexes.php';

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

        // 3. Rendre, puis écrire.
        $url = rtrim($config['url_site'], '/');

        // Les empreintes se calculent avant tout rendu : les pages y référent.
        empreintes(calculer_empreintes($dossier));

        // De même pour la photo : c'est sa présence sur le disque qui décide
        // si la page l'affiche, pas un réglage à tenir à jour en double.
        photo_disponible(is_file($dossier . '/img/salle-' . max(SALLE_LARGEURS) . '.jpg'));

        $pages = [
            'index.html' => rendre_accueil($carte['vedettes'], $infos, $carte['categories'], $url),
            'carte.html' => rendre_carte($carte['categories'], $infos, $url),
            'mentions-legales.html' => rendre_mentions_legales($infos, $url),
            'confidentialite.html' => rendre_confidentialite($infos, $url),
            // Le formulaire de contact est dynamique : il ne peut pas être
            // pré-généré. Il reprend l'en-tête et le pied par ce gabarit, pour
            // qu'il n'existe qu'une seule source de vérité pour le décor.
            'gabarit.php' => rendre_gabarit($infos, $url),
            '404.html' => rendre_404($infos),
            'robots.txt' => rendre_robots($url),
        ];

        // Raccourci de réservation : chezauguste.com/r, assez court pour être
        // dicté au téléphone ou imprimé sur une carte de visite.
        //
        // Un fichier et non un dossier « r » : le serveur redirige toute
        // adresse de dossier vers sa version suivie d'une barre oblique, et
        // cette redirection passe avant les règles de réécriture. Un dossier
        // coûtait donc un aller-retour de plus à chaque visite.
        if (reservation_active($infos)) {
            $pages['r.php'] = rendre_raccourci(lien_reservation($infos));
        }

        foreach ($pages as $fichier => $contenu) {
            $resultat['octets'] += ecrire($dossier . '/' . $fichier, $contenu);
            $resultat['pages'][] = $fichier;
        }

        // Le plan du site vient en dernier : il date chaque page de son
        // fichier, qui vient d'être écrit ou laissé tel quel.
        if ($url !== '') {
            $resultat['octets'] += ecrire($dossier . '/sitemap.xml', rendre_sitemap($url, $infos, $dossier));
            $resultat['pages'][] = 'sitemap.xml';
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
        $resultat['avertissements'] = array_merge(
            avertissements_infos($infos),
            avertissements_cles_inconnues($infos),
            avertissements_reservation($infos),
            avertissements_vedettes($carte),
            avertissements_legaux($infos),
            $url === '' ? ['« url_site » n\'est pas configurée : ni balise canonique, '
                . 'ni aperçu de partage, ni JSON-LD ne sont émis.'] : []
        );
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
 *
 * Une page identique n'est pas réécrite. La publication automatique régénère
 * le site toutes les dix minutes : sans cela, chaque passage changerait la
 * date des fichiers, les navigateurs retéléchargeraient des pages inchangées,
 * et le plan du site annoncerait à Google une modification quotidienne.
 */
function ecrire(string $chemin, string $html): int
{
    if (is_file($chemin) && file_get_contents($chemin) === $html) {
        return strlen($html);
    }

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

    // is_file() a mis l'ancienne date en cache : le plan du site doit lire
    // la nouvelle.
    clearstatcache(true, $chemin);

    return $octets;
}

/**
 * Trop de plats cochés « vedette » : on plafonne, mais sans le taire. Une
 * coupe silencieuse laisserait le restaurateur cocher dans le vide.
 *
 * @param array<string, mixed> $carte
 * @return list<string>
 */
function avertissements_vedettes(array $carte): array
{
    $affichees = count($carte['vedettes']);

    if ($carte['cochees'] <= $affichees) {
        return [];
    }

    return [sprintf(
        '%d plats sont cochés « vedette » dans l\'onglet carte, mais l\'accueil '
        . 'n\'en affiche que %d — au-delà, ce n\'est plus une sélection. '
        . 'Décochez-en pour choisir lesquels.',
        $carte['cochees'],
        $affichees
    )];
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
