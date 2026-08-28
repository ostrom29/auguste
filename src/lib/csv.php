<?php

declare(strict_types=1);

/**
 * Lecture CSV.
 *
 * Les colonnes sont retrouvées par le nom lu dans la ligne d'en-tête, jamais
 * par position : déplacer une colonne dans le Google Sheet ne casse rien.
 */

/**
 * Découpe un CSV en en-têtes + lignes indexées par nom de colonne.
 *
 * Chaque ligne retournée porte son numéro de ligne physique dans le fichier
 * (l'en-tête étant la ligne 1), pour que les messages d'erreur renvoient le
 * restaurateur au bon endroit dans son tableur.
 *
 * @return array{
 *     entetes: list<string>,
 *     lignes: list<array{numero: int, champs: array<string, string>}>
 * }
 */
function csv_lire(string $contenu): array
{
    $contenu = csv_retirer_bom($contenu);

    $flux = fopen('php://memory', 'r+');
    if ($flux === false) {
        throw new RuntimeException('Impossible d\'ouvrir un flux mémoire pour lire le CSV.');
    }
    fwrite($flux, $contenu);
    rewind($flux);

    $entetes = null;
    $lignes = [];
    $numero = 1;
    $position = 0;

    while (($champs = fgetcsv($flux, 0, ',', '"', '')) !== false) {
        $numeroLigne = $numero;

        // fgetcsv ne dit pas combien de lignes physiques il a consommées ; on
        // le déduit du déplacement dans le flux, pour rester juste même quand
        // un champ entre guillemets contient un retour à la ligne.
        $apres = (int) ftell($flux);
        $numero += substr_count(substr($contenu, $position, $apres - $position), "\n");
        $position = $apres;

        if (csv_ligne_vide($champs)) {
            continue;
        }

        if ($entetes === null) {
            $entetes = array_map(
                static fn ($valeur): string => csv_normaliser_entete((string) $valeur),
                $champs
            );
            continue;
        }

        $lignes[] = [
            'numero' => $numeroLigne,
            'champs' => csv_associer($entetes, $champs),
        ];
    }

    fclose($flux);

    return [
        'entetes' => $entetes ?? [],
        'lignes' => $lignes,
    ];
}

/**
 * Associe les valeurs d'une ligne aux noms de colonnes.
 *
 * Une ligne plus courte que l'en-tête donne des champs vides ; les valeurs en
 * trop sont ignorées.
 *
 * @param list<string> $entetes
 * @param list<string|null> $champs
 * @return array<string, string>
 */
function csv_associer(array $entetes, array $champs): array
{
    $ligne = [];

    foreach ($entetes as $index => $entete) {
        if ($entete === '') {
            continue;
        }
        $ligne[$entete] = trim((string) ($champs[$index] ?? ''));
    }

    return $ligne;
}

/**
 * Normalise un nom de colonne : BOM, espaces autour, casse.
 */
function csv_normaliser_entete(string $entete): string
{
    return mb_strtolower(trim(csv_retirer_bom($entete)), 'UTF-8');
}

function csv_retirer_bom(string $texte): string
{
    $bom = "\xEF\xBB\xBF";

    return str_starts_with($texte, $bom) ? substr($texte, strlen($bom)) : $texte;
}

/**
 * Une ligne sans aucune valeur : fgetcsv rend `[null]` sur une ligne vide.
 *
 * @param list<string|null> $champs
 */
function csv_ligne_vide(array $champs): bool
{
    foreach ($champs as $champ) {
        if (trim((string) $champ) !== '') {
            return false;
        }
    }

    return true;
}
