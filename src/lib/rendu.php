<?php

declare(strict_types=1);

/**
 * Rendu HTML des deux pages du site.
 *
 * Rien n'est concaténé sans passer par `e()` : tout ce qui vient du Sheet est
 * échappé au moment de l'écriture.
 */

require_once __DIR__ . '/texte.php';
require_once __DIR__ . '/metadonnees.php';

/** Jours de la semaine, dans l'ordre d'affichage. */
const JOURS_SEMAINE = [
    'lundi' => 'Lundi',
    'mardi' => 'Mardi',
    'mercredi' => 'Mercredi',
    'jeudi' => 'Jeudi',
    'vendredi' => 'Vendredi',
    'samedi' => 'Samedi',
    'dimanche' => 'Dimanche',
];

const NOM_PAR_DEFAUT = 'Chez Auguste';
const ACCROCHE_PAR_DEFAUT = 'Bouillon-brasserie';

/**
 * Empreintes des ressources statiques, pour casser le cache des navigateurs.
 *
 * style.css et jour.js gardent le même nom d'une version à l'autre. Sans
 * empreinte dans l'URL, un visiteur qui a déjà vu le site reçoit du HTML neuf
 * avec l'ancienne feuille de style — ce qui donne une page à moitié cassée,
 * pendant toute la durée du cache.
 *
 * Le registre est posé une fois par le générateur, avant tout rendu.
 *
 * @param array<string, string>|null $nouvelles
 * @return array<string, string>
 */
function empreintes(?array $nouvelles = null): array
{
    static $registre = ['style.css' => '', 'jour.js' => ''];

    if ($nouvelles !== null) {
        $registre = $nouvelles;
    }

    return $registre;
}

/**
 * L'URL d'une ressource, suffixée de son empreinte si on la connaît.
 */
function ressource(string $fichier, string $prefixe = ''): string
{
    $empreinte = empreintes()[$fichier] ?? '';

    return $prefixe . $fichier . ($empreinte === '' ? '' : '?v=' . $empreinte);
}

/**
 * Une photo de la salle est-elle disponible ?
 *
 * C'est la présence du fichier qui décide, pas une case du tableur : déposer
 * l'image et lancer le build suffit à la faire apparaître, et la retirer la
 * fait disparaître. Une clé « photo » à « non » permet de la masquer sans la
 * supprimer.
 *
 * Posé une fois par le générateur, comme les empreintes.
 */
function photo_disponible(?bool $valeur = null): bool
{
    static $disponible = false;

    if ($valeur !== null) {
        $disponible = $valeur;
    }

    return $disponible;
}

/**
 * Calcule les empreintes des ressources présentes dans le dossier de sortie.
 *
 * @return array<string, string>
 */
function calculer_empreintes(string $dossier): array
{
    $registre = [];

    foreach (['style.css', 'jour.js'] as $fichier) {
        $chemin = $dossier . '/' . $fichier;
        // Huit caractères suffisent : on distingue des versions successives,
        // on ne se défend contre personne.
        $registre[$fichier] = is_file($chemin) ? substr(md5_file($chemin) ?: '', 0, 8) : '';
    }

    // Les images aussi, et pour une raison plus sévère encore que la feuille
    // de style : le .htaccess les met en cache six mois. Sans empreinte, une
    // photo recadrée ne serait vue par un visiteur déjà venu qu'à l'expiration
    // du cache — soit dans six mois.
    foreach (glob($dossier . '/img/*') ?: [] as $chemin) {
        if (is_file($chemin)) {
            $registre['img/' . basename($chemin)] = substr(md5_file($chemin) ?: '', 0, 8);
        }
    }

    return $registre;
}

/**
 * Lit une info du Sheet, en repliant sur un défaut.
 *
 * Une clé présente mais vide vaut une clé absente : dans un tableur, effacer
 * une cellule laisse la ligne en place. C'est le cas le plus fréquent, et
 * l'opérateur `??` seul ne l'attrape pas — il ne teste que l'absence.
 *
 * @param array<string, string> $infos
 */
