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
];
