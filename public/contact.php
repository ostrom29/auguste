<?php

declare(strict_types=1);

/**
 * Formulaire de contact.
 *
 * La mécanique commune aux deux formulaires du site est dans
 * src/lib/formulaire.php, hors de public_html. Ici, seuls les champs.
 */

// Le générateur n'est pas au même endroit en local et sur l'hébergement.
foreach ([dirname(__DIR__), dirname(__DIR__) . '/auguste'] as $candidat) {
    if (is_file($candidat . '/src/lib/formulaire.php')) {
        require $candidat . '/src/lib/formulaire.php';

        break;
    }
}

if (!function_exists('nettoyer')) {
    http_response_code(500);
    exit('Le générateur est introuvable.');
}

const CHAMPS_MAXIMUM = ['nom' => 100, 'email' => 190, 'message' => 4000];

if (PHP_SAPI !== 'cli') {
    traiter_requete();
}

// ---------------------------------------------------------------------------

function traiter_requete(): void
{
    $gabarit = charger_gabarit();
    $valeurs = ['nom' => '', 'email' => '', 'message' => ''];
    $erreurs = [];
    $envoye = false;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach ($valeurs as $champ => $_) {
            // Le message garde ses retours à la ligne : il finit dans le corps
            // du courriel. Le nom et l'adresse finissent dans des en-têtes, où
            // un saut de ligne permettrait d'en injecter d'autres.
            $valeurs[$champ] = nettoyer((string) ($_POST[$champ] ?? ''), $champ !== 'message');
        }

        $erreurs = refuser_robot($_POST) ?: valider($valeurs);

        if ($erreurs === []) {
            $envoye = envoyer($valeurs, $gabarit);

            if (!$envoye) {
                $erreurs[] = 'Le message n’a pas pu être envoyé. '
                    . 'Vous pouvez nous appeler, le téléphone est en bas de page.';
            }
        }
    }

    echo page_formulaire(
        $gabarit,
        'contact.php',
        'Nous écrire — ' . $gabarit['nom'],
        'Nous écrire',
        $envoye ? bloc_confirmation() : bloc_formulaire($valeurs, $erreurs)
    );
}

/**
 * @return array<string, mixed>
 */
function charger_gabarit(): array
{
    $chemin = __DIR__ . '/gabarit.php';

    if (!is_file($chemin)) {
        http_response_code(500);
        exit('Le site n’a pas encore été généré.');
    }

    return require $chemin;
}

/**
 * @param array<string, string> $valeurs
 * @return list<string>
 */
function valider(array $valeurs): array
{
    $erreurs = [];

    if ($valeurs['nom'] === '') {
        $erreurs[] = 'Votre nom est nécessaire pour vous répondre.';
    }

    if (filter_var($valeurs['email'], FILTER_VALIDATE_EMAIL) === false) {
        $erreurs[] = 'Cette adresse électronique ne semble pas valide.';
    }

    if (mb_strlen($valeurs['message']) < 10) {
        $erreurs[] = 'Le message est un peu court.';
    }

    foreach (CHAMPS_MAXIMUM as $champ => $maximum) {
        if (mb_strlen($valeurs[$champ]) > $maximum) {
            $erreurs[] = sprintf('Le champ « %s » dépasse %d caractères.', $champ, $maximum);
        }
    }

    return $erreurs;
}

/**
 * @param array<string, string> $valeurs
 * @param array<string, mixed> $gabarit
 */
function envoyer(array $valeurs, array $gabarit): bool
{
    $corps = implode("\n", [
        'Message reçu depuis le site.',
        '',
        'Nom      : ' . $valeurs['nom'],
        'Courriel : ' . $valeurs['email'],
        'Date     : ' . date('d/m/Y à H\hi'),
        '',
        '--',
        '',
        $valeurs['message'],
        '',
    ]);

    return expedier($gabarit, '[Site] Message de ' . $valeurs['nom'], $corps, $valeurs['email']);
}

function bloc_confirmation(): string
{
    return implode("\n", [
        '    <p class="message" role="status">Votre message est parti. Nous vous répondrons '
            . 'à l’adresse que vous avez indiquée.</p>',
        '    <p><a class="bouton bouton--discret" href="index.html">Retour à l’accueil</a></p>',
    ]);
}

/**
 * @param array<string, string> $valeurs
 * @param list<string> $erreurs
 */
function bloc_formulaire(array $valeurs, array $erreurs): string
{
    $v = array_map('h', $valeurs);
    $antirobot = champs_antirobot();

    return bloc_erreurs($erreurs) . <<<HTML
        <p>Une question, un groupe à placer, une remarque ? Écrivez-nous, ou
        appelez-nous : le numéro est en bas de page.</p>

        <form class="formulaire" method="post" action="contact.php">
    {$antirobot}
          <p class="formulaire__champ">
            <label for="nom">Votre nom</label>
            <input id="nom" name="nom" type="text" required maxlength="100"
                   autocomplete="name" value="{$v['nom']}">
          </p>

          <p class="formulaire__champ">
            <label for="email">Votre adresse électronique</label>
            <input id="email" name="email" type="email" required maxlength="190"
                   autocomplete="email" value="{$v['email']}">
          </p>

          <p class="formulaire__champ">
            <label for="message">Votre message</label>
            <textarea id="message" name="message" rows="7" required
                      maxlength="4000">{$v['message']}</textarea>
          </p>

          <p class="formulaire__envoi">
            <button class="bouton" type="submit">Envoyer</button>
          </p>

          <p class="formulaire__rgpd">
            Votre nom, votre adresse et votre message nous servent uniquement à vous
            répondre, et sont conservés un an. Voir notre
            <a href="confidentialite.html">politique de confidentialité</a>.
          </p>
        </form>
    HTML;
}
