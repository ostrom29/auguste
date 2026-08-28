<?php

declare(strict_types=1);

/**
 * D'où viennent les deux CSV.
 *
 *   --source=sheet      (défaut) télécharge depuis les URLs de config.php
 *                       et met à jour cache/
 *   --source=cache      rejoue le dernier téléchargement, hors ligne
 *   --source=fixtures   lit fixtures/, hors ligne
 *   --source=<chemin>   lit n'importe quel dossier contenant carte.csv
 *                       et infos.csv (utilisé pour les fixtures cassées)
 */

/**
 * Charge les deux CSV bruts.
 *
 * @return array{carte: string, infos: string, description: string}
 */
function source_charger(string $source, string $racine): array
{
    $source = rtrim($source, '/\\');

    if ($source === '' || $source === 'sheet' || $source === 'remote') {
        return source_depuis_sheet($racine);
    }

    if ($source === 'cache') {
        return source_depuis_dossier(
            $racine . '/cache',
            'cache local (cache/)'
        );
    }

    if ($source === 'fixtures') {
        return source_depuis_dossier(
            $racine . '/fixtures',
            'fixtures (fixtures/)'
        );
    }

    $dossier = source_chemin_absolu($source, $racine);

    return source_depuis_dossier($dossier, 'dossier ' . $source);
}

/**
 * @return array{carte: string, infos: string, description: string}
 */
function source_depuis_sheet(string $racine): array
{
    $config = source_config($racine);

    $carte = source_telecharger($config['csv_carte'], 'carte');
    $infos = source_telecharger($config['csv_infos'], 'infos');

    // On n'écrit le cache qu'une fois les deux onglets récupérés, pour ne
    // jamais laisser un cache à moitié à jour.
    source_ecrire_cache($racine, $carte, $infos);

    return [
        'carte' => $carte,
        'infos' => $infos,
        'description' => 'Google Sheet publié (réseau), cache mis à jour',
    ];
}

/**
 * @return array{carte: string, infos: string, description: string}
 */
function source_depuis_dossier(string $dossier, string $description): array
{
    if (!is_dir($dossier)) {
        throw new RuntimeException(sprintf('Dossier source introuvable : %s', $dossier));
    }

    return [
        'carte' => source_lire_fichier($dossier . '/carte.csv'),
        'infos' => source_lire_fichier($dossier . '/infos.csv'),
        'description' => $description,
    ];
}

function source_lire_fichier(string $chemin): string
{
    if (!is_file($chemin)) {
        throw new RuntimeException(sprintf('Fichier CSV introuvable : %s', $chemin));
    }

    $contenu = file_get_contents($chemin);
    if ($contenu === false) {
        throw new RuntimeException(sprintf('Lecture impossible : %s', $chemin));
    }

    return $contenu;
}

/**
 * @return array{csv_carte: string, csv_infos: string}
 */
function source_config(string $racine): array
{
    $chemin = $racine . '/config.php';

    if (!is_file($chemin)) {
        throw new RuntimeException(
            "config.php est introuvable.\n"
            . "  Copiez le modèle : cp config.example.php config.php\n"
            . '  Ou travaillez hors ligne : php src/build.php --source=fixtures'
        );
    }

    $config = require $chemin;

    if (!is_array($config)) {
        throw new RuntimeException('config.php doit retourner un tableau.');
    }

    foreach (['csv_carte', 'csv_infos'] as $cle) {
        if (!isset($config[$cle]) || !is_string($config[$cle]) || trim($config[$cle]) === '') {
            throw new RuntimeException(sprintf('config.php : la clé « %s » est absente ou vide.', $cle));
        }
    }

    return [
        'csv_carte' => $config['csv_carte'],
        'csv_infos' => $config['csv_infos'],
    ];
}

function source_telecharger(string $url, string $onglet): string
{
    $contenu = extension_loaded('curl')
        ? source_telecharger_curl($url, $onglet)
        : source_telecharger_flux($url, $onglet);

    $contenu = csv_retirer_bom($contenu);

    if (trim($contenu) === '') {
        throw new RuntimeException(sprintf('L\'onglet « %s » a renvoyé une réponse vide.', $onglet));
    }

    // Google renvoie une page HTML quand le classeur n'est plus publié.
    if (str_starts_with(ltrim($contenu), '<')) {
        throw new RuntimeException(sprintf(
            "L'onglet « %s » a renvoyé du HTML au lieu d'un CSV.\n"
            . '  Vérifiez que le Sheet est toujours publié sur le Web au format CSV.',
            $onglet
        ));
    }

    return $contenu;
}

function source_telecharger_curl(string $url, string $onglet): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Initialisation de cURL impossible.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'chez-auguste-build/1.0',
    ]);

    $contenu = curl_exec($curl);
    $erreur = curl_error($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($contenu === false) {
        throw new RuntimeException(sprintf('Téléchargement de l\'onglet « %s » impossible : %s', $onglet, $erreur));
    }

    if ($code !== 200) {
        throw new RuntimeException(sprintf('L\'onglet « %s » a répondu HTTP %d.', $onglet, $code));
    }

    return (string) $contenu;
}

function source_telecharger_flux(string $url, string $onglet): string
{
    $contexte = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => 1,
            'max_redirects' => 5,
            'user_agent' => 'chez-auguste-build/1.0',
        ],
    ]);

    $contenu = @file_get_contents($url, false, $contexte);

    if ($contenu === false) {
        throw new RuntimeException(sprintf(
            "Téléchargement de l'onglet « %s » impossible (ni cURL ni allow_url_fopen ?).",
            $onglet
        ));
    }

    return $contenu;
}

function source_ecrire_cache(string $racine, string $carte, string $infos): void
{
    $dossier = $racine . '/cache';

    if (!is_dir($dossier) && !mkdir($dossier, 0o775, true) && !is_dir($dossier)) {
        throw new RuntimeException(sprintf('Création du dossier de cache impossible : %s', $dossier));
    }

    foreach (['carte.csv' => $carte, 'infos.csv' => $infos] as $nom => $contenu) {
        if (file_put_contents($dossier . '/' . $nom, $contenu) === false) {
            throw new RuntimeException(sprintf('Écriture du cache impossible : %s', $dossier . '/' . $nom));
        }
    }
}

function source_chemin_absolu(string $chemin, string $racine): string
{
    $estAbsolu = str_starts_with($chemin, '/')
        || preg_match('#^[A-Za-z]:[\\\\/]#', $chemin) === 1;

    if ($estAbsolu) {
        return $chemin;
    }

    // Relatif au projet en priorité, sinon au répertoire courant : « fixtures/
    // casse-prix » marche quel que soit l'endroit d'où on lance le script.
    $depuisRacine = $racine . '/' . $chemin;

    return is_dir($depuisRacine) ? $depuisRacine : $chemin;
}
