<?php

declare(strict_types=1);

/**
 * Demande de réservation.
 *
 * Une demande, pas une confirmation : rien n'est retenu tant que le
 * restaurant n'a pas rappelé. La page le dit, plutôt que de laisser croire
 * qu'une table est bloquée.
 *
 * Les jours et services proposés viennent des horaires du Sheet : on ne
 * laisse pas demander un dimanche si le restaurant est fermé le dimanche.
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

const COUVERTS_MAXIMUM = 40;
const JOURS_A_L_AVANCE = 365;

const CHAMPS = [
    'date' => '', 'heure' => '', 'couverts' => '',
    'nom' => '', 'telephone' => '', 'email' => '', 'message' => '',
];

if (PHP_SAPI !== 'cli') {
    traiter_requete();
}

// ---------------------------------------------------------------------------

function traiter_requete(): void
{
    $gabarit = charger_gabarit();

    // La prise de réservation est éteinte : la page se ferme, mais tout le
    // code reste en place. Une ligne « reservation » à « oui » dans l'onglet
    // infos la rallume, sans rien redéployer.
    if (!($gabarit['reservation_active'] ?? false)) {
        echo page_fermee($gabarit);

        return;
    }

    $valeurs = CHAMPS;
    $erreurs = [];
    $envoye = false;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach ($valeurs as $champ => $_) {
            $valeurs[$champ] = nettoyer((string) ($_POST[$champ] ?? ''), $champ !== 'message');
        }

        $erreurs = refuser_robot($_POST) ?: valider($valeurs, $gabarit);

        if ($erreurs === []) {
            $envoye = envoyer($valeurs, $gabarit);

            if (!$envoye) {
                $erreurs[] = 'La demande n’a pas pu être envoyée. '
                    . 'Appelez-nous, le téléphone est en bas de page.';
            }
        }
    }

    echo page_formulaire(
        $gabarit,
        'reservation.php',
        'Réserver — ' . $gabarit['nom'],
        'Demander une table',
        $envoye ? bloc_confirmation($valeurs) : bloc_formulaire($valeurs, $erreurs, $gabarit)
    );
}

/**
 * Ce que voit un visiteur venu d'un ancien lien ou d'un moteur.
 *
 * Statut 404 : la page n'existe plus en tant que formulaire, et il vaut mieux
 * que Google la retire de son index que de continuer à l'y proposer.
 *
 * @param array<string, mixed> $gabarit
 */
