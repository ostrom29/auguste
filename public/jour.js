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

  var aujourdhui = jours[new Date().getDay()];

  // Le pied de page liste la semaine ; le haut de l'accueil n'affiche que le
  // jour courant, les autres étant masqués par la feuille de style tant que
  // le script n'a rien marqué. Sans JavaScript, la semaine entière reste
  // lisible : c'est une mise en valeur, pas une béquille.
  var cibles = document.querySelectorAll('[data-jour="' + aujourdhui + '"]');

  for (var i = 0; i < cibles.length; i++) {
    cibles[i].setAttribute('data-aujourdhui', '');
  }

  document.documentElement.setAttribute('data-jour-connu', '');
})();
