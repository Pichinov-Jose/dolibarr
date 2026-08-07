# ChangeLog — Pichinov Product Quick Create

## 1.1 (2026-08-07)
- Module rattaché au groupe Pichinov dans la liste des modules
- Page de configuration avec interrupteurs (bloc fournisseur, sections repliables)
- Onglets Support et Changelog
- Nouvelle constante PRODUCTQUICKCREATE_SUPPLIER_BLOCK (bloc fournisseur activable/désactivable)

## 1.0 (2026-08-07)
- Bloc optionnel Fournisseur / Prix d'achat sur le formulaire de création de produit
  (fournisseur, réf. fournisseur, prix d'achat HT, quantité min) via hook formObjectOptions
- Création du prix fournisseur via trigger PRODUCT_CREATE, avec prise en charge du module
  Multidevise (le prix est passé aussi en devise société pour éviter un prix à 0)
- Sections repliables sur le formulaire de création (Codes-barres & lots, Poids & dimensions,
  Description & notes, Comptabilité), état mémorisé par navigateur, adapté mobile
- Constante PRODUCT_CREATE_COLLAPSE_SECTIONS pour activer/désactiver les sections
