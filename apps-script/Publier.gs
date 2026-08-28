/**
 * Chez Auguste — bouton « Publier » dans le Google Sheet.
 *
 * Ajoute un menu « Site » à côté de Fichier et Édition. Le bouton appelle
 * publier.php sur l'hébergement, qui retélécharge ce tableur et régénère la
 * carte, puis affiche sa réponse telle quelle.
 *
 * Ce script ne génère rien lui-même : il ne fait qu'appuyer sur l'interrupteur.
 *
 * Installation : voir apps-script/README.md
 */

/** Le lien de publication est rangé dans les propriétés du script, pas ici. */
var PROPRIETE_URL = 'URL_PUBLICATION';

/**
 * Déclenché à l'ouverture du tableur. Construit le menu.
 */
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Site')
    .addItem('Publier la carte', 'publierLaCarte')
    .addSeparator()
    .addItem('Régler le lien de publication…', 'reglerLien')
    .addToUi();
}

/**
 * Appelle l'hébergement et montre le résultat.
 */
function publierLaCarte() {
  var ui = SpreadsheetApp.getUi();
  var url = PropertiesService.getScriptProperties().getProperty(PROPRIETE_URL);

  if (!url) {
    ui.alert(
      'Lien de publication manquant',
      'Ouvrez « Site > Régler le lien de publication… » et collez le lien '
        + 'fourni par la personne qui s’occupe du site.',
      ui.ButtonSet.OK
    );
    return;
  }

  var reponse;

  try {
    reponse = UrlFetchApp.fetch(avecFormatTexte(url), {
      muteHttpExceptions: true,
      followRedirects: true
    });
  } catch (erreur) {
    // Panne réseau, DNS, certificat : on n'a même pas eu de réponse HTTP.
    ui.alert(
      'Site injoignable',
      'La carte n’a pas été modifiée.\n\n' + erreur.message,
      ui.ButtonSet.OK
    );
    return;
  }

  var code = reponse.getResponseCode();
  var texte = reponse.getContentText();

  // publier.php renvoie déjà un texte lisible, en français, dans tous les cas.
  ui.alert(
    code === 200 ? 'Carte publiée' : 'Publication impossible',
    texte,
    ui.ButtonSet.OK
  );
}

/**
 * Demande le lien une fois pour toutes et le range dans les propriétés du
 * script — il n'apparaît donc pas dans le code, que voient tous les éditeurs
 * du tableur.
 */
function reglerLien() {
  var ui = SpreadsheetApp.getUi();
  var actuel = PropertiesService.getScriptProperties().getProperty(PROPRIETE_URL);

  var reponse = ui.prompt(
    'Lien de publication',
    'Collez le lien complet, avec sa clé.\n\n'
      + (actuel ? 'Lien actuel : ' + actuel : 'Aucun lien enregistré pour l’instant.'),
    ui.ButtonSet.OK_CANCEL
  );

  if (reponse.getSelectedButton() !== ui.Button.OK) {
    return;
  }

  var url = reponse.getResponseText().trim();

  if (url.indexOf('https://') !== 0) {
    ui.alert(
      'Lien refusé',
      'Le lien doit commencer par https:// — sinon la clé de publication '
        + 'circulerait en clair sur le réseau.',
      ui.ButtonSet.OK
    );
    return;
  }

  PropertiesService.getScriptProperties().setProperty(PROPRIETE_URL, url);
  ui.alert('Lien enregistré', 'Vous pouvez maintenant utiliser « Site > Publier la carte ».', ui.ButtonSet.OK);
}

/**
 * Ajoute format=texte au lien, que celui-ci porte déjà des paramètres ou non.
 */
function avecFormatTexte(url) {
  return url + (url.indexOf('?') === -1 ? '?' : '&') + 'format=texte';
}
