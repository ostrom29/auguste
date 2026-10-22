# Où en est le projet

Écrit pour être lu par quelqu'un — ou par une nouvelle session d'assistant —
qui reprend le projet sans rien savoir de ce qui a été dit avant.

Dernière mise à jour : 27 septembre 2026.

## En une phrase

Le site de Chez Auguste est **en ligne et fonctionnel** sur
<https://chezauguste.com>. Le restaurateur met à jour sa carte dans un Google
Sheet, depuis son téléphone s'il veut : le serveur reprend le Sheet toutes les
dix minutes et régénère les pages tout seul.

## Ce qui marche

| | |
| --- | --- |
| Accueil, carte | générés depuis le Sheet, en ligne |
| Publication automatique | cron toutes les 10 min → `src/automatique.php`, alerte par courriel si ça coince |
| Bouton « Publier » | menu **Site** dans le Sheet → `publier.php`, pour publier tout de suite depuis un ordinateur |
| Contact | `contact.php`, testé, courriel reçu |
| Mentions légales, confidentialité | générées, **contenu incomplet** (voir plus bas) |
| Métadonnées, JSON-LD, sitemap | en place |
| HTTPS, domaine canonique, gzip, cache | réglés dans `.htaccess` |
| Réservation | par L'Addition (voir plus bas) |

## Ce qui reste à faire, par ordre de gêne

**1. Le téléphone est faux.** `01 23 45 67 89` dans l'onglet `infos`. C'est le
numéro que compose le bouton d'appel, l'action principale sur mobile. Une
cellule à corriger.

**2. Les mentions légales contiennent des valeurs d'exemple.** Le SIRET
`12345678900012` et « Prénom Nom » sont les exemples de format qui ont été
recopiés tels quels. Une page qui *paraît* remplie est plus gênante qu'une
page marquée À COMPLÉTER. Il manque `raison_sociale` et `forme_juridique`.
Le directeur de la publication est obligatoire (LCEN, art. 1-1) : pour une
SARL, c'est le gérant, il suffit de mettre son nom.

**3. Une vedette de trop.** Sept plats sont cochés `vedette`, l'accueil n'en
affiche que six. Le build le signale à chaque publication.

## La publication automatique

Le menu « Site > Publier » n'existe pas dans les applis Google Sheets pour
téléphone : Google n'y affiche pas les menus Apps Script. Or le restaurateur
retire des plats selon les produits du jour, depuis son téléphone.

Une tâche cron lance donc `src/automatique.php` toutes les dix minutes. Elle
ne dit rien quand tout va bien. Quand la carte est mal remplie deux passages
de suite, ou le Sheet injoignable pendant une heure, elle écrit une fois à
`courriel_alertes` (config.php), puis une fois encore quand c'est réparé.

`outils/deployer.sh` installe la tâche si elle manque. Pour l'arrêter :
cPanel > Tâches Cron, ou `crontab -e` en SSH.

Deux conséquences dans le générateur : une page identique n'est plus réécrite,
et le plan du site date chaque page de son fichier plutôt que du jour du build.

Les colonnes `actif` et `vedette` acceptent des cases à cocher (`TRUE` dans le
CSV) comme « oui » tapé à la main.

## La réservation : par L'Addition, par le site, ou pas du tout

Le restaurant prend ses réservations sur L'Addition :
<https://reservation.laddition.com/booking/chez-auguste-courbevoie#/date>.

Une seule ligne décide de tout, `reservation` dans l'onglet `infos`, puis
« Publier ». Rien à redéployer.

**Une adresse en `https://`** : le menu « Réserver », le bouton de l'accueil
et le JSON-LD y mènent tout droit, dans le même onglet. `reservation.php`
redirige vers elle (302), la politique de confidentialité nomme le
prestataire, et la page sort du sitemap. Changer de prestataire, c'est changer
la cellule.

