# Fixtures

Jeux de CSV pour lancer le générateur sans réseau. Chaque dossier contient un
`carte.csv` et un `infos.csv`.

À la racine, `carte.csv` et `infos.csv` sont une copie conforme du Google
Sheet réel : c'est le jeu nominal.

    php src/build.php --source=fixtures

## Jeux valides

| Dossier               | Ce qu'il vérifie                                                                 |
| --------------------- | -------------------------------------------------------------------------------- |
| *(racine)*            | Le cas nominal. Contient une ligne `actif=non` et des plats sans description.     |
| `colonnes-deplacees/` | Colonnes dans un ordre différent dans les deux onglets. Le build doit réussir.    |

## Jeux cassés

Chacun doit faire échouer le build : rien d'écrit, message sur stderr, sortie 1.

| Dossier                       | Erreur attendue                                                     |
| ----------------------------- | -------------------------------------------------------------------- |
| `casse-entete/`               | La colonne `prix` a été renommée `tarif` dans l'en-tête de `carte`.  |
| `casse-donnees/`              | `nom` vide, `categorie` vide, `prix` non numérique, `ordre` non entier. |
| `casse-aucune-ligne-active/`  | Toutes les lignes sont à `actif=non`.                                |
| `casse-infos/`                | L'en-tête de `infos` est `clef,texte` au lieu de `cle,valeur`.       |

Note : `casse-donnees/` contient une dernière ligne inactive dont le `prix` et
l'`ordre` sont eux aussi invalides. Elle n'est pas signalée, et c'est voulu :
la validation ne porte que sur les lignes qui seront publiées, pour qu'un
brouillon laissé dans le tableur ne bloque pas la mise à jour.

    php src/build.php --source=fixtures/casse-donnees
