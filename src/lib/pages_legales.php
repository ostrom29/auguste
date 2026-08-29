<?php

declare(strict_types=1);

/**
 * Mentions légales et politique de confidentialité.
 *
 * Les deux pages sont obligatoires : la LCEN impose les mentions légales à
 * tout site professionnel, et le RGPD impose d'informer les personnes dès que
 * le formulaire de contact collecte leur nom, leur email ou leur message.
 *
 * Le contenu vient du Sheet comme le reste. Ce que le générateur ne sait pas —
 * la raison sociale, le SIRET, le directeur de la publication — ne s'invente
 * pas : la page affiche alors une mention visible et le build proteste.
 */

require_once __DIR__ . '/rendu.php';

/** Ces clés doivent être renseignées dans l'onglet infos avant mise en ligne. */
const LEGAL_CLES = [
    'raison_sociale' => 'Raison sociale',
    'forme_juridique' => 'Forme juridique',
    'capital' => 'Capital social',
    'siret' => 'SIRET',
    'rcs' => 'RCS',
    'tva' => 'TVA intracommunautaire',
    'directeur_publication' => 'Directeur de la publication',
    'email' => 'Adresse électronique',
];

/** Marqueur affiché à la place d'une mention manquante. Il doit se voir. */
const A_COMPLETER = 'À COMPLÉTER';

/**
 * @param array<string, string> $infos
 */
function rendre_mentions_legales(array $infos, string $urlSite): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);
    $titre = 'Mentions légales — ' . $nom;

    $lignes = [
        '    <h2>Éditeur du site</h2>',
        '    <dl class="legal">',
    ];

    foreach (LEGAL_CLES as $cle => $libelle) {
        $lignes[] = '      <div class="legal__ligne">';
        $lignes[] = '        <dt>' . e($libelle) . '</dt>';
        $lignes[] = '        <dd>' . mention($infos, $cle) . '</dd>';
        $lignes[] = '      </div>';
    }

    $lignes[] = '      <div class="legal__ligne">';
    $lignes[] = '        <dt>Adresse</dt>';
    $lignes[] = '        <dd>' . mention($infos, 'adresse') . '</dd>';
    $lignes[] = '      </div>';
    $lignes[] = '      <div class="legal__ligne">';
    $lignes[] = '        <dt>Téléphone</dt>';
    $lignes[] = '        <dd>' . mention($infos, 'telephone') . '</dd>';
    $lignes[] = '      </div>';
    $lignes[] = '    </dl>';

    $lignes[] = '    <h2>Hébergement</h2>';
    $lignes[] = '    <p>Ce site est hébergé par ' . mention($infos, 'hebergeur') . '.</p>';

    $lignes[] = '    <h2>Propriété intellectuelle</h2>';
    $lignes[] = '    <p>Les textes, la charte graphique et les photographies de ce site sont '
        . 'protégés. Toute reproduction sans autorisation est interdite.</p>';

    $lignes[] = '    <h2>Données personnelles</h2>';
    $lignes[] = '    <p>Le traitement des données transmises par le formulaire de contact est '
        . 'décrit dans notre <a href="confidentialite.html">politique de confidentialité</a>.</p>';

    return page_simple(
        'legale',
        $titre,
        'Mentions légales',
        $infos,
        'mentions-legales.html',
        'Mentions légales de ' . $nom . '.',
        $urlSite,
        $lignes
    );
}

/**
 * @param array<string, string> $infos
 */
