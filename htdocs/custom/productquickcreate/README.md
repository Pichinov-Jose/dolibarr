# Pichinov — Création rapide de produit (productquickcreate)

Module Dolibarr maison Pichinov. Enrichit le formulaire de création de produit :

- **Bloc Fournisseur / Prix d'achat** (optionnel, repliable) : fournisseur, réf. fournisseur,
  prix d'achat unitaire HT, quantité min, EAN fournisseur — le prix fournisseur est créé en même temps que le
  produit (trigger `PRODUCT_CREATE`), compatible module Multidevise. Une réf. fournisseur ou un EAN
  déjà utilisés par un autre produit refusent la création avec un message (hook `doActions`).
  Au retour sur une réception, la réf. fournisseur et le prix d'achat de la ligne sont préremplis.
- **Sections repliables** (mobile ready) : Codes-barres & lots, Poids & dimensions,
  Description & notes, Comptabilité — état mémorisé par navigateur.

Aucune modification du core (hooks `formObjectOptions`/`doActions` + trigger). Compatible Dolibarr 22–24.

## Installation
Copier le dossier dans `htdocs/custom/` puis activer « Pichinov — Création rapide de produit »
dans Configuration → Modules (groupe Pichinov). Réglages dans la page de configuration du module.

## Support
jose.martinez@pichinov.com — © 2026 Jose MARTINEZ / Pichinov — GPL v3+
