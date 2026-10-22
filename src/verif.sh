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
attend_code 0 fixtures/colonnes-minimales   "sans allergenes ni ordre, avec cases à cocher"

# Sans colonne « ordre », les plats suivent les lignes du Sheet.
if python3 -c "
import sys
h = open('public/carte.html', encoding='utf-8').read()
places = [h.find(n) for n in ('Œufs mimosa', 'Poireaux vinaigrette', 'Harengs, pommes de terre')]
sys.exit(0 if -1 not in places and places == sorted(places) and 'plat__allergenes' not in h else 1)
"; then
  vert "sans colonne ordre, les plats suivent les lignes du Sheet"
else
  rouge "sans colonne ordre, les plats ne suivent pas les lignes du Sheet"
fi

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
dans carte.html 'carte__arrivages'        "la carte prévient qu'elle suit les arrivages"
dans carte.html 'categorie__note'         "les boissons se disent une sélection"

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

# Le JSON-LD doit dire la même chose que le site, dans un sens comme dans
# l'autre : annoncer une réservation qu'on ne prend pas enverrait des clients
# sur une porte fermée, la taire quand on la prend perdrait des couverts. Et
# quand on la prend, le menu et Google doivent mener au même endroit.
reservation_coherente() {
  if python3 -c "
import html, json, re, sys
h = open('public/index.html', encoding='utf-8').read()
d = json.loads(re.search(r'<script type=\"application/ld\+json\">(.*?)</script>', h, re.S).group(1))
liens = re.findall(r'<a class=\"menu__lien[^\"]*\" href=\"([^\"]*)\"[^>]*>Réserver</a>', h)
menu = liens != []
declare = d.get('acceptsReservations') == 'True'
action = d.get('potentialAction', {})
cible = action.get('target', {}).get('urlTemplate', '')
meme = not menu or cible.endswith(html.unescape(liens[0]))
sys.exit(0 if menu == declare and (action.get('@type') == 'ReserveAction') == declare and meme else 1)
" 2>/dev/null; then
    vert "$1"
  else
    rouge "$1"
  fi
}

reservation_coherente "le JSON-LD dit la même chose que le site sur la réservation"