function info(array $infos, string $cle, string $defaut = ''): string
{
    $valeur = trim($infos[$cle] ?? '');

    return $valeur !== '' ? $valeur : $defaut;
}

/**
 * La photo d'ambiance, dans ses trois largeurs préparées par outils/images.py.
 *
 * Recadrée en 21:9 quelle que soit la source. La salle est tout en longueur,
 * un cadre allongé l'épouse — et une image presque carrée en pleine largeur
 * ferait un bloc qui repousse tout le contenu sous la ligne de flottaison.
 *
 * Doit rester d'accord avec SALLE_RATIO dans outils/images.py.
 */
const SALLE_LARGEURS = [420, 720, 1040];
const SALLE_RATIO = 2.333;

// ---------------------------------------------------------------------------
// Les deux pages
// ---------------------------------------------------------------------------

/**
 * Page d'accueil : ce qu'on cherche en arrivant, dans l'ordre où on le cherche.
 *
 * @param list<array<string, mixed>> $vedettes
 * @param array<string, string> $infos
 */
function rendre_accueil(array $vedettes, array $infos, array $categories, string $urlSite): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);
    $titre = $nom . ' — ' . info($infos, 'accroche', ACCROCHE_PAR_DEFAUT);
    $description = description_site($infos);

    return document(
        'accueil',
        e($titre),
        array_merge(
            metadonnees($infos, 'index.html', $titre, $description, $urlSite),
            [json_ld($infos, $categories, $urlSite)]
        ),
        implode("\n", array_filter([
            rendre_entete($infos, 'accueil', 'index.html'),
            // La photo si elle existe, le bandeau de prix sinon.
            rendre_banniere($infos) ?: rendre_bandeau($infos, $categories),
            // Collée sous l'image, à sa largeur : elle la légende au lieu de
            // flotter dans le vide, et donne le renseignement qu'on cherche
            // en premier.
            rendre_aujourdhui($infos),
            '  <main id="contenu">',
            rendre_message($infos),
            rendre_coordonnees($infos),
            // L'ornement sépare deux sections. Isolé dans du vide, il ne
            // séparait rien.
            rendre_ornement(),
            rendre_vedettes($vedettes),
            '  </main>',
            rendre_pied($infos),
        ], static fn (string $bloc): bool => $bloc !== ''))
    );
}

/**
 * La carte complète.
 *
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 * @param array<string, string> $infos
 */
function rendre_carte(array $categories, array $infos, string $urlSite): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);
    $titre = 'Carte — ' . $nom;
    $description = sprintf(
        'La carte de %s : %d plats, de %s à %s.',
        $nom,
        nombre_de_plats($categories),
        prix_extreme($categories, 'min'),
        prix_extreme($categories, 'max')
    );
    $sections = [];

    foreach ($categories as $categorie) {
        $sections[] = '    <section class="categorie">';
        $sections[] = '      <h2 class="categorie__titre">' . e($categorie['nom']) . '</h2>';
        $sections[] = '      <ul class="categorie__plats">';

        foreach ($categorie['plats'] as $plat) {
            array_push($sections, ...rendre_plat($plat, 3, '        '));
        }

        $sections[] = '      </ul>';
        $sections[] = '    </section>';
    }

    return document(
        'carte',
        e($titre),
        metadonnees($infos, 'carte.html', $titre, $description, $urlSite),
        implode("\n", array_filter([
            rendre_entete($infos, 'carte', 'carte.html'),
            '  <main id="contenu" class="carte">',
            rendre_message($infos),
            implode("\n", $sections),
            '    <p class="note-allergenes">Une information sur les allergènes est disponible en salle. '
                . 'N’hésitez pas à nous interroger.</p>',
            '  </main>',
            rendre_pied($infos),
        ], static fn (string $bloc): bool => $bloc !== ''))
    );
}

// ---------------------------------------------------------------------------
// Blocs partagés
// ---------------------------------------------------------------------------

/**
 * @param list<string> $tete Balises supplémentaires du <head>, déjà échappées.
 */
