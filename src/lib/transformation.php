<?php

declare(strict_types=1);

/**
 * Du CSV validé vers la structure que le gabarit affiche.
 */

/** Espace insécable, notamment entre le montant et le symbole euro. */
const ESPACE_INSECABLE = "\u{00A0}";

/**
 * Regroupe les plats actifs par catégorie.
 *
 * Les catégories sortent dans leur ordre d'apparition dans le Sheet ; à
 * l'intérieur d'une catégorie, les plats sont triés par « ordre » croissant.
 *
 * @param list<array{numero: int, champs: array<string, string>}> $lignes
 * @return array{
 *     categories: list<array{nom: string, plats: list<array{
 *         nom: string, description: string, prix: string,
 *         allergenes: list<string>, ordre: int
 *     }>}>,
 *     lues: int, actives: int, ignorees: int
 * }
 */
function transformer_carte(array $lignes): array
{
    $groupes = [];
    $actives = 0;

    foreach ($lignes as $ligne) {
        $champs = $ligne['champs'];

        if (!ligne_active($champs['actif'])) {
            continue;
        }

        ++$actives;
        $categorie = $champs['categorie'];

        // La première apparition fixe la place de la catégorie dans la page.
        if (!isset($groupes[$categorie])) {
            $groupes[$categorie] = [];
        }

        $groupes[$categorie][] = [
            'nom' => $champs['nom'],
            'description' => $champs['description'],
            'prix' => formater_prix($champs['prix']),
            'allergenes' => decouper_allergenes($champs['allergenes']),
            'ordre' => (int) trim($champs['ordre']),
        ];
    }

    $categories = [];

    foreach ($groupes as $nom => $plats) {
        // usort est stable depuis PHP 8 : à « ordre » égal, l'ordre du Sheet
        // est conservé.
        usort($plats, static fn (array $a, array $b): int => $a['ordre'] <=> $b['ordre']);

        $categories[] = ['nom' => (string) $nom, 'plats' => $plats];
    }

    return [
        'categories' => $categories,
        'lues' => count($lignes),
        'actives' => $actives,
        'ignorees' => count($lignes) - $actives,
    ];
}

/**
 * Aplatit l'onglet « infos » en un simple tableau clé => valeur.
 *
 * @param list<array{numero: int, champs: array<string, string>}> $lignes
 * @return array<string, string>
 */
function transformer_infos(array $lignes): array
{
    $infos = [];

    foreach ($lignes as $ligne) {
        $cle = mb_strtolower(trim($ligne['champs']['cle']), 'UTF-8');

        if ($cle === '') {
            continue;
        }

        $infos[$cle] = $ligne['champs']['valeur'];
    }

    return $infos;
}

/**
 * 2.50 → « 2,50 € », avec une espace insécable avant le symbole.
 */
function formater_prix(string $brut): string
{
    $montant = (float) str_replace(',', '.', trim($brut));

    return number_format($montant, 2, ',', ESPACE_INSECABLE) . ESPACE_INSECABLE . '€';
}

/**
 * « œuf, moutarde » → ['œuf', 'moutarde']. Une cellule vide donne [].
 *
 * @return list<string>
 */
function decouper_allergenes(string $brut): array
{
    $allergenes = [];

    foreach (explode(',', $brut) as $allergene) {
        $allergene = trim($allergene);

        if ($allergene !== '') {
            $allergenes[] = $allergene;
        }
    }

    return $allergenes;
}
