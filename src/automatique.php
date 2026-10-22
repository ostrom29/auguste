<?php

declare(strict_types=1);

/**
 * Publication automatique, lancée par une tâche cron toutes les dix minutes.
 *
 *     php src/automatique.php
 *     php src/automatique.php --source=fixtures/casse-donnees --etat=/tmp/e.json --essai
 *
 * Le restaurateur modifie le Sheet depuis son téléphone, où le menu « Site >
 * Publier » n'existe pas : Google n'affiche pas les menus Apps Script dans ses
 * applis mobiles. Ce script fait le clic à sa place.
 *
 * Il ne dit rien quand tout va bien : cron envoie par courriel tout ce qu'une
 * tâche écrit. Quand la publication échoue, il prévient `courriel_alertes`,
 * une seule fois par problème, puis une fois encore quand c'est réparé.
 *
 * Il ne prévient pas au premier échec. Une ligne en cours de saisie — le nom
 * tapé, pas encore le prix — est une erreur pendant deux minutes, et le Sheet
 * est parfois injoignable un instant : deux courriels pour rien, qui
 * apprendraient surtout à ne plus les lire.
 *
 *   --source=<source>   comme build.php, « sheet » par défaut
 *   --etat=<fichier>    où retenir le problème en cours, cache/alerte.json par défaut
 *   --essai             affiche les courriels au lieu de les envoyer
 */

require __DIR__ . '/lib/generateur.php';

define('RACINE', dirname(__DIR__));

/** Passages consécutifs avant de prévenir : vingt minutes pour une carte mal remplie. */
const SEUIL_DONNEES = 2;

/** Une heure pour une panne : réseau ou Google se rétablissent souvent seuls. */
const SEUIL_TECHNIQUE = 6;

exit(main($argv));

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $options = ['source' => 'sheet', 'etat' => RACINE . '/cache/alerte.json', 'essai' => false];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--essai') {
            $options['essai'] = true;
        } elseif (str_starts_with($argument, '--source=')) {
            $options['source'] = substr($argument, strlen('--source='));
        } elseif (str_starts_with($argument, '--etat=')) {
            $options['etat'] = substr($argument, strlen('--etat='));
        } else {
            fwrite(STDERR, sprintf("Argument inconnu : %s\n", $argument));

            return 1;
        }
    }

    // Un passage lent — le Sheet qui répond mal, trois essais de trente
    // secondes — ne doit pas croiser le suivant.
    $verrou = fopen($options['etat'] . '.verrou', 'c');

    if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
        return 0;
    }

    $resultat = generer($options['source'], RACINE);
    $etat = lire_etat($options['etat']);

    if ($resultat['succes']) {
        if ($etat['prevenu']) {
            alerter(
                'la carte du site est de nouveau à jour',
                ['Le problème signalé plus tôt est réglé : le site affiche de nouveau '
                    . 'la carte du Google Sheet.'],
                $options['essai']
            );
        }

        @unlink($options['etat']);

        return 0;
    }

    $lignes = lignes_erreurs($resultat['erreurs']);
    $donnees = erreurs_sont_de_donnees($resultat['erreurs']);
    $signature = sha1(implode("\n", $lignes));

    // Un autre problème que le précédent repart de zéro : c'est de lui qu'il
    // faudra parler.
    if ($etat['signature'] !== $signature) {
        $etat = ['signature' => $signature, 'passages' => 0, 'prevenu' => false];
    }

    ++$etat['passages'];

    if (!$etat['prevenu'] && $etat['passages'] >= ($donnees ? SEUIL_DONNEES : SEUIL_TECHNIQUE)) {
        $consigne = $donnees
            ? 'Corrigez ces lignes dans le Google Sheet : le site se mettra à jour tout seul '
                . 'dans les dix minutes.'
            : 'Ce problème ne se corrige pas depuis le tableur : prévenez la personne qui '
                . 's\'occupe du site.';

        $etat['prevenu'] = alerter(
            'la carte du site n\'a pas pu être mise à jour',
            array_merge(
                ['Le site n\'a pas pu reprendre la carte du Google Sheet. La version '
                    . 'précédente reste en ligne, sans changement.', ''],
                $lignes,
                ['', $consigne]
            ),
            $options['essai']
        );
    }

    file_put_contents($options['etat'], json_encode($etat));

    return 1;
}

/**
 * @return array{signature: string, passages: int, prevenu: bool}
 */
function lire_etat(string $chemin): array
{
    $etat = is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;

    return is_array($etat)
        ? ['signature' => (string) $etat['signature'], 'passages' => (int) $etat['passages'],
            'prevenu' => (bool) $etat['prevenu']]
        : ['signature' => '', 'passages' => 0, 'prevenu' => false];
}

/**
 * Envoie l'alerte, ou l'écrit sur la sortie d'erreur si personne n'est
 * désigné : cron la transmet alors au compte cPanel, s'il en a une adresse.
 *
 * @param list<string> $lignes
 */
function alerter(string $objet, array $lignes, bool $essai): bool
{
    $config = config_charger(RACINE);
    $domaine = parse_url($config['url_site'], PHP_URL_HOST) ?: 'localhost';
    $destinataires = $config['courriel_alertes'];

    $sujet = 'Site ' . $domaine . ' : ' . $objet;
    $corps = implode("\n", array_merge(['Bonjour,', ''], $lignes, ['', '— Le site ' . $domaine, '']));

    if ($essai || $destinataires === '') {
        fwrite($essai ? STDOUT : STDERR, sprintf(
            "Pour : %s\nSujet : %s\n\n%s",
            $destinataires === '' ? '(personne)' : $destinataires,
            $sujet,
            $corps
        ));

        return true;
    }

    $entetes = [
        'From: Site ' . $domaine . ' <no-reply@' . $domaine . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: chez-auguste',
    ];

    // Un sujet accentué doit être encodé, sans quoi certaines messageries
    // l'affichent en caractères cassés.
    $envoye = mail($destinataires, mb_encode_mimeheader($sujet, 'UTF-8'), $corps, implode("\r\n", $entetes));

    if (!$envoye) {
        fwrite(STDERR, "Échec de l'envoi de l'alerte à $destinataires :\n$sujet\n\n$corps");
    }

    return $envoye;
}