function document(string $classe, string $titre, array $tete, string $corps): string
{
    $html = implode("\n", [
        '<!DOCTYPE html>',
        '<html lang="fr">',
        '<head>',
        '  <meta charset="utf-8">',
        '  <meta name="viewport" content="width=device-width, initial-scale=1">',
        '  <title>' . $titre . '</title>',
        implode("\n", $tete),
        '  <link rel="stylesheet" href="' . e(ressource('style.css')) . '">',
        '  <link rel="icon" href="' . e(ressource('img/favicon.png')) . '" type="image/png">',
        '</head>',
        '<body class="page page--' . $classe . '">',
        '  <!-- Page générée par src/build.php : toute modification à la main sera écrasée. -->',
        '  <a class="evitement" href="#contenu">Aller au contenu</a>',
        $corps,
        '</body>',
        '</html>',
        '',
    ]);

    // L'espace insécable est écrit en entité pour rester visible à la relecture.
    return str_replace(ESPACE_INSECABLE, '&#160;', $html);
}

/**
 * La prise de réservation est-elle ouverte ?
 *
 * Elle est éteinte par défaut : un formulaire de réservation que personne ne
 * dépouille est pire que pas de formulaire du tout. Pour l'allumer, une ligne
 * « reservation » à « oui » dans l'onglet infos — le code reste en place et
 * complet en attendant.
 *
 * @param array<string, string> $infos
 */
function reservation_active(array $infos): bool
{
    return mb_strtolower(info($infos, 'reservation'), 'UTF-8') === 'oui';
}

/**
 * Le menu principal : des libellés courts, qui se replient si besoin.
 *
 * @param array<string, string> $infos
 * @return array<string, string>
 */
function menu(array $infos): array
{
    $entrees = [
        'index.html' => 'Accueil',
        'carte.html' => 'La carte',
    ];

    if (reservation_active($infos)) {
        $entrees['reservation.php'] = 'Réserver';
    }

    $entrees['contact.php'] = 'Nous écrire';

    return $entrees;
}

/**
 * La navigation principale.
 *
 * Trois entrées seulement : elles tiennent sur une ligne, même sur un petit
 * téléphone, et se replient d'elles-mêmes si la place manque. Pas de bouton
 * hamburger, donc pas de JavaScript, donc rien qui puisse ne pas s'ouvrir.
 */
function rendre_menu(array $infos, string $courant): string
{
    $liens = [];

    foreach (menu($infos) as $fichier => $libelle) {
        $actuel = $fichier === $courant;

        // aria-current dit aux lecteurs d'écran où l'on se trouve ; la classe
        // le dit aux voyants. Les deux, pas l'un ou l'autre.
        $liens[] = sprintf(
            '      <a class="menu__lien%s" href="%s"%s>%s</a>',
            $actuel ? ' menu__lien--actuel' : '',
            e($fichier),
            $actuel ? ' aria-current="page"' : '',
            e($libelle)
        );
    }

    return implode("\n", array_merge(
        ['    <nav class="menu" aria-label="Navigation principale">'],
        $liens,
        ['    </nav>']
    ));
}

/**
 * @param array<string, string> $infos
 */
