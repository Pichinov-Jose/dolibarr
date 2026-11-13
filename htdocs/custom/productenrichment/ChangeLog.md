# ChangeLog — Pichinov Enrichissement produit

## 1.0.0 (2026-11-13)
- Recherche par EAN en cascade UPCitemdb → eBay (Browse API) → IA sur demande (recherche web), logique
  reprise du module ScanCapture, mêmes clés, même modèle d'IA et mêmes quotas du jour
- Cache par EAN (llx_productenrichment_cache) qui relit aussi le cache du scanner (le plus riche gagne)
- Formulaire de création de produit (popup « Nouveau produit » des réceptions comprise) : champ EAN,
  bouton Enrichir, bouton Recherche IA, propositions cliquables qui remplissent les champs du formulaire
  (libellé, description, code-barres, réf. marque, page fabricant, et les champs du module
  productquickcreate : réf. fournisseur, prix d'achat unitaire, EAN fournisseur, prix de vente niveau 1),
  jamais d'écrasement sans confirmation ; photos et caractéristiques appliquées à la création (trigger)
- Fiche produit : bouton Enrichir, remplit seulement les champs vides (description, réf. marque, page
  fabricant, code-barres, prix de vente niveau 1 si nul, photos si aucune, caractéristiques absentes)
- Liste des produits : action de masse « Enrichir (champs vides) » avec file d'attente, tâche planifiée
  et page « File d'enrichissement » (traitement depuis la page, journal, relance, purge) ; une action de
  masse ne modifie jamais de prix
- Caractéristiques via le module productcharacteristics (attributs réglés en caractéristique,
  rapprochement par libellé, modifiable avant d'appliquer) ; les autres vont dans la description
- Déclinaisons du modèle affichées pour information, jamais créées automatiquement
- Photos : https seulement, hôtes publics seulement, vraies images seulement, 3 au plus par défaut
