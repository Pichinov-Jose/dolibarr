# Pichinov — Enrichissement produit (productenrichment)

Module Dolibarr maison Pichinov (n° 500135, famille Pichinov). À partir de l'EAN d'un produit, il
recherche ses données (UPCitemdb, eBay, puis recherche web par l'IA sur demande) et remplit les champs
VIDES du produit : libellé et description, réf. marque (`ref_marque`), page fabricant (`supplier_url`),
code-barres, prix de vente niveau 1, photos, caractéristiques (module `productcharacteristics`).

- **Création de produit** (popup des réceptions comprise) : bouton Enrichir sous le libellé.
- **Fiche produit** : bouton Enrichir, seuls les champs vides sont proposés.
- **Liste des produits** : action de masse « Enrichir (champs vides) », file d'attente traitée par lots
  (page « File d'enrichissement » du menu Produits, ou tâche planifiée).
- **Données d'enrichissement** (menu Produits) : tous les EAN connus (scanner et module), contrôle de
  cohérence, détail par EAN avec relance d'une source, mise à l'écart du résultat eBay, suppression. Les
  données du scanner ne sont jamais modifiées : la correction est gardée par le module et prime.
- **Onglet Enrichissement** de la fiche produit : données de ses EAN et historique de ce qui a été appliqué.

eBay est interrogé par code produit (eBay France puis US), puis, si rien n'est trouvé, en cherchant l'EAN
dans le texte des annonces (France, Italie, Espagne, Allemagne, Royaume-Uni, US) ; une annonce trouvée ainsi
n'est retenue que si sa page porte l'EAN, et elle est marquée « plein texte ».

Les clés eBay, le modèle d'IA et les quotas du jour sont partagés avec le module ScanCapture.
Aucune modification du cœur (hooks + trigger). Dolibarr 24.

© 2026 Jose Martinez / Pichinov — GPL v3+