function rendre_entete(array $infos, string $page, string $courant = ''): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);

    // Sans repli, volontairement : le logo dit déjà « BOUILLON - BRASSERIE ».
    // L'accroche par défaut sert au titre de l'onglet et aux moteurs, qui ne
    // savent pas lire une image — l'afficher ici la répéterait pour rien.
    // Une accroche saisie dans le Sheet, elle, apparaît bien.
    $accroche = info($infos, 'accroche');

    // Sur l'accueil le logo est le titre de la page ; sur la carte, il n'est
    // qu'un lien de retour, et le titre revient à la carte elle-même.
    $logo = '<img class="entete__logo" src="' . e(ressource('img/logo.png')) . '" alt="' . e($nom) . '"'
        . ' width="440" height="322" srcset="' . e(ressource('img/logo.png')) . ' 440w, '
        . e(ressource('img/logo-2x.png')) . ' 880w"'
        . ' sizes="(min-width: 40rem) 20rem, 60vw">';

    if ($page === 'accueil') {
        return implode("\n", array_filter([
            '  <header class="entete">',
            '    <h1 class="entete__titre">' . $logo . '</h1>',
            $accroche === '' ? '' : '    <p class="entete__accroche">' . e($accroche) . '</p>',
            rendre_menu($infos, $courant),
            '  </header>',
        ], static fn (string $ligne): bool => $ligne !== ''));
    }

    $lignes = [
        '  <header class="entete entete--compacte">',
        '    <a class="entete__retour" href="index.html">' . $logo . '</a>',
        rendre_menu($infos, $courant),
    ];

    // Sur la carte, le titre visible ferait doublon avec l'entrée de menu
    // déjà soulignée en rouge juste au-dessus. Il reste dans le document,
    // pour la structure et les lecteurs d'écran, mais hors de l'écran.
    if ($page === 'carte') {
        $lignes[] = '    <h1 class="hors-ecran">La carte</h1>';
    }

    $lignes[] = '  </header>';

    return implode("\n", $lignes);
}

/**
 * @param array<string, string> $infos
 */
/**
 * Le bandeau qui tient la place de la photo.
 *
 * Une photo de la salle n'existe pas encore, et une photo de stock montrerait
 * la brasserie de quelqu'un d'autre. À la place, ce que le restaurant a de
 * plus convaincant et qui est déjà dans le Sheet : ses prix. Pour un bouillon,
 * « à partir de 1,80 € » vaut mieux qu'un intérieur générique.
 *
 * @param array<string, string> $infos
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 */
function rendre_bandeau(array $infos, array $categories): string
{
    if ($categories === []) {
        return '';
    }

    $lignes = [
        '  <aside class="bandeau">',
        '    <p class="bandeau__prix">' . sprintf(
            '%d plats, de %s à %s',
            nombre_de_plats($categories),
            e(prix_extreme($categories, 'min')),
            e(prix_extreme($categories, 'max'))
        ) . '</p>',
    ];

    // « Service continu » n'est pas une formule : c'est ce que disent les
    // horaires quand la journée tient en une seule plage.
    $continu = service_continu($infos);

    if ($continu !== '') {
        $lignes[] = '    <p class="bandeau__service">Service continu, ' . e($continu) . '</p>';
    }

    $lignes[] = '  </aside>';

    return implode("\n", $lignes);
}

/**
 * « de 11h00 à 21h00 » si la maison sert sans interruption, sinon rien.
 *
 * @param array<string, string> $infos
 */
function service_continu(array $infos): string
{
    $plages = [];

    foreach (array_keys(JOURS_SEMAINE) as $jour) {
        $creneaux = decouper_creneaux(info($infos, 'horaires_' . $jour));

        // Deux créneaux dans la journée : il y a une coupure entre les
        // services, la mention serait fausse.
        if (count($creneaux) > 1) {
            return '';
        }

        if ($creneaux !== []) {
            $plages[] = $creneaux[0][0] . '-' . $creneaux[0][1];
        }
    }

    // Une seule et même amplitude tous les jours ouverts, sinon on se tait
    // plutôt que d'annoncer un horaire qui ne vaut pas pour tous.
    if ($plages === [] || count(array_unique($plages)) !== 1) {
        return '';
    }

    [$debut, $fin] = explode('-', $plages[0]);

    return sprintf('de %s à %s', str_replace(':', 'h', $debut), str_replace(':', 'h', $fin));
}

/**
 * @param array<string, string> $infos
 */
