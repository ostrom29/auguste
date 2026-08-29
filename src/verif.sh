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
# Sur la ligne du prix, pas n'importe où : la meta description cite elle aussi
# des prix, et un motif trop large ferait dépendre ce test de sa formulation.
compte 21 'plat__prix">.*&#160;€'                 "21 prix à espace insécable"
compte  1 'lang="fr"'                             "la page est en français"
compte  1 'name="viewport"'                       "meta viewport présent"

echo
echo "Pages annexes et métadonnées"

fichier() {
  if [ -f "public/$1" ]; then vert "$2"; else rouge "$2 — public/$1 absent"; fi
}

fichier index.html              "l accueil est généré"
fichier mentions-legales.html   "les mentions légales sont générées"
fichier confidentialite.html    "la politique de confidentialité est générée"
fichier 404.html                "la page 404 est générée"
fichier robots.txt              "robots.txt est généré"
fichier gabarit.php             "le gabarit du formulaire est généré"

dans() {
  local f=$1 motif=$2 libelle=$3
  if grep -q "$motif" "public/$f"; then vert "$libelle"; else rouge "$libelle"; fi
}

dans index.html 'name="description"'      "meta description sur l accueil"
dans index.html 'rel="canonical"'         "balise canonique"
dans index.html 'property="og:image"'     "image de partage déclarée"
dans index.html 'application/ld+json'     "bloc JSON-LD présent"
dans carte.html 'name="description"'      "meta description sur la carte"

# Un JSON-LD mal formé est pire qu'absent : Google le rejette en silence.
if python3 -c "
import json, re, sys
h = open('public/index.html', encoding='utf-8').read()
m = re.search(r'<script type=\"application/ld\+json\">(.*?)</script>', h, re.S)
sys.exit(0 if m and json.loads(m.group(1)).get('@type') == 'Restaurant' else 1)
" 2>/dev/null; then
  vert "le JSON-LD est un objet Restaurant valide"
else
  rouge "le JSON-LD est absent ou mal formé"
fi

echo
echo "Formulaire de contact"

# Le nom part dans le sujet du courriel : un saut de ligne y permettrait
# d'injecter un « Bcc: ». Le message, lui, doit garder ses retours à la ligne.
if php -r '
require "public/contact.php";
$nom = nettoyer("Pirate\nBcc: victime@example.com", true);
$msg = nettoyer("ligne un\nligne deux", false);
exit((strpos($nom, "\n") === false && strpos($msg, "\n") !== false) ? 0 : 1);
' 2>/dev/null; then
  vert "les sauts de ligne sont retirés du nom, gardés dans le message"
else
  rouge "nettoyage des champs : injection d en-tête possible"
fi

echo
if [ "$echecs" -eq 0 ]; then
  echo "Tout passe."
  exit 0
fi

echo "$echecs vérification(s) en échec."
exit 1
