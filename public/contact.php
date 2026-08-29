<?php

declare(strict_types=1);

/**
 * Formulaire de contact.
 *
 * Seule page dynamique du site : elle change selon ce que le visiteur envoie.
 * Elle reprend l'en-tête et le pied par gabarit.php, écrit par le build, pour
 * qu'il n'existe qu'une seule source de vérité pour le décor.
 *
 * Aucun JavaScript : le formulaire fonctionne script désactivé, y compris sa
 * protection anti-robots.
 */

const CHAMPS_MAXIMUM = [
    'nom' => 100,
    'email' => 190,
    'message' => 4000,
];

/** Un humain met plus de trois secondes à lire et remplir trois champs. */
const DELAI_MINIMUM = 3;

/** Au-delà, la soumission est trop vieille pour être honnête (ou rejouée). */
const DELAI_MAXIMUM = 7200;

// En ligne de commande, le fichier ne fait que définir ses fonctions : c'est
// ce qui permet à src/verif.sh de tester le nettoyage des champs sans avoir à
// simuler une requête.
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

        $erreurs = valider($valeurs, $_POST);

        if ($erreurs === []) {
            $envoye = expedier($valeurs, $gabarit);

            if (!$envoye) {
                $erreurs[] = 'Le message n’a pas pu être envoyé. '
                    . 'Vous pouvez nous appeler, le téléphone est en bas de page.';
            }
        }
    }

    echo page($gabarit, $valeurs, $erreurs, $envoye);
}

/**
 * @return array<string, string>
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
 * @param array<string, string> $valeurs
 * @param array<string, mixed> $envoi
 * @return list<string>
 */
function valider(array $valeurs, array $envoi): array
{
    $erreurs = [];

    // Pot de miel : un champ invisible que seul un robot remplit. Il ne coûte
    // ni captcha au visiteur, ni service tiers, ni JavaScript.
    if (trim((string) ($envoi['site_web'] ?? '')) !== '') {
        return ['Message refusé.'];
    }

    $depuis = time() - (int) ($envoi['ouvert_a'] ?? 0);

    if ($depuis < DELAI_MINIMUM || $depuis > DELAI_MAXIMUM) {
        return ['Message refusé. Rechargez la page et réessayez.'];
    }

    if ($valeurs['nom'] === '') {
        $erreurs[] = 'Votre nom est nécessaire pour vous répondre.';
    }

    if ($valeurs['email'] === '' || filter_var($valeurs['email'], FILTER_VALIDATE_EMAIL) === false) {
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
 * @param array<string, string> $gabarit
 */
function expedier(array $valeurs, array $gabarit): bool
{
    $destinataire = $gabarit['email'];

    if ($destinataire === '') {
        return false;
    }

    $corps = implode("\n", [
        'Message reçu depuis le site.',
        '',
        'Nom     : ' . $valeurs['nom'],
        'Courriel: ' . $valeurs['email'],
        'Date    : ' . date('d/m/Y à H\hi'),
        '',
        '--',
        '',
        $valeurs['message'],
        '',
    ]);

    // L'expéditeur reste le domaine, pour rester conforme au SPF ; l'adresse
    // du visiteur va dans Reply-To, où « Répondre » la trouvera.
    $entetes = [
        'From: Site ' . $gabarit['nom'] . ' <no-reply@' . domaine($gabarit) . '>',
        'Reply-To: ' . $valeurs['email'],
        'Content-Type: text/plain; charset=utf-8',
        'X-Mailer: chez-auguste-contact',
    ];

    return mail(
        $destinataire,
        '[Site] Message de ' . $valeurs['nom'],
        $corps,
        implode("\r\n", $entetes)
    );
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
 * @param array<string, string> $gabarit
 * @param array<string, string> $valeurs
 * @param list<string> $erreurs
 */
function page(array $gabarit, array $valeurs, array $erreurs, bool $envoye): string
{
    $titre = 'Nous écrire — ' . $gabarit['nom'];
    $corps = $envoye ? bloc_confirmation() : bloc_formulaire($valeurs, $erreurs);

    return <<<HTML
    <!DOCTYPE html>
    <html lang="fr">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>{$titre}</title>
      <meta name="description" content="Écrire à {$gabarit['nom']}.">
      <meta name="robots" content="noindex">
      <link rel="stylesheet" href="style.css">
      <link rel="icon" href="img/favicon.png" type="image/png">
    </head>
    <body class="page page--contact">
      <a class="evitement" href="#contenu">Aller au contenu</a>
    {$gabarit['entete']}
      <main id="contenu" class="texte">
        <h1 class="texte__titre">Nous écrire</h1>
    {$corps}
      </main>
    {$gabarit['pied']}
    </body>
    </html>

    HTML;
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
    $alerte = '';

    if ($erreurs !== []) {
        $items = implode("\n", array_map(
            static fn (string $e): string => '        <li>' . h($e) . '</li>',
            $erreurs
        ));

        $alerte = "    <div class=\"erreurs\" role=\"alert\">\n"
            . "      <ul>\n{$items}\n      </ul>\n    </div>\n";
    }

    $ouvert = time();
    $nom = h($valeurs['nom']);
    $email = h($valeurs['email']);
    $message = h($valeurs['message']);

    return $alerte . <<<HTML
        <p>Une question, un groupe à placer, une remarque ? Écrivez-nous, ou
        appelez-nous : le numéro est en bas de page.</p>

        <form class="formulaire" method="post" action="contact.php">
          <input type="hidden" name="ouvert_a" value="{$ouvert}">

          <p class="formulaire__champ">
            <label for="nom">Votre nom</label>
            <input id="nom" name="nom" type="text" required maxlength="100"
                   autocomplete="name" value="{$nom}">
          </p>

          <p class="formulaire__champ">
            <label for="email">Votre adresse électronique</label>
            <input id="email" name="email" type="email" required maxlength="190"
                   autocomplete="email" value="{$email}">
          </p>

          <p class="formulaire__champ">
            <label for="message">Votre message</label>
            <textarea id="message" name="message" rows="7" required
                      maxlength="4000">{$message}</textarea>
          </p>

          <p class="formulaire__piege" aria-hidden="true">
            <label for="site_web">Ne remplissez pas ce champ</label>
            <input id="site_web" name="site_web" type="text" tabindex="-1" autocomplete="off">
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

function h(string $texte): string
{
    return htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
