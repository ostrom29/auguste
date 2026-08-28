<?php

declare(strict_types=1);

/**
 * Configuration du générateur.
 *
 * Copiez ce fichier en `config.php` à la racine du projet, puis adaptez les
 * valeurs. `config.php` n'est pas versionné.
 *
 *     cp config.example.php config.php
 *
 * Les deux URLs sont celles de « Fichier > Partager > Publier sur le Web »,
 * un onglet à la fois, au format « Valeurs séparées par des virgules (.csv) ».
 */

return [
    // Onglet « carte » : categorie, nom, description, prix, allergenes, ordre, actif
    'csv_carte' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vS03y970vwCxMbMjc9L2w15Tkff2lPLt90zWlQuyHoqPoJUAWn0ijor-ARavhDIZcZKLi5wKJU70q61/pub?gid=0&single=true&output=csv',

    // Onglet « infos » : cle, valeur
    'csv_infos' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vS03y970vwCxMbMjc9L2w15Tkff2lPLt90zWlQuyHoqPoJUAWn0ijor-ARavhDIZcZKLi5wKJU70q61/pub?gid=93767807&single=true&output=csv',

    // Où écrire les pages : index.html et carte.html.
    //
    // En local, laissez la ligne commentée : la sortie va dans public/,
    // à côté du générateur.
    //
    // Sur l'hébergement, le générateur vit dans ~/auguste/ et le site dans
    // ~/public_html/ : les deux ne sont plus voisins, il faut donc le dire.
    // Remplacez « xxxxx » par le nom de votre compte cPanel.
    //
    // 'sortie_dossier' => '/home/xxxxx/public_html',

    // Secret de public/publier.php. Sans lui, l'endpoint refuse de tourner.
    //
    // Générez-en un et ne le réutilisez nulle part ailleurs :
    //     php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
    //
    // Il doit faire au moins 16 caractères.
    'secret_publication' => 'a-remplacer-par-un-secret-genere',
];