function page_fermee(array $gabarit): string
{
    http_response_code(404);

    $appel = $gabarit['telephone_lien'] === ''
        ? ''
        : '    <p><a class="bouton" href="tel:' . h($gabarit['telephone_lien']) . '">Appeler le '
            . h($gabarit['telephone']) . '</a></p>';

    return page_formulaire(
        $gabarit,
        'reservation.php',
        'Réservation — ' . $gabarit['nom'],
        'Nous ne prenons pas de réservation',
        implode("\n", array_filter([
            '    <p>Les tables se prennent sur place, à l’arrivée. C’est le principe '
                . 'de la maison, et cela n’a pas changé depuis longtemps.</p>',
            '    <p>Pour un groupe ou une occasion particulière, appelez-nous ou '
                . '<a href="contact.php">écrivez-nous</a> : nous verrons ce qu’il est '
                . 'possible de faire.</p>',
            $appel,
        ]))
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
 * @param array<string, mixed> $gabarit
 * @return list<string>
 */
function valider(array $valeurs, array $gabarit): array
{
    $erreurs = [];

    $jour = jour_demande($valeurs['date']);

    if ($jour === null) {
        $erreurs[] = 'La date n’est pas valide.';
    } elseif ($jour['passe']) {
        $erreurs[] = 'Cette date est déjà passée.';
    } elseif ($jour['trop_loin']) {
        $erreurs[] = 'Nous ne prenons pas de demande au-delà d’un an.';
    }

    $heureValide = in_array($valeurs['heure'], $gabarit['heures'], true);

    if (!$heureValide) {
        $erreurs[] = 'Choisissez une heure d’arrivée.';
    }

    // Le meilleur service qu'on puisse rendre : dire tout de suite que c'est
    // fermé, plutôt que de laisser attendre une réponse qui dira non.
    if ($jour !== null && !$jour['passe'] && $heureValide) {
        $plages = $gabarit['horaires'][$jour['cle']] ?? [];

        if ($plages === []) {
            $erreurs[] = sprintf('Nous sommes fermés le %s.', $jour['libelle']);
        } elseif (!heure_dans_plages($valeurs['heure'], $plages)) {
            $erreurs[] = sprintf(
                'Le %s, nous servons de %s. Choisissez une autre heure.',
                $jour['libelle'],
                plages_en_texte($plages)
            );
        }
    }

    $couverts = (int) $valeurs['couverts'];

    if ($couverts < 1) {
        $erreurs[] = 'Indiquez le nombre de personnes.';
    } elseif ($couverts > COUVERTS_MAXIMUM) {
        $erreurs[] = sprintf(
            'Au-delà de %d personnes, appelez-nous : cela se prépare de vive voix.',
            COUVERTS_MAXIMUM
        );
    }

    if ($valeurs['nom'] === '') {
        $erreurs[] = 'Votre nom est nécessaire.';
    }

    // Le téléphone compte plus que l'adresse : c'est par là qu'on rappelle.
    if (strlen((string) preg_replace('/\D/', '', $valeurs['telephone'])) < 9) {
        $erreurs[] = 'Ce numéro de téléphone ne semble pas valide.';
    }

    if (filter_var($valeurs['email'], FILTER_VALIDATE_EMAIL) === false) {
        $erreurs[] = 'Cette adresse électronique ne semble pas valide.';
    }

    if (mb_strlen($valeurs['message']) > 2000) {
        $erreurs[] = 'Le message est trop long.';
    }

    return $erreurs;
}

/**
 * L'heure demandée tombe-t-elle dans une plage d'ouverture ?
 *
 * On accepte jusqu'à une demi-heure avant la fermeture : le générateur ne
 * propose pas d'heure plus tardive, mais un envoi forgé pourrait en contenir.
 *
 * @param list<array{0: string, 1: string}> $plages
 */
function heure_dans_plages(string $heure, array $plages): bool
{
    $minute = minutes($heure);

    foreach ($plages as [$debut, $fin]) {
        if ($minute >= minutes($debut) && $minute <= minutes($fin) - 30) {
            return true;
        }
    }

    return false;
}

function minutes(string $heure): int
{
    [$h, $m] = array_map('intval', explode(':', $heure));

    return $h * 60 + $m;
}

/**
 * « 11h00 à 21h00 », ou « 12h00 à 14h30 et de 19h00 à 22h30 ».
 *
 * @param list<array{0: string, 1: string}> $plages
 */
function plages_en_texte(array $plages): string
{
    $textes = array_map(
        static fn (array $p): string => str_replace(':', 'h', $p[0]) . ' à ' . str_replace(':', 'h', $p[1]),
        $plages
    );

    return implode(' et de ', $textes);
}

/**
 * Analyse la date demandée.
 *
 * @return array{cle: string, libelle: string, passe: bool, trop_loin: bool}|null
 */
function jour_demande(string $date): ?array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return null;
    }

    $demande = date_create_immutable($date . ' 00:00:00');

    if ($demande === false) {
        return null;
    }

    $jours = ['Sunday' => 'dimanche', 'Monday' => 'lundi', 'Tuesday' => 'mardi',
        'Wednesday' => 'mercredi', 'Thursday' => 'jeudi', 'Friday' => 'vendredi',
        'Saturday' => 'samedi'];

    $cle = $jours[$demande->format('l')];
    $aujourdhui = date_create_immutable('today');

    return [
        'cle' => $cle,
        'libelle' => $cle,
        'passe' => $demande < $aujourdhui,
        'trop_loin' => $demande > $aujourdhui->modify('+' . JOURS_A_L_AVANCE . ' days'),
    ];
}

/**
 * @param array<string, string> $valeurs
 * @param array<string, mixed> $gabarit
 */
function envoyer(array $valeurs, array $gabarit): bool
{
    $jour = jour_demande($valeurs['date']);

    $corps = implode("\n", [
        'DEMANDE DE RÉSERVATION',
        '',
        'Date      : ' . date('d/m/Y', (int) strtotime($valeurs['date']))
            . ' (' . ($jour['libelle'] ?? '') . ')',
        'Heure     : ' . str_replace(':', 'h', $valeurs['heure']),
        'Couverts  : ' . (int) $valeurs['couverts'],
        '',
        'Nom       : ' . $valeurs['nom'],
        'Téléphone : ' . $valeurs['telephone'],
        'Courriel  : ' . $valeurs['email'],
        '',
        'Reçue le  : ' . date('d/m/Y à H\hi'),
        '',
        '--',
        '',
        $valeurs['message'] !== '' ? $valeurs['message'] : '(pas de message)',
        '',
        'Rappelez le client pour confirmer : rien n’est retenu à ce stade.',
        '',
    ]);

    return expedier(
        $gabarit,
        sprintf(
            '[Réservation] %s, %s à %s pour %d',
            $valeurs['nom'],
            date('d/m', (int) strtotime($valeurs['date'])),
            str_replace(':', 'h', $valeurs['heure']),
            (int) $valeurs['couverts']
        ),
        $corps,
        $valeurs['email']
    );
}

