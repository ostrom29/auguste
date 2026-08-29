#!/usr/bin/env bash
#
# Déploie le site sur l'hébergement, par SSH.
#
#     ./outils/deployer.sh
#
# Vérifie d'abord, envoie ensuite, régénère sur place, puis contrôle le
# résultat en HTTPS. N'efface jamais rien : il écrit les fichiers du projet et
# ne touche à rien d'autre.
#
# Les réglages se surchargent par variables d'environnement :
#     AUGUSTE_HOTE=user@serveur ./outils/deployer.sh

set -u

HOTE=${AUGUSTE_HOTE:-cpe0005013@pf-014.whm.fr-par.scw.cloud}
CLE=${AUGUSTE_CLE:-$HOME/.ssh/auguste_deploy}
DISTANT=${AUGUSTE_DISTANT:-/home/cpe0005013}
SITE=${AUGUSTE_SITE:-https://chezauguste.com}

SSH="ssh -i $CLE -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15"

cd "$(dirname "$0")/.." || exit 1

titre() { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

titre "Vérifications locales"
# « bash src/... » plutôt que « ./src/... » : le bit d'exécution ne survit pas
# toujours à un aller-retour par un partage Windows.
if sortie=$(bash src/verif.sh); then
  echo "$sortie" | tail -2
else
  echo "$sortie"
  echo "Vérifications en échec — rien n'a été envoyé." >&2
  exit 1
fi

titre "Générateur → ~/auguste/"
# verif.sh a besoin des fixtures et de l'arborescence du dépôt : sur le
# serveur il ne saurait qu'échouer.
tar czf - --exclude=src/verif.sh src | $SSH "$HOTE" "mkdir -p $DISTANT/auguste/cache && tar xzf - -C $DISTANT/auguste"
echo "  envoyé"

titre "Ressources servies → ~/public_html/"
# Seulement ce qui est versionné : les pages sont écrites par le build distant.
tar czf - -C public style.css jour.js publier.php contact.php img polices \
  | $SSH "$HOTE" "tar xzf - -C $DISTANT/public_html"
echo "  envoyé"

titre "Bloc .htaccess"
# Idempotent : on n'ajoute que si le repère est absent, et on n'enlève jamais
# les directives que cPanel a écrites en tête de fichier.
if $SSH "$HOTE" "grep -q 'BEGIN chez-auguste' $DISTANT/public_html/.htaccess 2>/dev/null"; then
  echo "  déjà présent, laissé tel quel"
else
  $SSH "$HOTE" "cp $DISTANT/public_html/.htaccess $DISTANT/public_html/.htaccess.avant 2>/dev/null; cat >> $DISTANT/public_html/.htaccess" < deploiement/htaccess.txt
  echo "  ajouté (sauvegarde : .htaccess.avant)"
fi

titre "Clé url_site"
# Sans elle, le générateur n'émet ni canonique, ni Open Graph, ni JSON-LD, ni
# sitemap — et il le dit, mais autant ne pas avoir à y penser.
if $SSH "$HOTE" "grep -q \"'url_site'\" $DISTANT/auguste/config.php"; then
  echo "  déjà présente"
else
  $SSH "$HOTE" "sed -i \"s|^];|    'url_site' => '$SITE',\n];|\" $DISTANT/auguste/config.php"
  echo "  ajoutée : $SITE"
fi

titre "Génération sur le serveur"
$SSH "$HOTE" "cd $DISTANT/auguste && php src/build.php" || {
  echo "Le build distant a échoué — le site en place n'a pas été modifié." >&2
  exit 1
}

titre "Contrôle en HTTPS"
for chemin in / /carte.html /contact.php /mentions-legales.html /confidentialite.html \
              /robots.txt /sitemap.xml /style.css /img/partage.jpg /publier.php /page-qui-nexiste-pas; do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE$chemin")
  printf '  %-28s %s\n' "$chemin" "$code"
done

titre "Redirections et compression"
printf '  %-28s %s\n' "http:// vers https" \
  "$(curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}' --max-time 20 "http://${SITE#https://}/")"
printf '  %-28s %s\n' "www vers domaine nu" \
  "$(curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}' --max-time 20 "https://www.${SITE#https://}/")"
printf '  %-28s %s\n' "compression de la page" \
  "$(curl -s --compressed -o /dev/null -D - --max-time 20 "$SITE/" | grep -i content-encoding | tr -d '\r' || echo 'aucune')"
