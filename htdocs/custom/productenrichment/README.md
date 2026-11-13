# Pichinov — Enrichissement produit (productenrichment)

Module Dolibarr maison Pichinov (n° 500135, famille Pichinov). À partir de l'EAN d'un produit, il
recherche ses données (UPCitemdb, eBay, puis recherche web par l'IA sur demande) et remplit les champs
VIDES du produit : libellé et description, réf. marque (`ref_marque`), page fabricant (`supplier_url`),
code-barres, prix de vente niveau 1, photos, caractéristiques (module `productcharacteristics`).

- **Création de produit** (popup des réceptions comprise) : bouton Enrichir sous le libellé.
- **Fiche produit** : bouton Enrichir, seuls les champs vides sont proposés.
- **Liste des produits** : action de masse « Enrichir (champs vides) », file d'attente traitée par lots
  (page « File d'enrichissement » du menu Produits, ou tâche planifiée).

Les clés eBay, le modèle d'IA et les quotas du jour sont partagés avec le module ScanCapture.
Aucune modification du cœur (hooks + trigger). Dolibarr 24.

© 2026 Jose Martinez / Pichinov — GPL v3+
