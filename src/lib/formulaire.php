<?php

declare(strict_types=1);

/**
 * Ce que partagent les deux formulaires du site.
 *
 * Le contact et la demande de réservation ont la même mécanique : nettoyer,
 * écarter les robots, valider, envoyer un courriel, réafficher la page avec
 * ce qui a été saisi. Seuls les champs changent.
 *
 * Ce fichier vit hors de public_html : il n'est jamais servi.
 */

/** Un humain met plus de trois secondes à lire et remplir un formulaire. */
const DELAI_MINIMUM = 3;

/** Au-delà, la soumission est trop vieille pour être honnête (ou rejouée). */
const DELAI_MAXIMUM = 7200;

/**
 * Retire les caractères de contrôle.
 *
 * `$uneSeuleLigne` retire aussi les sauts de ligne, ce qui est indispensable
 * pour tout ce qui finit dans un en-tête de courriel : un « \n » dans le nom
 * suffirait sinon à ajouter un « Bcc: » au message.
 */
function nettoyer(string $valeur, bool $uneSeuleLigne): string
{
    $valeur = str_replace(["\r", "\0"], '', $valeur);

    // Tous les caractères de contrôle sauf le saut de ligne et la tabulation.
    $valeur = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valeur);

    if ($uneSeuleLigne) {
        $valeur = str_replace("\n", ' ', $valeur);
    }

    return trim($valeur);
}

/**
 * Le refus des robots, commun aux deux formulaires.
 *
 * Pot de miel : un champ invisible que seul un automate remplit. Il ne coûte
 * ni captcha au visiteur, ni service tiers, ni JavaScript.
 *
 * @param array<string, mixed> $envoi
 * @return list<string> vide si la soumission a l'air humaine
 */
function refuser_robot(array $envoi): array
{
    if (trim((string) ($envoi['site_web'] ?? '')) !== '') {
        return ['Message refusé.'];
    }

    $depuis = time() - (int) ($envoi['ouvert_a'] ?? 0);

    if ($depuis < DELAI_MINIMUM || $depuis > DELAI_MAXIMUM) {
        return ['Message refusé. Rechargez la page et réessayez.'];
    }

    return [];
}

/**
 * Envoie un courriel au restaurant.
 *
 * L'expéditeur reste le domaine, pour rester conforme au SPF ; l'adresse du
 * visiteur va dans Reply-To, où « Répondre » la trouvera.
 *
 * @param array<string, string> $gabarit
 */
function expedier(array $gabarit, string $sujet, string $corps, string $repondreA): bool
{
    if ($gabarit['email'] === '') {
        return false;
    }

    $entetes = [
        'From: Site ' . $gabarit['nom'] . ' <no-reply@' . domaine($gabarit) . '>',
        'Reply-To: ' . $repondreA,
        'Content-Type: text/plain; charset=utf-8',
        'X-Mailer: chez-auguste',
    ];

    return mail($gabarit['email'], $sujet, $corps, implode("\r\n", $entetes));
}

/**
 * @param array<string, string> $gabarit
 */
function domaine(array $gabarit): string
{
    $hote = parse_url($gabarit['url_site'], PHP_URL_HOST);

    return is_string($hote) && $hote !== ''
        ? $hote
        : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * La page complète, décor compris.
 *
 * @param array<string, mixed> $gabarit
 */
function page_formulaire(array $gabarit, string $page, string $titre, string $h1, string $corps): string
{
    $entete = $gabarit['entetes'][$page] ?? reset($gabarit['entetes']);
    $style = $gabarit['style'];
    $pied = $gabarit['pied'];

    return <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>{$titre}</title>
      <meta name="robots" content="noindex">
      <link rel="stylesheet" href="{$style}">
      <link rel="icon" href="img/favicon.png" type="image/png">
    </head>
    <body class="page page--formulaire">
      <a class="evitement" href="#contenu">Aller au contenu</a>
    {$entete}
      <main id="contenu" class="texte">
        <h1 class="texte__titre">{$h1}</h1>
    {$corps}
      </main>
    {$pied}
    </body>
    </html>

    HTML;
}

/**
 * Le bandeau d'erreurs, au-dessus du formulaire.
 *
 * @param list<string> $erreurs
 */
function bloc_erreurs(array $erreurs): string
{
    if ($erreurs === []) {
        return '';
    }

    $items = implode("\n", array_map(
        static fn (string $e): string => '        <li>' . h($e) . '</li>',
        $erreurs
    ));

    return "    <div class=\"erreurs\" role=\"alert\">\n      <ul>\n{$items}\n      </ul>\n    </div>\n";
}

/**
 * Le champ invisible et l'horodatage, à poser dans chaque formulaire.
 */
function champs_antirobot(): string
{
    $ouvert = time();

    return <<<HTML
          <input type="hidden" name="ouvert_a" value="{$ouvert}">

          <p class="formulaire__piege" aria-hidden="true">
            <label for="site_web">Ne remplissez pas ce champ</label>
            <input id="site_web" name="site_web" type="text" tabindex="-1" autocomplete="off">
          </p>
    HTML;
}

function h(string $texte): string
{
    return htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
