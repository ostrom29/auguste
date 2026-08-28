#!/usr/bin/env bash
#
# Vérifie le générateur, sans réseau, sur les fixtures.
#
#     ./src/verif.sh
#
# Sortie 0 si tout passe, 1 sinon. À lancer après chaque modification du
# générateur, et avant tout déploiement.

set -u
cd "$(dirname "$0")/.." || exit 1

echecs=0
sortie=$(mktemp)
trap 'rm -f "$sortie" "$sortie.page"' EXIT

vert()  { printf '  \033[32mok\033[0m   %s\n' "$1"; }
rouge() { printf '  \033[31mKO\033[0m   %s\n' "$1"; echecs=$((echecs + 1)); }

# Lance un build et vérifie son code de sortie.
#   attend_code <code attendu> <source> <libellé>
attend_code() {
  local attendu=$1 source=$2 libelle=$3 obtenu

  php src/build.php --source="$source" >"$sortie" 2>&1
  obtenu=$?

  if [ "$obtenu" = "$attendu" ]; then
    vert "$libelle"
  else
    rouge "$libelle (code $obtenu, attendu $attendu)"
    sed 's/^/       /' "$sortie"
  fi
}

# Vérifie qu'un build en échec n'a pas touché à la page déjà en place.
#   intact <source> <libellé>
intact() {
  local source=$1 libelle=$2 avant apres

  avant=$(md5sum < public/carte.html)
  php src/build.php --source="$source" >/dev/null 2>&1
  apres=$(md5sum < public/carte.html)

  if [ "$avant" = "$apres" ]; then
    vert "$libelle"
  else
    rouge "$libelle — la page a été réécrite alors que la validation échouait"
  fi
}

# Compte les occurrences d'un motif dans la page générée.
#   compte <attendu> <motif> <libellé>
compte() {
  local attendu=$1 motif=$2 libelle=$3 obtenu

  obtenu=$(grep -c "$motif" public/carte.html)

  if [ "$obtenu" = "$attendu" ]; then
    vert "$libelle"
  else
    rouge "$libelle ($obtenu, attendu $attendu)"
  fi
}

echo "Sources valides"
attend_code 0 fixtures                      "le jeu nominal se génère"
attend_code 0 fixtures/colonnes-deplacees   "colonnes déplacées : mappage par nom"

echo
echo "Fixtures cassées : la validation doit refuser d'écrire"
attend_code 1 fixtures/casse-entete              "en-tête incomplet"
attend_code 1 fixtures/casse-donnees             "prix, ordre, nom et categorie invalides"
attend_code 1 fixtures/casse-aucune-ligne-active "aucune ligne active"
attend_code 1 fixtures/casse-infos               "en-tête de l'onglet infos"

echo
echo "La page en place survit à un échec"
php src/build.php --source=fixtures >/dev/null 2>&1
intact fixtures/casse-entete              "après en-tête incomplet"
intact fixtures/casse-donnees             "après données invalides"
intact fixtures/casse-aucune-ligne-active "après carte vide"
intact fixtures/casse-infos               "après en-tête infos"

echo
echo "Contenu de la page (jeu nominal)"
php src/build.php --source=fixtures >/dev/null 2>&1
compte 21 'class="plat"'                          "21 plats"
compte  0 'Soupe'                                 "la ligne actif=non est absente"
compte  0 'plat__description"></p>'               "aucune description vide"
compte  0 'plat__allergenes">Allergènes : </p>'   "aucun bloc allergènes vide"
compte 17 'class="plat__description"'             "17 descriptions"
compte 21 '&#160;€'                               "21 prix à espace insécable"
compte  1 'lang="fr"'                             "la page est en français"
compte  1 'name="viewport"'                       "meta viewport présent"

echo
if [ "$echecs" -eq 0 ]; then
  echo "Tout passe."
  exit 0
fi

echo "$echecs vérification(s) en échec."
exit 1
