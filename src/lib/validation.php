<?php

declare(strict_types=1);

/**
 * Validation des CSV, avant toute écriture.
 *
 * Le script valide d'abord et écrit ensuite : tant qu'une erreur subsiste,
 * `public/carte.html` n'est pas touché et la version en ligne reste en place.
 */

const CARTE_ENTETES = ['categorie', 'nom', 'description', 'prix', 'allergenes', 'ordre', 'actif'];
const INFOS_ENTETES = ['cle', 'valeur'];

/**
 * Colonnes facultatives : leur absence ne bloque pas la publication.
 *
 * « vedette » désigne les plats mis en avant sur la page d'accueil. Tant que
 * la colonne n'existe pas dans le Sheet, l'accueil se rabat sur le premier
 * plat de chaque catégorie.
 */
const CARTE_ENTETES_FACULTATIFS = ['vedette'];

/**
 * Clés de l'onglet « infos » attendues par le gabarit. Leur absence n'empêche
 * pas la publication : la section correspondante est simplement omise.
 */
// « accroche » n'y figure pas : vide est un choix légitime, le logo porte
// déjà la mention. Elle n'apparaît que si on la saisit.
const INFOS_CLES_ATTENDUES = [
    'nom', 'adresse', 'acces', 'telephone',
    'horaires_lundi', 'horaires_mardi', 'horaires_mercredi', 'horaires_jeudi',
    'horaires_vendredi', 'horaires_samedi', 'horaires_dimanche',
];

/**
 * Vérifie l'onglet « carte ».
 *
 * @param array{entetes: list<string>, lignes: list<array{numero: int, champs: array<string, string>}>} $csv
 * @return list<array{fichier: string, numero: int|null, message: string}>
 */
function valider_carte(array $csv): array
{
    $erreurs = valider_entetes($csv['entetes'], CARTE_ENTETES, 'carte.csv');

    // Sans en-tête correct, tout le reste serait du bruit.
    if ($erreurs !== []) {
        return $erreurs;
    }

    $actives = 0;

    foreach ($csv['lignes'] as $ligne) {
        $champs = $ligne['champs'];
        $numero = $ligne['numero'];

        if (!ligne_active($champs['actif'])) {
            continue;
        }

        ++$actives;

        if ($champs['nom'] === '') {
            $erreurs[] = erreur('carte.csv', $numero, 'le champ « nom » est vide');
        }

        if ($champs['categorie'] === '') {
            $erreurs[] = erreur('carte.csv', $numero, 'le champ « categorie » est vide');
        }

        if (!est_prix($champs['prix'])) {
            $erreurs[] = erreur('carte.csv', $numero, sprintf(
                'le champ « prix » doit être un nombre (lu : « %s »)',
                $champs['prix']
            ));
        }

        if (!est_entier($champs['ordre'])) {
            $erreurs[] = erreur('carte.csv', $numero, sprintf(
                'le champ « ordre » doit être un entier (lu : « %s »)',
                $champs['ordre']
            ));
        }
    }

    if ($actives === 0) {
        $erreurs[] = erreur(
            'carte.csv',
            null,
            'aucune ligne active : il faut au moins un plat avec « actif » à « oui »'
        );
    }

    return $erreurs;
}

/**
 * Vérifie l'onglet « infos ».
 *
 * @param array{entetes: list<string>, lignes: list<array{numero: int, champs: array<string, string>}>} $csv
 * @return list<array{fichier: string, numero: int|null, message: string}>
 */
function valider_infos(array $csv): array
{
    $erreurs = valider_entetes($csv['entetes'], INFOS_ENTETES, 'infos.csv');

    if ($erreurs !== []) {
        return $erreurs;
    }

    foreach ($csv['lignes'] as $ligne) {
        if ($ligne['champs']['cle'] === '') {
            $erreurs[] = erreur('infos.csv', $ligne['numero'], 'le champ « cle » est vide');
        }
    }

    return $erreurs;
}

/**
 * Signale, sans bloquer, les clés « infos » que le gabarit sait afficher mais
 * qui manquent au tableur.
 *
 * @param array<string, string> $infos
 * @return list<string>
 */
function avertissements_infos(array $infos): array
{
    $manquantes = [];

    foreach (INFOS_CLES_ATTENDUES as $cle) {
        if (!isset($infos[$cle]) || $infos[$cle] === '') {
            $manquantes[] = $cle;
        }
    }

    if ($manquantes === []) {
        return [];
    }

    return [sprintf(
        'onglet infos, clé(s) absente(s) ou vide(s) : %s',
        implode(', ', $manquantes)
    )];
}

/**
 * @param list<string> $lues
 * @param list<string> $attendues
 * @return list<array{fichier: string, numero: int|null, message: string}>
 */
function valider_entetes(array $lues, array $attendues, string $fichier): array
{
    if ($lues === []) {
        return [erreur($fichier, null, 'fichier vide : aucune ligne d\'en-tête')];
    }

    $manquantes = array_values(array_diff($attendues, $lues));

    if ($manquantes === []) {
        return [];
    }

    return [erreur($fichier, 1, sprintf(
        "en-tête incomplet, colonne(s) manquante(s) : %s\n         en-tête lu : %s",
        implode(', ', $manquantes),
        implode(', ', $lues)
    ))];
}

/**
 * @return array{fichier: string, numero: int|null, message: string}
 */
function erreur(string $fichier, ?int $numero, string $message): array
{
    return ['fichier' => $fichier, 'numero' => $numero, 'message' => $message];
}

/**
 * Seul « oui » (quelle que soit la casse) publie une ligne.
 */
function ligne_active(string $actif): bool
{
    return mb_strtolower(trim($actif), 'UTF-8') === 'oui';
}

/**
 * Un prix est un nombre positif, avec au plus deux décimales, point ou virgule.
 */
function est_prix(string $valeur): bool
{
    return preg_match('/^\d+(?:[.,]\d{1,2})?$/', trim($valeur)) === 1;
}

function est_entier(string $valeur): bool
{
    return preg_match('/^-?\d+$/', trim($valeur)) === 1;
}
