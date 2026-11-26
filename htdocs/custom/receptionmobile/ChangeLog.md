# ChangeLog — Pichinov Réception mobile

## 1.2.0 (2026-11-26)
- Les six objets natifs sous 600 px, une session par objet : produit (fiche, modification, liste, prix, prix d'achat,
  stock, statistiques, onglets), commande client, commande fournisseur (dont la réception des produits dispatch.php),
  facture client (dont la saisie d'un règlement), facture fournisseur, expédition (dont la création depuis une commande
  et l'onglet Expéditions de la commande). Aucun défilement horizontal à 412 et 360 px sur les pages contrôlées ;
  rien ne change sur ordinateur.
- Commun : pages reconnues (onglets 24.0.3 du produit, règlement facture, agenda/messaging), libellé Réf. fournisseur,
  recherche produit qui se replie, « Ligne libre » à sa taille, seule la boîte des lignes déborde visiblement, aide au
  scan sur les pièces fournisseurs, bouton « Filtres » seulement sous 600 px, menu « +N » des onglets au doigt, retour
  du curseur dans la recherche produit après « Ajouter » (saisie en masse).

## 1.1.0 (2026-11-26)
- Socle commun pour tous les objets natifs : le script pose sur le body rm-on, rm-obj-<objet>, rm-page-card|list|tab et
  rm-doc (documents à lignes) d'après l'adresse (réceptions, commandes clients et fournisseurs, factures clients et
  fournisseurs, expéditions, produits) ; les règles des lignes (#tablelines), du formulaire d'ajout, des popups et des
  listes en fiches valent désormais pour tous ces documents ; l'ordre propre aux réceptions reste limité aux réceptions.
- Un fichier CSS et un fichier JS par objet (css/rm-<objet>.css, js/rm-<objet>.js), vides au départ, chacun tenu par
  une seule session ; fonctions communes exposées dans window.PichinovMobile.

## 1.0.1 (2026-11-22)
- Première ligne d'une réception : le formulaire d'ajout est reconnu (Dolibarr ne lui donne la classe
  liste_titre_create qu'à partir de la 2e ligne) ; le prix d'achat y est recopié et l'affichage mobile s'applique

## 1.0.0 (2026-11-13)
- Fiche réception sous 600 px : une fiche par ligne avec libellés, formulaire d'ajout de ligne en une
  colonne, description repliée, bouton « Lignes », popups de page en plein écran
- Recherche produit : un code scanné qui ne donne qu'un résultat est sélectionné tout seul, curseur
  dans la quantité
- Liste des réceptions sous 600 px : une fiche par réception, filtres derrière un bouton
