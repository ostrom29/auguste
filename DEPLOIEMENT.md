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

**1. Déposer les fichiers.** Le Gestionnaire de fichiers cPanel n'envoie que
des fichiers, jamais des dossiers. On envoie donc une archive et on l'extrait
sur place :

    python3 outils/paquet.py

Ça produit deux archives dans `dist/`, une par destination :

| Archive | Extraire dans | Donne |
| --- | --- | --- |
| `1-auguste.zip` | `~/` | `~/auguste/src/`, `config.example.php`, `cache/` |
| `2-public_html.zip` | `~/public_html/` | `publier.php`, `style.css` |

Pour chacune : ouvrez le dossier de destination dans le Gestionnaire, vérifiez
la barre de chemin — c'est là qu'on se trompe —, **Téléverser** l'archive,
puis clic droit dessus et **Extraire**. Supprimez l'archive ensuite ; celle
qui atterrit dans `public_html` serait sinon téléchargeable.

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
Lancez `./src/verif.sh` avant, refaites les archives avec
`python3 outils/paquet.py`, et ne renvoyez que celle qui a bougé.

Pour un seul fichier modifié, l'éditeur du Gestionnaire de fichiers va plus
vite qu'une archive. Le jour où les allers-retours deviennent pénibles,
passez à WinSCP : il envoie un dossier entier d'un coup, récursivement.

Ne déposez jamais `carte.html` à la main : il est écrit par le serveur, et
votre copie locale serait écrasée au prochain clic de toute façon.
