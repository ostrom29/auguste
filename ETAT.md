# Où en est le projet

Écrit pour être lu par quelqu'un — ou par une nouvelle session d'assistant —
qui reprend le projet sans rien savoir de ce qui a été dit avant.

Dernière mise à jour : 29 août 2026.

## En une phrase

Le site de Chez Auguste est **en ligne et fonctionnel** sur
<https://chezauguste.com>. Le restaurateur met à jour sa carte dans un Google
Sheet et clique « Publier » : le serveur régénère les pages tout seul.

## Ce qui marche

| | |
| --- | --- |
| Accueil, carte | générés depuis le Sheet, en ligne |
| Bouton « Publier » | menu **Site** dans le Sheet → `publier.php` → régénération |
| Contact | `contact.php`, testé, courriel reçu |
| Mentions légales, confidentialité | générées, **contenu incomplet** (voir plus bas) |
| Métadonnées, JSON-LD, sitemap | en place |
| HTTPS, domaine canonique, gzip, cache | réglés dans `.htaccess` |
| Réservation | **codée et testée, mais éteinte** (voir plus bas) |

## Ce qui reste à faire, par ordre de gêne

**1. Le téléphone est faux.** `01 23 45 67 89` dans l'onglet `infos`. C'est le
numéro que compose le bouton d'appel, l'action principale sur mobile. Une
cellule à corriger.

**2. Les mentions légales contiennent des valeurs d'exemple.** Le SIRET
`12345678900012` et « Prénom Nom » sont les exemples de format qui ont été
recopiés tels quels. Une page qui *paraît* remplie est plus gênante qu'une
page marquée À COMPLÉTER. Il manque `raison_sociale` et `forme_juridique`.

**3. La photo ne vous appartient pas.** `medias-sources/bouillon.jpg` porte le
filigrane de Sortiraparis et montre, sauf erreur, la salle du Bouillon Julien.
À remplacer par une vraie photo de l'établissement dès que possible.

**4. Une vedette de trop.** Sept plats sont cochés `vedette`, l'accueil n'en
affiche que six. Le build le signale à chaque publication.

## La réservation est éteinte, pas supprimée

Tout le code est en place : `public/reservation.php`, les créneaux déduits des
horaires, la validation des jours de fermeture, le JSON-LD, les tests. Il a
tourné en production et une demande réelle a été reçue.

Il est éteint sur décision, le temps de savoir si le restaurant veut vraiment
prendre des réservations — un bouillon fonctionne traditionnellement sans.

**Pour rallumer** : une ligne `reservation` à `oui` dans l'onglet `infos`, puis
« Publier ». Reviennent alors d'un coup l'entrée de menu, le bouton d'accueil,
la page, l'entrée de sitemap et `acceptsReservations: True` dans le JSON-LD.
Rien à redéployer.

**Pour éteindre** : la même ligne à `non`, ou supprimée. `reservation.php`
répond alors 404 avec une page qui explique et renvoie vers le téléphone.

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
