# Pichinov — Création rapide de produit (productquickcreate)

Module Dolibarr maison Pichinov. Enrichit le formulaire de création de produit :

- **Bloc Fournisseur / Prix d'achat** (optionnel, repliable) : fournisseur, réf. fournisseur,
  prix d'achat unitaire HT, quantité min, EAN fournisseur — le prix fournisseur est créé en même temps que le
  produit (trigger `PRODUCT_CREATE`), compatible module Multidevise. Une réf. fournisseur ou un EAN
  déjà utilisés par un autre produit refusent la création avec un message (hook `doActions`).
  Depuis le « + Nouveau produit » d'une réception, d'une commande ou d'une facture fournisseur, le
  fournisseur de la pièce est présélectionné et, au retour, la ligne est préremplie (prix d'achat, réf.).
- **Pièces clients** (factures, devis, commandes) : au retour du « + Nouveau produit », la ligne est remplie
  par Dolibarr (prix du niveau du client, TVA, description).
- **Prix de vente niveau 1** à la création quand le multiprix est actif (TTC par défaut), et
  **entrepôt par défaut présélectionné**.
- **Champs obligatoires ou masqués** au choix dans les réglages du module (les champs exigés par
  Dolibarr restent verrouillés) ; un champ obligatoire reste visible même dans une section repliée.
- **Code-barres en tête** : type et valeur du code-barres juste après le libellé, pour scanner l'EAN en premier.
- **Sections repliables** (mobile ready) : Lots / numéros de série, Poids & dimensions,
  Description & notes, Comptabilité, Champs complémentaires — état mémorisé par navigateur.

Aucune modification du core (hooks `formObjectOptions`/`doActions` + trigger). Compatible Dolibarr 22–24.

## Installation
Copier le dossier dans `htdocs/custom/` puis activer « Pichinov — Création rapide de produit »
dans Configuration → Modules (groupe Pichinov). Réglages dans la page de configuration du module.

## Support
jose.martinez@pichinov.com — © 2026 Jose MARTINEZ / Pichinov — GPL v3+
