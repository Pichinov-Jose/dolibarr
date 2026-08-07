# Pichinov — Création rapide de produit (productquickcreate)

Module Dolibarr maison Pichinov. Enrichit le formulaire de création de produit :

- **Bloc Fournisseur / Prix d'achat** (optionnel, repliable) : fournisseur, réf. fournisseur,
  prix d'achat HT, quantité min — le prix fournisseur est créé en même temps que le produit
  (trigger `PRODUCT_CREATE`), compatible module Multidevise.
- **Sections repliables** (mobile ready) : Codes-barres & lots, Poids & dimensions,
  Description & notes, Comptabilité — état mémorisé par navigateur.

Aucune modification du core (hook `formObjectOptions` + trigger). Compatible Dolibarr 22–24.

## Installation
Copier le dossier dans `htdocs/custom/` puis activer « Pichinov — Création rapide de produit »
dans Configuration → Modules (groupe Pichinov). Réglages dans la page de configuration du module.

## Support
jose.martinez@pichinov.com — © 2026 Jose MARTINEZ / Pichinov — GPL v3+
