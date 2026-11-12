# ChangeLog — Pichinov Product Quick Create

## 1.2.1 (2026-11-12)
- Retour sur la réception après « + Nouveau produit » : la réf. fournisseur et le prix d'achat de la
  ligne sont préremplis depuis le prix fournisseur du produit chez le fournisseur de la réception
  (avant : champs vides, il fallait rechercher à nouveau le produit). Hook doActions, contexte receptioncard
- Le prix saisi dans le bloc est un prix d'achat UNITAIRE (libellé « Prix d'achat unitaire ») : il est
  converti en prix de la quantité minimum attendu par Dolibarr (5 pièces à 5 € → 25 € pour 5, unitaire 5 €)

## 1.2.0 (2026-11-12)
- Le bloc fournisseur ne perd plus les saisies en silence : une réf. fournisseur déjà utilisée chez ce
  fournisseur par un autre produit, un EAN déjà porté par un autre produit, ou une réf./un EAN saisis sans
  prix d'achat refusent la création avec un message qui nomme le produit existant ; le formulaire se
  réaffiche avec les valeurs saisies (contrôle avant création, hook doActions)
- Si le prix d'achat ne peut quand même pas être enregistré, la création du produit est annulée avec la
  raison (le trigger échoue au lieu de renvoyer 0)
- Nouveau champ « EAN fournisseur » (type + valeur) dans le bloc, enregistré sur le prix d'achat comme
  dans l'onglet « Prix d'achat » (si le module Codes-barres est actif)

## 1.1.1 (2026-08-27)
- Compatibilité Dolibarr 24 : filtre fournisseur du select_company en syntaxe universelle ((s.fournisseur:=:1))

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