/**
 * @param array<string, string> $valeurs
 */
function bloc_confirmation(array $valeurs): string
{
    $quand = h(date('d/m/Y', (int) strtotime($valeurs['date'])));
    $heure = h(str_replace(':', 'h', $valeurs['heure']));
    $couverts = (int) $valeurs['couverts'];

    return implode("\n", [
        '    <p class="message" role="status">Votre demande est enregistrée : '
            . "{$couverts} personne" . ($couverts > 1 ? 's' : '') . ", le {$quand} à {$heure}.</p>",
        '    <p><strong>Ce n’est pas encore une réservation.</strong> Nous vous rappelons pour '
            . 'confirmer. Si votre demande est pour aujourd’hui ou demain, appelez-nous plutôt : '
            . 'le numéro est en bas de page.</p>',
        '    <p><a class="bouton bouton--discret" href="index.html">Retour à l’accueil</a></p>',
    ]);
}

/**
 * @param array<string, string> $valeurs
 * @param list<string> $erreurs
 * @param array<string, mixed> $gabarit
 */
function bloc_formulaire(array $valeurs, array $erreurs, array $gabarit): string
{
    $v = array_map('h', $valeurs);
    $antirobot = champs_antirobot();
    $minimum = date('Y-m-d');
    $maximum = date('Y-m-d', strtotime('+' . JOURS_A_L_AVANCE . ' days'));
    $max = COUVERTS_MAXIMUM;

    $options = '';

    foreach ($gabarit['heures'] as $heure) {
        $choisi = $valeurs['heure'] === $heure ? ' selected' : '';
        $options .= "\n                <option value=\"" . h($heure) . "\"{$choisi}>"
            . h(str_replace(':', 'h', $heure)) . '</option>';
    }

    $fermetures = bloc_fermetures($gabarit);

    return bloc_erreurs($erreurs) . <<<HTML
        <p>Indiquez ce que vous souhaitez, nous vous rappelons pour confirmer.
        <strong>Aucune table n’est retenue tant que nous ne vous avons pas répondu.</strong>
        Pour le jour même, appelez-nous : c’est plus sûr.</p>
    {$fermetures}
        <form class="formulaire" method="post" action="reservation.php">
    {$antirobot}
          <div class="formulaire__ligne">
            <p class="formulaire__champ">
              <label for="date">Date</label>
              <input id="date" name="date" type="date" required
                     min="{$minimum}" max="{$maximum}" value="{$v['date']}">
            </p>

            <p class="formulaire__champ">
              <label for="heure">Heure d’arrivée</label>
              <select id="heure" name="heure" required>
                <option value="">—</option>{$options}
              </select>
            </p>

            <p class="formulaire__champ">
              <label for="couverts">Personnes</label>
              <input id="couverts" name="couverts" type="number" required
                     min="1" max="{$max}" value="{$v['couverts']}">
            </p>
          </div>

          <p class="formulaire__champ">
            <label for="nom">Votre nom</label>
            <input id="nom" name="nom" type="text" required maxlength="100"
                   autocomplete="name" value="{$v['nom']}">
          </p>

          <div class="formulaire__ligne">
            <p class="formulaire__champ">
              <label for="telephone">Votre téléphone</label>
              <input id="telephone" name="telephone" type="tel" required maxlength="30"
                     autocomplete="tel" value="{$v['telephone']}">
            </p>

            <p class="formulaire__champ">
              <label for="email">Votre adresse électronique</label>
              <input id="email" name="email" type="email" required maxlength="190"
                     autocomplete="email" value="{$v['email']}">
            </p>
          </div>

          <p class="formulaire__champ">
            <label for="message">Précisions (facultatif)</label>
            <textarea id="message" name="message" rows="4" maxlength="2000">{$v['message']}</textarea>
          </p>

          <p class="formulaire__envoi">
            <button class="bouton" type="submit">Envoyer la demande</button>
          </p>

          <p class="formulaire__rgpd">
            Ces informations nous servent uniquement à traiter votre demande, et sont
            conservées un an. Voir notre
            <a href="confidentialite.html">politique de confidentialité</a>.
          </p>
        </form>
    HTML;
}

/**
 * Annonce les jours de fermeture, plutôt que de laisser découvrir le refus
 * après l'envoi du formulaire.
 *
 * @param array<string, mixed> $gabarit
 */
function bloc_fermetures(array $gabarit): string
{
    $fermes = [];

    foreach ($gabarit['horaires'] as $jour => $plages) {
        if ($plages === []) {
            $fermes[] = $jour;
        }
    }

    if ($fermes === []) {
        return '';
    }

    return '    <p class="note-fermeture">Nous sommes fermés le '
        . h(implode(' et le ', $fermes)) . '.</p>';
}
