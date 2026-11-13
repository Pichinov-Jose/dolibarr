# ChangeLog — Pichinov Enrichissement produit

## 1.1.1 (2026-11-13)
- Rapprochement des caractéristiques avec les attributs mot à mot : mots vides et unités ignorés, pluriel
  retiré, équivalences anglais / français des sources (material → Matière, buoyancy → Densité, sound →
  Sonorité, breaking strain → Résistance, maximum diving depth → Profondeur de nage, puissance lancer →
  Plage de lancé…) ; « brins » trouve « Nombre de brins »
- Page Support : lien vers le dépôt privé Pichinov/productenrichment

## 1.1.0 (2026-11-13)
- L'EAN d'abord : sur le formulaire de création, les boutons Enrichir et Recherche IA sont à droite du champ
  code-barres du produit (plus de champ EAN séparé, sauf si le module Codes-barres est inactif) et le panneau
  s'ouvre juste en dessous ; la touche Entrée de la douchette lance la recherche au lieu d'enregistrer
- Un EAN reçu dans l'adresse (produit créé depuis une réception) est recherché dès l'ouverture, l'EAN
  fournisseur vide reprend le même code
- Réception : quand l'EAN scanné dans la recherche produit de la ligne n'est trouvé nulle part, un bouton
  « Créer le produit avec l'EAN … » ouvre la création avec le code-barres et l'EAN fournisseur remplis et le
  fournisseur de la réception ; au retour, la ligne est remplie (productquickcreate)

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
