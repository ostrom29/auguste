<?php

declare(strict_types=1);

/**
 * Génère public/carte.html depuis le Google Sheet publié en CSV.
 *
 *     php src/build.php                      # télécharge le Sheet, met à jour cache/
 *     php src/build.php --source=cache       # rejoue le dernier build, hors ligne
 *     php src/build.php --source=fixtures    # build sur les fixtures
 *
 * Le script valide d'abord et écrit ensuite : à la moindre erreur, rien n'est
 * écrit, le détail part sur stderr et le code de sortie est 1.
 */

require __DIR__ . '/lib/csv.php';
require __DIR__ . '/lib/source.php';
require __DIR__ . '/lib/validation.php';
require __DIR__ . '/lib/transformation.php';
require __DIR__ . '/lib/rendu.php';

const RACINE = __DIR__ . '/..';
const SORTIE_HTML = RACINE . '/public/carte.html';

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

    try {
        $brut = source_charger($options['source'], RACINE);
    } catch (RuntimeException $e) {
        return echouer([$e->getMessage()]);
    }

    $carteCsv = csv_lire($brut['carte']);
    $infosCsv = csv_lire($brut['infos']);

    // 1. Valider. Aucune écriture tant que ce n'est pas propre.
    $erreurs = array_merge(valider_carte($carteCsv), valider_infos($infosCsv));

    if ($erreurs !== []) {
        return echouer(formater_erreurs($erreurs));
    }

    // 2. Transformer.
    $carte = transformer_carte($carteCsv['lignes']);
    $infos = transformer_infos($infosCsv['lignes']);

    // 3. Rendre, puis écrire.
    $html = rendre_page($carte['categories'], $infos);

    try {
        $octets = ecrire($html);
    } catch (RuntimeException $e) {
        return echouer([$e->getMessage()]);
    }

    resumer($brut['description'], $carte, $octets, avertissements_infos($infos));

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
 * Écrit la page via un fichier temporaire renommé : si l'écriture échoue en
 * cours de route, la version précédente reste intacte.
 */
function ecrire(string $html): int
{
    $dossier = dirname(SORTIE_HTML);

    if (!is_dir($dossier) && !mkdir($dossier, 0o775, true) && !is_dir($dossier)) {
        throw new RuntimeException(sprintf('Création du dossier impossible : %s', $dossier));
    }

    $temporaire = SORTIE_HTML . '.tmp';
    $octets = file_put_contents($temporaire, $html);

    if ($octets === false) {
        throw new RuntimeException(sprintf('Écriture impossible : %s', $temporaire));
    }

    if (!rename($temporaire, SORTIE_HTML)) {
        @unlink($temporaire);

        throw new RuntimeException(sprintf('Remplacement impossible : %s', SORTIE_HTML));
    }

    return $octets;
}

/**
 * @param list<array{fichier: string, numero: int|null, message: string}> $erreurs
 * @return list<string>
 */
function formater_erreurs(array $erreurs): array
{
    $lignes = [];
    $fichierCourant = null;

    foreach ($erreurs as $erreur) {
        if ($erreur['fichier'] !== $fichierCourant) {
            $fichierCourant = $erreur['fichier'];
            $lignes[] = $fichierCourant;
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
 * Accord en nombre à la française : 0 et 1 restent au singulier.
 */
function accord(int $nombre, string $mot): string
{
    return $nombre > 1 ? $mot . 's' : $mot;
}

/**
 * @param list<string> $lignes
 */
function echouer(array $lignes): int
{
    fwrite(STDERR, "Échec : le site n'a pas été régénéré.\n\n");
    fwrite(STDERR, implode("\n", $lignes) . "\n");

    return CODE_ERREUR;
}

/**
 * @param array{categories: list<array{nom: string, plats: list<mixed>}>, lues: int, actives: int, ignorees: int} $carte
 * @param list<string> $avertissements
 */
function resumer(string $source, array $carte, int $octets, array $avertissements): void
{
    $detail = array_map(
        static fn (array $categorie): string => sprintf(
            '%s (%d)',
            $categorie['nom'],
            count($categorie['plats'])
        ),
        $carte['categories']
    );

    printf("Source     : %s\n", $source);
    printf(
        "Lignes     : %d %s, %d %s, %d %s (actif ≠ oui)\n",
        $carte['lues'],
        accord($carte['lues'], 'lue'),
        $carte['actives'],
        accord($carte['actives'], 'active'),
        $carte['ignorees'],
        accord($carte['ignorees'], 'ignorée')
    );
    printf("Catégories : %s\n", implode(' · ', $detail));
    printf("Écrit      : public/carte.html (%s octets)\n", number_format($octets, 0, ',', ' '));

    foreach ($avertissements as $avertissement) {
        fwrite(STDERR, sprintf("Avertissement : %s\n", $avertissement));
    }
}

function aide(): string
{
    return <<<'AIDE'
    Génère public/carte.html depuis le Google Sheet publié en CSV.

    Usage : php src/build.php [--source=<source>]

      --source=sheet      (défaut) télécharge les deux onglets depuis les URLs
                          de config.php, puis met cache/ à jour
      --source=cache      rejoue le dernier téléchargement, sans réseau
      --source=fixtures   lit fixtures/carte.csv et fixtures/infos.csv
      --source=<dossier>  lit carte.csv et infos.csv dans ce dossier
      --help, -h          affiche cette aide

    Sortie : 0 si la page a été écrite, 1 si la validation a échoué.

    AIDE;
}