function rendre_banniere(array $infos): string
{
    $legende = info($infos, 'legende_photo');

    // Pas d'image préparée : le bandeau de prix tient la place.
    if (!photo_disponible()) {
        return '';
    }

    // Échappatoire : « photo » à « non » masque une image pourtant présente.
    if (mb_strtolower(info($infos, 'photo', 'oui'), 'UTF-8') === 'non') {
        return '';
    }

    $webp = [];
    $jpeg = [];

    foreach (SALLE_LARGEURS as $largeur) {
        $webp[] = e(ressource(sprintf('img/salle-%d.webp', $largeur))) . ' ' . $largeur . 'w';
        $jpeg[] = e(ressource(sprintf('img/salle-%d.jpg', $largeur))) . ' ' . $largeur . 'w';
    }

    $tailles = '(min-width: 60rem) 58rem, 100vw';
    $pleine = max(SALLE_LARGEURS);
    $hauteur = (int) round($pleine / SALLE_RATIO);

    $lignes = [
        '  <figure class="banniere">',
        '    <picture>',
        '      <source type="image/webp" srcset="' . implode(', ', $webp) . '" sizes="' . $tailles . '">',
        // width et height évitent que la page saute quand l'image arrive.
        '      <img src="' . e(ressource('img/salle-' . $pleine . '.jpg'))
            . '" srcset="' . implode(', ', $jpeg) . '"'
            . ' sizes="' . $tailles . '" width="' . $pleine . '" height="' . $hauteur . '"'
            . ' alt="' . e($legende !== '' ? $legende : 'La salle du restaurant') . '" decoding="async">',
        '    </picture>',
    ];

    if ($legende !== '') {
        $lignes[] = '    <figcaption class="banniere__legende">' . e($legende) . '</figcaption>';
    }

    $lignes[] = '  </figure>';

    return implode("\n", $lignes);
}

/**
 * Une URL du Sheet, si elle est utilisable comme lien.
 *
 * On refuse tout ce qui n'est pas http(s) : une cellule de tableur finit dans
 * un attribut href, et « javascript: » y serait un script exécuté chez le
 * visiteur. Le Sheet est de confiance, mais il a plusieurs éditeurs.
 *
 * @param array<string, string> $infos
 */
function lien_externe(array $infos, string $cle): string
{
    $url = info($infos, $cle);

    if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
        return '';
    }

    return $url;
}

/**
 * La fioriture du logo, réutilisée seule comme séparation.
 *
 * Décorative et rien d'autre : elle est retirée aux lecteurs d'écran, qui
 * n'ont que faire d'une volute.
 */
function rendre_ornement(): string
{
    return '    <img class="ornement" src="' . e(ressource('img/ornement.png')) . '"'
        . ' srcset="' . e(ressource('img/ornement.png')) . ' 420w, '
        . e(ressource('img/ornement-2x.png')) . ' 840w"'
        . ' width="420" height="52" alt="" aria-hidden="true" decoding="async">';
}

/**
 * Les horaires du jour, en haut de page.
 *
 * Le site étant pré-généré, il ne dit jamais « ouvert en ce moment » — ce
 * serait faux la plupart du temps. Il donne l'horaire du jour, et jour.js
 * met le bon en évidence.
 *
 * @param array<string, string> $infos
 */
function rendre_aujourdhui(array $infos): string
{
    $lignes = [];

    foreach (JOURS_SEMAINE as $cle => $libelle) {
        $horaire = info($infos, 'horaires_' . $cle);
        $ferme = $horaire === '' || mb_strtolower($horaire, 'UTF-8') === 'fermé';

        // Le nom du jour ne sert à rien quand on a déjà dit « aujourd'hui » :
        // il n'apparaît que dans le pied de page, où toute la semaine est là.
        $lignes[] = sprintf(
            '      <span class="aujourdhui__jour" data-jour="%s">'
            // L'espace entre les deux balises n'est pas décorative : sans
            // elle, un lecteur d'écran annonce « Aujourd’hui11h00 ».
            . '<span class="aujourdhui__etiquette">Aujourd’hui</span> '
            . '<b class="aujourdhui__horaire%s">%s</b></span>',
            e($cle),
            $ferme ? ' aujourdhui__horaire--ferme' : '',
            e($ferme ? 'Fermé' : $horaire)
        );
    }

    return implode("\n", array_merge(
        ['  <p class="aujourdhui">'],
        $lignes,
        ['  </p>']
    ));
}

