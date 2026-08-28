<?php

declare(strict_types=1);

/**
 * Présentation terminal du générateur.
 *
 *     php src/build.php                      # télécharge le Sheet, met à jour cache/
 *     php src/build.php --source=cache       # rejoue le dernier build, hors ligne
 *     php src/build.php --source=fixtures    # build sur les fixtures
 *
 * Toute la logique est dans src/lib/generateur.php ; ce fichier ne fait que
 * lire les arguments et mettre le compte rendu en forme pour un terminal.
 * public/publier.php fait le même travail pour un navigateur.
 */

require __DIR__ . '/lib/generateur.php';

// define() plutôt que const : une expression de constante ne peut pas appeler
// dirname(), et « __DIR__ . '/..' » ressortirait tel quel dans les messages.
define('RACINE', dirname(__DIR__));

const CODE_SUCCES = 0;
const CODE_ERREUR = 1;

exit(main($argv));

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $options = analyser_arguments($argv);

    if ($options['aide']) {
        echo aide();

        return CODE_SUCCES;
    }

    $resultat = generer($options['source'], RACINE);

    if (!$resultat['succes']) {
        fwrite(STDERR, "Échec : le site n'a pas été régénéré.\n\n");
        fwrite(STDERR, implode("\n", formater_erreurs($resultat['erreurs'])) . "\n");

        return CODE_ERREUR;
    }

    resumer($resultat);

    return CODE_SUCCES;
}

/**
 * @param list<string> $argv
 * @return array{source: string, aide: bool}
 */
function analyser_arguments(array $argv): array
{
    $options = ['source' => 'sheet', 'aide' => false];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['aide'] = true;

            continue;
        }

        if (str_starts_with($argument, '--source=')) {
            $options['source'] = substr($argument, strlen('--source='));

            continue;
        }

        fwrite(STDERR, sprintf("Argument inconnu : %s\n\n", $argument));
        $options['aide'] = true;
    }

    return $options;
}

/**
 * @param list<array{fichier: string|null, numero: int|null, message: string}> $erreurs
 * @return list<string>
 */
function formater_erreurs(array $erreurs): array
{
    $lignes = [];
    $fichierCourant = false;

    foreach ($erreurs as $erreur) {
        if ($erreur['fichier'] !== $fichierCourant) {
            $fichierCourant = $erreur['fichier'];

            if ($fichierCourant !== null) {
                $lignes[] = $fichierCourant;
            }
        }

        if ($erreur['fichier'] === null) {
            $lignes[] = $erreur['message'];

            continue;
        }

        $lignes[] = $erreur['numero'] === null
            ? sprintf('  %s', $erreur['message'])
            : sprintf('  ligne %d : %s', $erreur['numero'], $erreur['message']);
    }

    $lignes[] = '';
    $lignes[] = sprintf(
        '%d %s. Rien n\'a été écrit, la version précédente du site reste en place.',
        count($erreurs),
        accord(count($erreurs), 'erreur')
    );

    return $lignes;
}

/**
 * @param array<string, mixed> $resultat
 */
function resumer(array $resultat): void
{
    $detail = array_map(
        static fn (array $categorie): string => sprintf('%s (%d)', $categorie['nom'], $categorie['plats']),
        $resultat['categories']
    );

    printf("Source     : %s\n", $resultat['source']);
    printf(
        "Lignes     : %d %s, %d %s, %d %s (actif ≠ oui)\n",
        $resultat['lues'],
        accord($resultat['lues'], 'lue'),
        $resultat['actives'],
        accord($resultat['actives'], 'active'),
        $resultat['ignorees'],
        accord($resultat['ignorees'], 'ignorée')
    );
    printf("Catégories : %s\n", implode(' · ', $detail));
    printf(
        "Écrit      : %s dans %s (%s octets)\n",
        implode(', ', $resultat['pages']),
        $resultat['sortie'],
        number_format($resultat['octets'], 0, ',', ' ')
    );

    foreach ($resultat['avertissements'] as $avertissement) {
        fwrite(STDERR, sprintf("Avertissement : %s\n", $avertissement));
    }
}

function aide(): string
{
    return <<<'AIDE'
    Génère la page carte depuis le Google Sheet publié en CSV.

    Usage : php src/build.php [--source=<source>]

      --source=sheet      (défaut) télécharge les deux onglets depuis les URLs
                          de config.php, puis met cache/ à jour
      --source=cache      rejoue le dernier téléchargement, sans réseau
      --source=fixtures   lit fixtures/carte.csv et fixtures/infos.csv
      --source=<dossier>  lit carte.csv et infos.csv dans ce dossier
      --help, -h          affiche cette aide

    La page est écrite dans le chemin « sortie_html » de config.php, par défaut
    public/carte.html.

    Sortie : 0 si la page a été écrite, 1 si la validation a échoué.

    AIDE;
}