# Le .htaccess met les images en cache six mois. Une seule URL sans empreinte
# et le visiteur déjà venu garde l'ancienne image jusqu'à l'expiration.
sans_empreinte=$(grep -ohE '(src|href)="[^"]*img/[^"]*"' public/*.html \
  | grep -v '?v=' | sort -u)

if [ -z "$sans_empreinte" ]; then
  vert "toutes les images portent une empreinte de cache"
else
  rouge "des images sans empreinte, invisibles six mois après un changement"
  echo "$sans_empreinte" | sed 's/^/       /'
fi

echo
echo "Contrastes"
# Variable distincte : $sortie est le fichier temporaire du script.
if rapport_contraste=$(python3 outils/contraste.py); then
  echo "$rapport_contraste" | grep -E '^  (ok|KO)' | sed 's/^/  /'
else
  echo "$rapport_contraste" | sed 's/^/  /'
  rouge "des contrastes sont sous le seuil WCAG"
fi

echo
echo "Formulaires"

# Les heures proposées doivent venir des horaires, jamais d'une liste figée :
# le restaurateur change ses horaires dans le Sheet, pas dans le code.
if php -r '
$g = require "public/gabarit.php";
$heures = $g["heures"];
$ok = $heures !== [];
// Une heure proposée doit tomber dans au moins une plage, celle de n importe
// quel jour : avec deux services, midi et soir ont chacun les leurs.
foreach ($heures as $h) {
    $trouvee = false;
    foreach ($g["horaires"] as $plages) {
        foreach ($plages as [$debut, $fin]) {
            if ($h >= $debut && $h <= $fin) { $trouvee = true; }
        }
    }
    if (!$trouvee) { $ok = false; }
}
exit($ok ? 0 : 1);
' 2>/dev/null; then
  vert "les heures proposées tiennent dans les horaires du Sheet"
else
  rouge "des heures proposées tombent hors des horaires"
fi

if php -r '
$g = require "public/gabarit.php";
exit(isset($g["entetes"]["contact.php"], $g["entetes"]["reservation.php"]) ? 0 : 1);
' 2>/dev/null; then
  vert "le gabarit porte un en-tête par page dynamique"
else
  rouge "le gabarit ne couvre pas les deux formulaires"
fi

# Le nom part dans le sujet du courriel : un saut de ligne y permettrait
# d'injecter un « Bcc: ». Le message, lui, doit garder ses retours à la ligne.
if php -r '
require "src/lib/formulaire.php";
$nom = nettoyer("Pirate\nBcc: victime@example.com", true);
$msg = nettoyer("ligne un\nligne deux", false);
exit((strpos($nom, "\n") === false && strpos($msg, "\n") !== false) ? 0 : 1);
' 2>/dev/null; then
  vert "les sauts de ligne sont retirés du nom, gardés dans le message"
else
  rouge "nettoyage des champs : injection d en-tête possible"
fi

echo
echo "Réservation chez un prestataire"
php src/build.php --source=fixtures/reservation-externe >/dev/null 2>&1
dans index.html 'menu__lien" href="https://reservation.laddition.com/' "le menu mène chez le prestataire"
dans index.html 'bouton--discret" href="https://reservation.laddition.com/' "le bouton de l accueil aussi"
reservation_coherente "le JSON-LD déclare la même adresse que le menu"
dans confidentialite.html 'reservation.laddition.com' "la confidentialité nomme le prestataire"

# Le raccourci /r est assez court pour être dicté au téléphone. Il doit mener
# là où mène le menu, et par une redirection temporaire : une permanente
# resterait en cache le jour d'un changement de prestataire.
if [ -f public/r/index.php ] \
  && grep -q "Location: https://reservation.laddition.com/" public/r/index.php \
  && grep -q '302' public/r/index.php; then
  vert "le raccourci /r renvoie chez le prestataire, en redirection temporaire"
else
  rouge "le raccourci /r est absent ou pointe ailleurs"
fi

if ! grep -q 'reservation.php' public/sitemap.xml; then
  vert "reservation.php sort du plan du site"
else
  rouge "reservation.php reste dans le plan du site"
fi

# reservation.php ne tient plus de formulaire : il renvoie chez le prestataire.
if php -r '
$g = require "public/gabarit.php";
exit(!$g["reservation_active"] && str_starts_with($g["reservation_externe"], "https://") ? 0 : 1);
' 2>/dev/null; then
  vert "reservation.php renvoie chez le prestataire"
else
  rouge "reservation.php ne sait pas où renvoyer"
fi

# Le reste du site est vérifié sur le jeu nominal : on le remet en place.
php src/build.php --source=fixtures >/dev/null 2>&1

echo
echo "Publication automatique"

# Une case à cocher du Sheet arrive en TRUE / FALSE dans le CSV.
if php -r '
require "src/lib/generateur.php";
$oui = ["oui", "OUI", "TRUE", "true", "VRAI"];
$non = ["non", "FALSE", "FAUX", ""];
exit(count(array_filter($oui, "ligne_active")) === 5 && array_filter($non, "ligne_active") === [] ? 0 : 1);
' 2>/dev/null; then
  vert "une case cochée vaut « oui », une case vide vaut « non »"
else
  rouge "les cases à cocher du Sheet ne sont pas comprises"
fi

# Toutes les dix minutes, une page inchangée ne doit pas changer de date : ni
# pour les navigateurs, ni dans le plan du site.
touch -d '2020-01-01 12:00' public/index.html
php src/build.php --source=fixtures >/dev/null 2>&1
if [ "$(date -r public/index.html +%Y)" = 2020 ] && grep -q '2020-01-01' public/sitemap.xml; then
  vert "une page inchangée n'est pas réécrite, le plan du site garde sa date"
else
  rouge "une page inchangée est réécrite à chaque passage"
fi

etat="$sortie.etat"
trap 'rm -f "$sortie" "$sortie.page" "$etat" "$etat.verrou"' EXIT

# Une carte en cours de saisie ne doit pas déclencher d'alerte tout de suite,
# puis une seule, puis une dernière quand c'est réparé.
courriels=""
for source in casse-donnees casse-donnees casse-donnees . ; do
  n=$(php src/automatique.php --source="fixtures/$source" --etat="$etat" --essai | grep -c '^Sujet')
  courriels="$courriels$n"
done

if [ "$courriels" = 0101 ] && [ ! -f "$etat" ]; then
  vert "une alerte au deuxième échec, aucune ensuite, une quand c'est réparé"
else
  rouge "alertes mal cadencées (courriels par passage : $courriels, attendu 0101)"
fi

echo
if [ "$echecs" -eq 0 ]; then
  echo "Tout passe."
  exit 0
fi

echo "$echecs vérification(s) en échec."
exit 1