/**
 * Le message du Sheet — fermeture annuelle, jour férié. Rien s'il est vide.
 *
 * @param array<string, string> $infos
 */
function rendre_message(array $infos): string
{
    $message = info($infos, 'message');

    if ($message === '') {
        return '';
    }

    return '    <p class="message" role="status">' . e($message) . '</p>';
}

/**
 * @param array<string, string> $infos
 */
function rendre_coordonnees(array $infos): string
{
    $adresse = info($infos, 'adresse');
    $acces = info($infos, 'acces');
    $telephone = info($infos, 'telephone');
    $lignes = [];

    if ($adresse !== '') {
        $lignes[] = '        <p class="coordonnees__adresse">' . e($adresse) . '</p>';
    }

    if ($acces !== '') {
        $lignes[] = '        <p class="coordonnees__acces">' . e($acces) . '</p>';
    }

    if ($lignes === [] && $telephone === '') {
        return '';
    }

    $bloc = [
        '    <section class="coordonnees">',
        '      <h2 class="coordonnees__titre">Nous trouver</h2>',
        '      <address class="coordonnees__adresse-bloc">',
    ];

    array_push($bloc, ...$lignes);
    $bloc[] = '      </address>';

    $actions = [];

    if ($telephone !== '') {
        $actions[] = '<a class="bouton" href="tel:' . e(lien_telephone($telephone)) . '">'
            . 'Appeler le ' . e($telephone) . '</a>';
    }

    // L'itinéraire compte autant que l'appel : le restaurant est dans un
    // centre commercial, où une adresse seule ne suffit pas à trouver la porte.
    $maps = lien_externe($infos, 'maps');

    if ($maps !== '') {
        $actions[] = '<a class="bouton bouton--discret" href="' . e($maps) . '"'
            . ' target="_blank" rel="noopener">Itinéraire</a>';
    }

    if (reservation_active($infos)) {
        $actions[] = '<a class="bouton bouton--discret" href="reservation.php">Réserver une table</a>';
    }

    // Instagram avec les autres actions : c'est là que vivent les photos d'un
    // restaurant, et c'est une destination au même titre qu'un itinéraire.
    $instagram = lien_externe($infos, 'instagram');

    if ($instagram !== '') {
        $actions[] = '<a class="bouton bouton--discret" href="' . e($instagram) . '"'
            . ' target="_blank" rel="noopener me">Instagram</a>';
    }

    if ($actions !== []) {
        $bloc[] = '      <p class="coordonnees__appel">' . implode("\n        ", $actions) . '</p>';
    }


    $bloc[] = '    </section>';

    return implode("\n", $bloc);
}

/**
 * @param list<array<string, mixed>> $vedettes
 */
function rendre_vedettes(array $vedettes): string
{
    if ($vedettes === []) {
        return '';
    }

    $lignes = [
        '    <section class="vedettes">',
        '      <h2 class="vedettes__titre">Quelques incontournables</h2>',
        '      <ul class="vedettes__plats">',
    ];

    foreach ($vedettes as $plat) {
        array_push($lignes, ...rendre_plat($plat, 3, '        '));
    }

    $lignes[] = '      </ul>';
    $lignes[] = '      <p class="vedettes__lien"><a class="bouton bouton--discret" href="carte.html">'
        . 'Voir toute la carte</a></p>';
    $lignes[] = '    </section>';

    return implode("\n", $lignes);
}

/**
 * Un plat. Le niveau de titre change selon la page, la structure non.
 *
 * @param array<string, mixed> $plat
 * @return list<string>
 */
