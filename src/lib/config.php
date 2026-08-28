<?php

declare(strict_types=1);

/**
 * Chargement de config.php.
 *
 * config.php est facultatif : un build sur fixtures doit tourner sur une copie
 * fraîche du dépôt, sans réseau et sans rien avoir configuré. Les clés
 * manquantes prennent une valeur par défaut, et c'est au moment de s'en servir
 * qu'on refuse une valeur vide — pas au chargement.
 *
 * @return array{
 *     csv_carte: string, csv_infos: string,
 *     sortie_html: string, secret_publication: string
 * }
 */
function config_charger(string $racine): array
{
    $defauts = [
        'csv_carte' => '',
        'csv_infos' => '',
        // En local, la sortie est à côté du générateur. Sur l'hébergement,
        // public_html/ n'est pas dans le dossier de l'application : le chemin
        // est alors donné explicitement dans config.php.
        'sortie_html' => $racine . '/public/carte.html',
        'secret_publication' => '',
    ];

    $chemin = $racine . '/config.php';

    if (!is_file($chemin)) {
        return $defauts;
    }

    $config = require $chemin;

    if (!is_array($config)) {
        throw new RuntimeException('config.php doit retourner un tableau.');
    }

    foreach ($config as $cle => $valeur) {
        if (!array_key_exists($cle, $defauts)) {
            throw new RuntimeException(sprintf('config.php : clé inconnue « %s ».', $cle));
        }

        if (!is_string($valeur)) {
            throw new RuntimeException(sprintf('config.php : « %s » doit être une chaîne.', $cle));
        }
    }

    return array_merge($defauts, $config);
}

/**
 * Lit une clé de configuration en refusant une valeur vide.
 *
 * @param array<string, string> $config
 */
function config_exiger(array $config, string $cle, string $pourquoi): string
{
    if (trim($config[$cle] ?? '') === '') {
        throw new RuntimeException(sprintf(
            "config.php : la clé « %s » est absente ou vide.\n  Elle est nécessaire %s.",
            $cle,
            $pourquoi
        ));
    }

    return $config[$cle];
}
