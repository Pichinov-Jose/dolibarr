# Pichinov — Réception mobile (receptionmobile, n° 500136)

Module Dolibarr maison Pichinov. Adapte les écrans de réception aux petits écrans, en particulier le
terminal Android UROVO DT50 (412 px de large) utilisé au magasin PecheCenter :

- **Fiche réception** : une fiche par ligne (description, puis réf. fournisseur, prix d'achat, quantité,
  entrepôt, lot avec leur libellé), boutons modifier/supprimer plus grands, glisser-déposer masqué ;
  formulaire d'ajout de ligne en une colonne avec gros bouton « Ajouter », éditeur de description
  replié derrière un lien « + Description », bouton flottant « Lignes » pour sauter l'en-tête.
- **Scan** : quand le code scanné dans la recherche produit ne donne qu'un résultat, il est sélectionné
  tout seul ; le curseur passe alors dans la quantité.
- **Popups** contenant une page (« Nouveau produit », prix fournisseur) : plein écran.
- **Liste des réceptions** : une fiche par réception, cases de sélection masquées, filtres derrière un
  bouton « Filtres ».

Tout est sous `@media (max-width: 600px)` et limité aux pages de réception : rien ne change sur un
ordinateur. Aucune modification du cœur (une feuille de style et un script déclarés dans le descripteur).
Après une mise à jour, désactiver puis réactiver le module (dans deux processus distincts en ligne de
commande) pour que le numéro de version des fichiers change et que les navigateurs les rechargent.

Support : jose.martinez@pichinov.com — © 2026 Jose Martinez / Pichinov — GPL v3+
