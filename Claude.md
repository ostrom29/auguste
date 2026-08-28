# Chez Auguste — site vitrine

Site vitrine d'un restaurant. Une personne non technique (le restaurateur)
met à jour la carte depuis un Google Sheet et clique « Publier ».

## Environnement cible

- Hébergement mutualisé Scaleway Web Hosting, panel cPanel
- PHP 8, pas d'accès SSH garanti, déploiement par SFTP
- Aucun Node disponible sur l'hébergement

## Contraintes non négociables

- **Aucun build step.** Pas de npm, pas de bundler, pas de framework JS.
- HTML et CSS écrits à la main. Un seul fichier CSS. Mobile-first.
- Le menu est **pré-généré** en HTML par un script PHP. Il n'est jamais
  lu depuis Google au moment où un visiteur charge la page.
- JS vanilla et le moins possible. Le site doit rester entièrement
  lisible avec JS désactivé.
- L'arborescence de `public/` est celle déployée dans `public_html`.
- Le HTML généré n'est pas commité.

## Ce qu'il ne faut pas proposer

React, Vue, Vite, Next, npm, un CMS, une base de données, un conteneur.
Si une solution semble en avoir besoin, c'est que le problème est mal
posé : demande-moi avant d'partir dessus.

## Source de données

Google Sheet publié en CSV, deux onglets.

`carte` : categorie, nom, description, prix, allergenes, ordre, actif
`infos` : cle, valeur (adresse, telephone, horaires_lundi…, message)

## Vérification

`php src/build.php --source=fixtures/` doit produire le site dans
`public/` sans aucun accès réseau. Lance-la après chaque modif du
générateur.

- Le style est en attente de validation. Génère du HTML sémantique
  avec des classes explicites (`.carte`, `.categorie`, `.plat`,
  `.plat__nom`, `.plat__prix`) et un `style.css` réduit au strict
  minimum lisible. Aucune décision esthétique pour l'instant.