function rendre_confidentialite(array $infos, string $urlSite): string
{
    $nom = info($infos, 'nom', NOM_PAR_DEFAUT);
    $titre = 'Politique de confidentialité — ' . $nom;
    $courriel = mention($infos, 'email');

    $lignes = [
        '    <p class="chapo">Cette page décrit ce que nous faisons des informations que '
            . 'vous nous transmettez. Elle est courte, parce que nous en collectons très peu.</p>',

        '    <h2>Ce que nous collectons</h2>',
        '    <p>Uniquement ce que vous saisissez dans le formulaire de contact : votre nom, '
            . 'votre adresse électronique et votre message. Le site n’utilise aucun cookie, '
            . 'aucun traceur et aucun outil de mesure d’audience.</p>',

        '    <h2>Pourquoi</h2>',
        '    <p>Pour vous répondre, et pour rien d’autre. Ces informations ne sont ni vendues, '
            . 'ni transmises à un tiers, ni utilisées pour vous envoyer des messages que vous '
            . 'n’auriez pas demandés.</p>',

        '    <h2>Sur quelle base</h2>',
        '    <p>Votre demande elle-même : en nous écrivant, vous nous demandez de vous '
            . 'répondre. C’est ce que le règlement appelle une mesure précontractuelle ou '
            . 'l’intérêt légitime à traiter une demande reçue.</p>',

        '    <h2>Combien de temps</h2>',
        '    <p>Les messages reçus sont conservés un an, puis supprimés.</p>',

        '    <h2>Qui y a accès</h2>',
        '    <p>Le message est transmis par courrier électronique à ' . mention($infos, 'nom') . ' '
            . 'et n’est lu que par le restaurant. Il transite par notre hébergeur, '
            . mention($infos, 'hebergeur') . ', qui n’en fait aucun autre usage.</p>',

        '    <h2>Vos droits</h2>',
        '    <p>Vous pouvez demander à consulter, corriger ou supprimer les informations vous '
            . 'concernant, en écrivant à ' . $courriel . '. Vous pouvez également introduire '
            . 'une réclamation auprès de la <abbr title="Commission nationale de l’informatique '
            . 'et des libertés">CNIL</abbr>.</p>',
    ];

    return page_simple(
        'legale',
        $titre,
        'Politique de confidentialité',
        $infos,
        'confidentialite.html',
        'Ce que ' . $nom . ' fait des informations que vous transmettez. Aucun cookie, aucun traceur.',
        $urlSite,
        $lignes
    );
}

/**
 * Une page de texte, avec le même en-tête et le même pied que le reste.
 *
 * @param array<string, string> $infos
 * @param list<string> $corps
 */
function page_simple(
    string $classe,
    string $titre,
    string $titreVisible,
    array $infos,
    string $fichier,
    string $description,
    string $urlSite,
    array $corps
): string {
    return document(
        $classe,
        e($titre),
        metadonnees($infos, $fichier, $titre, $description, $urlSite),
        implode("\n", [
            rendre_entete($infos, 'interieure'),
            '  <main id="contenu" class="texte">',
            '    <h1 class="texte__titre">' . e($titreVisible) . '</h1>',
            implode("\n", $corps),
            '  </main>',
            rendre_pied($infos),
        ])
    );
}

/**
 * Une mention légale, ou un marqueur bien visible tant qu'elle manque.
 *
 * @param array<string, string> $infos
 */
function mention(array $infos, string $cle): string
{
    $valeur = info($infos, $cle);

    return $valeur === ''
        ? '<mark class="a-completer">' . A_COMPLETER . '</mark>'
        : e($valeur);
}

/**
 * Les mentions légales absentes, pour que le build les réclame.
 *
 * @param array<string, string> $infos
 * @return list<string>
 */
function avertissements_legaux(array $infos): array
{
    $manquantes = [];

    foreach (array_merge(array_keys(LEGAL_CLES), ['hebergeur']) as $cle) {
        if (info($infos, $cle) === '') {
            $manquantes[] = $cle;
        }
    }

    if ($manquantes === []) {
        return [];
    }

    return [sprintf(
        'mentions légales incomplètes — %s à renseigner dans l\'onglet infos. '
        . 'Les pages affichent « %s » en attendant, et ce n\'est pas conforme à la LCEN.',
        implode(', ', $manquantes),
        A_COMPLETER
    )];
}
