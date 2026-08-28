# Déploiement sur l'hébergement

Cible : Scaleway Web Hosting, panel cPanel, PHP 8, dépôt par Gestionnaire de
fichiers ou SFTP. Pas de build step, pas de Composer, rien à compiler.

## L'arborescence n'est pas celle du dépôt

Seul `public_html` est servi par Apache. Le générateur et `config.php` — qui
contient les URLs du Sheet et le secret de publication — doivent rester en
dehors, sinon n'importe qui les télécharge.

    ~/                          racine du compte cPanel, hors web
      auguste/
        src/                    tout le dossier src/ du dépôt
        config.php              à créer sur place, jamais versionné
        cache/                  vide, doit être inscriptible
      public_html/              ← le contenu de public/
        carte.html              écrit par le serveur, pas déposé
        style.css
        publier.php

`publier.php` retrouve le générateur tout seul : il essaie `../src` (le dépôt
en local) puis `../auguste/src` (l'hébergement). Rien à modifier entre les deux.

## Première installation

**1. Déposer les fichiers.** Dans le Gestionnaire de fichiers cPanel, vérifiez
la barre de chemin avant chaque envoi — c'est là qu'on se trompe.

| Depuis le dépôt | Vers |
| --- | --- |
| `public/style.css`, `public/publier.php` | `~/public_html/` |
| `src/` en entier | `~/auguste/src/` |

Créez aussi `~/auguste/cache/`, vide.

**2. Générer un secret**, sur votre machine :

    php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"

**3. Créer `~/auguste/config.php`** avec l'éditeur du Gestionnaire de fichiers,
en repartant de `config.example.php`. Deux lignes changent par rapport au local :

    'sortie_html'        => '/home/xxxxx/public_html/carte.html',
    'secret_publication' => 'le secret généré à l’étape 2',

`xxxxx` est le nom du compte cPanel — il apparaît dans la barre de chemin du
Gestionnaire de fichiers.

**4. Publier une première fois**, dans le navigateur :

    https://chezauguste.com/publier.php?cle=LE_SECRET

Vous devez lire « Carte publiée » et le décompte des plats. Vérifiez ensuite
que `https://chezauguste.com/carte.html` affiche bien la nouvelle carte.

**5. Brancher le bouton du Sheet** — voir [apps-script/README.md](apps-script/README.md).

## Ce qui peut coincer

**« Le générateur est introuvable »** — `src/` n'est pas dans `~/auguste/`, ou
il a été déposé un niveau trop haut ou trop bas.

**« Dossier de sortie non inscriptible »** — les droits de `public_html`. Le
Gestionnaire de fichiers permet de les changer par clic droit ; `755` suffit.

**« Aucun secret de publication n'est configuré »** — `config.php` est absent,
mal placé, ou le secret y est resté sur sa valeur d'exemple.

**Une page blanche** — l'affichage des erreurs PHP est coupé en production.
Regardez les logs d'erreur dans cPanel.

## Les mises à jour suivantes

Le restaurateur ne déploie jamais rien : il clique « Publier » dans son Sheet
et le serveur régénère `carte.html` tout seul.

Vous ne redéposez des fichiers que lorsque **le code ou la CSS** changent.
Lancez `./src/verif.sh` avant, puis renvoyez seulement ce qui a bougé.

Ne déposez jamais `carte.html` à la main : il est écrit par le serveur, et
votre copie locale serait écrasée au prochain clic de toute façon.
