# Le bouton « Publier » dans le Sheet

`Publier.gs` ajoute un menu **Site** dans la barre du Google Sheet. Le
restaurateur clique, la carte se régénère, et il lit le résultat sans quitter
son tableur.

Le script n'a aucune intelligence : il appelle `publier.php` sur l'hébergement
et affiche sa réponse. Toute la validation reste côté serveur.

## Installation, une seule fois

1. Dans le Sheet : **Extensions > Apps Script**.
2. Remplacer le contenu de `Code.gs` par celui de `Publier.gs`.
3. Enregistrer, puis recharger le Google Sheet. Le menu **Site** apparaît.
4. **Site > Régler le lien de publication…** et coller le lien complet :

       https://chezauguste.com/publier.php?cle=LE_SECRET

   Le lien est rangé dans les propriétés du script, pas dans le code — les
   autres éditeurs du tableur ne le lisent donc pas en clair.

5. **Site > Publier la carte**.

## La première fois, Google demande une autorisation

Au premier clic, Google affiche un écran d'autorisation, puis un avertissement
**« Cette application n'est pas validée »**. C'est normal : le script n'est pas
publié sur le store Google, il vit dans ce tableur.

Il faut cliquer sur **Paramètres avancés**, puis sur **Accéder à … (non
sécurisé)**. C'est franchement inquiétant pour quelqu'un de non technique :
**soyez à côté de lui ce jour-là.** Ça ne se produit qu'une fois.

Le script ne demande qu'une seule permission, celle d'appeler une URL externe.

## Ce qu'il voit

Quand tout va bien :

    Carte publiée

    21 plats en ligne : Entrées (4), Plats (8), Desserts (5), Boissons (4).
    Mise à jour le 28/08/2026 à 22h27.
    1 ligne non publiée, car la colonne « actif » n'est pas à « oui ».

Quand il s'est trompé dans une case :

    Publication impossible

    onglet « carte », ligne 5 : le champ « prix » doit être un nombre (lu : « 12,90 € »)

    Corrigez ces lignes dans le Google Sheet, puis republiez. En attendant,
    la carte précédente reste en ligne, inchangée.

C'est le point important : **une carte invalide ne casse jamais le site.**
L'ancienne version reste en ligne tant que la nouvelle n'est pas correcte.

## Si le lien change

Le secret vit dans `config.php` sur l'hébergement. Si vous le changez,
repassez par **Site > Régler le lien de publication…**.