function rendre_plat(array $plat, int $niveau, string $marge): array
{
    $h = 'h' . $niveau;

    $lignes = [
        $marge . '<li class="plat">',
        $marge . '  <div class="plat__entete">',
        $marge . '    <' . $h . ' class="plat__nom">' . e((string) $plat['nom']) . '</' . $h . '>',
        $marge . '    <p class="plat__prix">' . e((string) $plat['prix']) . '</p>',
        $marge . '  </div>',
    ];

    // Une description vide ne produit aucun élément.
    if ((string) $plat['description'] !== '') {
        $lignes[] = $marge . '  <p class="plat__description">' . e((string) $plat['description']) . '</p>';
    }

    if ($plat['allergenes'] !== []) {
        $lignes[] = $marge . '  <p class="plat__allergenes">Allergènes : '
            . e(implode(', ', $plat['allergenes'])) . '</p>';
    }

    $lignes[] = $marge . '</li>';

    return $lignes;
}

/**
 * @param array<string, string> $infos
 */
function rendre_pied(array $infos): string
{
    $jours = [];

    foreach (JOURS_SEMAINE as $cle => $libelle) {
        $horaire = info($infos, 'horaires_' . $cle);

        if ($horaire === '') {
            continue;
        }

        // data-jour sert au script qui met le jour courant en évidence. Sans
        // JavaScript, la semaine reste entièrement lisible.
        $jours[] = '        <div class="horaires__jour" data-jour="' . e($cle) . '">';
        $jours[] = '          <dt class="horaires__nom">' . e($libelle) . '</dt>';
        $jours[] = '          <dd class="horaires__valeur">' . e($horaire) . '</dd>';
        $jours[] = '        </div>';
    }

    $lignes = ['  <footer class="pied">'];

    if ($jours !== []) {
        $lignes[] = '    <section class="horaires">';
        $lignes[] = '      <h2 class="horaires__titre">Horaires</h2>';
        $lignes[] = '      <dl class="horaires__liste">';
        array_push($lignes, ...$jours);
        $lignes[] = '      </dl>';
        $lignes[] = '    </section>';
    }

    $telephone = info($infos, 'telephone');

    if ($telephone !== '') {
        $lignes[] = '    <p class="pied__telephone"><a href="tel:'
            . e(lien_telephone($telephone)) . '">' . e($telephone) . '</a></p>';
    }

    // Les réseaux ne sont plus repris ici : ils figurent auprès des
    // coordonnées, où ils se voient. Les répéter en bas ferait doublon.
    //
    // Le menu principal couvre déjà les pages du site : le pied ne reprend
    // que ce qui n'a pas sa place en haut.
    $lignes[] = '    <nav class="pied__liens" aria-label="Informations légales">';
    $lignes[] = '      <a href="mentions-legales.html">Mentions légales</a>';
    $lignes[] = '      <a href="confidentialite.html">Confidentialité</a>';
    $lignes[] = '    </nav>';
    $lignes[] = '    <script src="' . e(ressource('jour.js')) . '" defer></script>';
    $lignes[] = '  </footer>';

    return implode("\n", $lignes);
}

/**
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 */
function nombre_de_plats(array $categories): int
{
    return array_sum(array_map(
        static fn (array $categorie): int => count($categorie['plats']),
        $categories
    ));
}

/**
 * Le prix le plus bas ou le plus haut de la carte, déjà mis en forme.
 *
 * @param list<array{nom: string, plats: list<array<string, mixed>>}> $categories
 */
function prix_extreme(array $categories, string $sens): string
{
    $retenu = null;

    foreach ($categories as $categorie) {
        foreach ($categorie['plats'] as $plat) {
            $valeur = prix_numerique((string) $plat['prix']);

            $meilleur = $retenu === null
                || ($sens === 'min' ? $valeur < $retenu[0] : $valeur > $retenu[0]);

            if ($meilleur) {
                $retenu = [$valeur, (string) $plat['prix']];
            }
        }
    }

    return $retenu === null ? '' : $retenu[1];
}

/**
 * « 01 23 45 67 89 » → « +33123456789 », utilisable dans un href tel:.
 */
function lien_telephone(string $telephone): string
{
    $chiffres = preg_replace('/[^0-9+]/', '', $telephone) ?? '';

    if (strlen($chiffres) === 10 && str_starts_with($chiffres, '0')) {
        return '+33' . substr($chiffres, 1);
    }

    return $chiffres;
}
