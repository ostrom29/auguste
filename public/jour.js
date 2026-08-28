/*
 * Met en évidence le jour courant dans les horaires.
 *
 * C'est un confort, pas une béquille : sans JavaScript, la semaine entière
 * reste lisible, seule la mise en valeur disparaît. Le site ne calcule jamais
 * « ouvert en ce moment » — les pages sont pré-générées, un tel statut serait
 * faux la plupart du temps.
 */
(function () {
  'use strict';

  var jours = [
    'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'
  ];

  var cible = document.querySelector(
    '.horaires__jour[data-jour="' + jours[new Date().getDay()] + '"]'
  );

  if (cible) {
    cible.setAttribute('data-aujourdhui', '');
  }
})();