**`oui`** : le formulaire de demande du site, `public/reservation.php`, avec
ses créneaux déduits des horaires et le refus des jours de fermeture. Il a
tourné en production et une demande réelle a été reçue. Le code reste en place.

**`non`, ou ligne supprimée** : ni menu, ni bouton. `reservation.php` répond
404 avec une page qui explique et renvoie vers le téléphone.

Une valeur qui n'est aucune des trois — une adresse collée sans `https://`,
typiquement — laisse la réservation éteinte, et le build le signale.

Avec « Réserver », le menu a quatre entrées : sous 448 px son corps diminue
par paliers pour qu'il tienne sur une ligne jusqu'à 320 px (`style.css`,
`.menu:has(> :nth-child(4))`). Les seuils ont été mesurés au pixel près.

`src/verif.sh` vérifie que le JSON-LD et le site racontent la même histoire,
dans un sens comme dans l'autre.

## Les commandes

### Vérifier le rendu visuel

Le contrôle des couleurs déclarées se fait sans navigateur :

```
python3 outils/contraste.py
```

Mais une couleur déclarée n'est pas une couleur appliquée : la cascade peut en
remplacer une par une autre en silence. Pour les styles réellement calculés,
et pour les captures d'écran, il faut un navigateur sans interface. Il est
installé dans `~/outils-navigateur`, et `NODE_PATH` est indispensable — sans
lui, le script ne trouve pas `patchright` :

```
cd /mnt/c/Users/gando/.codegpt/skills/browser-automation
NODE_PATH=~/outils-navigateur/node_modules \
  node browser.mjs https://chezauguste.com/ \
  --script ~/auguste/Auguste/outils/audit-contraste.mjs
```

Le même `browser.mjs` prend des captures avec `--screenshot`, ou exécute un
script de contrôle avec `--script`.

### Les commandes

```
bash src/verif.sh              # 30 contrôles, sans réseau
php src/build.php              # génère depuis le Sheet
php src/build.php --source=cache      # rejoue hors ligne
php src/build.php --source=fixtures   # sur les fixtures
bash outils/deployer.sh        # vérifie, envoie, régénère, contrôle en HTTPS
python3 outils/images.py       # retraite medias-sources/ vers public/img/
python3 outils/paquet.py       # archives pour un dépôt manuel par cPanel
```

## Les accès

**Hébergement** : `cpe0005013@pf-014.whm.fr-par.scw.cloud`, SSH par la clé
`~/.ssh/auguste_deploy`. Elle est autorisée dans cPanel, section Accès SSH, et
se révoque de là. Sur une autre machine, il faut recopier cette clé privée ou
en autoriser une nouvelle.

**Arborescence sur le serveur** : le générateur dans `~/auguste/` (hors du
web), le site dans `~/public_html/`. Détails dans [DEPLOIEMENT.md](DEPLOIEMENT.md).

**config.php** n'est pas versionné : il contient les URLs du Sheet, le chemin
de sortie et le secret de publication. Il existe en deux exemplaires, un par
machine, et se recrée depuis `config.example.php`.

## Les décisions prises, et pourquoi

Elles sont dans les messages de commit, qui sont écrits pour être lus —
`git log` en dit plus que ce fichier. Les plus structurantes :

- **Le générateur ne connaît pas son affichage.** `generer()` rend un compte
  rendu, `build.php` le met en forme pour un terminal, `publier.php` pour un
  navigateur. C'est ce qui permet le même code des deux côtés.
- **On valide avant d'écrire.** Une carte invalide ne casse jamais le site :
  rien n'est écrit, l'ancienne version reste en ligne.
- **Les colonnes sont lues par leur nom.** Déplacer une colonne dans le Sheet
  ne casse rien.
- **Une clé vide vaut une clé absente.** Dans un tableur, effacer une cellule
  laisse la ligne en place.
- **Les ressources portent une empreinte.** `style.css?v=…` change quand le
  fichier change : plus de page à moitié stylée après un déploiement.
