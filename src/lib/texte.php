<?php

declare(strict_types=1);

/**
 * Petits utilitaires de texte partagés par les trois sorties : la page du
 * site, le terminal et l'endpoint web.
 */

/**
 * Échappe & < > et le guillemet double.
 *
 * L'apostrophe est laissée telle quelle : elle n'a rien de dangereux dans du
 * texte ni dans un attribut entre guillemets doubles — et aucun gabarit n'en
 * utilise d'autres — alors qu'un « d&#039;Isigny » rendrait le HTML généré
 * illisible à la relecture, dans une carte française pleine d'apostrophes.
 */
function e(string $texte): string
{
    return htmlspecialchars($texte, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Accord en nombre à la française : 0 et 1 restent au singulier.
 */
function accord(int $nombre, string $mot): string
{
    return $nombre > 1 ? $mot . 's' : $mot;
}
