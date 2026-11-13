# ChangeLog — Pichinov Product Quick Create

## 1.3.3 (2026-11-13)
- Code-barres en tête du formulaire de création (popup des réceptions comprise) : les lignes « Type de
  code-barres » et « Valeur code-barres » sont placées juste après le libellé, hors des sections repliées,
  pour saisir ou scanner l'EAN en premier ; les boutons d'Enrichissement produit suivent le champ
- La section repliable « Codes-barres & lots » devient « Lots / numéros de série » (elle n'apparaît plus
  quand les lots sont masqués ou que le module Lots est désactivé)
- Inchangé : un code-barres rendu obligatoire ou masqué dans les réglages le reste à sa nouvelle place

## 1.3.2 (2026-11-13)
- Réglages : « Catégories par défaut » (sélection multiple, comme sur la fiche produit) présélectionnées
  sur le formulaire de création, popup des réceptions comprise ; modifiables avant d'enregistrer ; non
  réappliquées quand le formulaire revient après un refus (les catégories retirées ne reviennent pas)

## 1.3.1 (2026-11-13)
- Champs complémentaires produit dans le tableau « Champs du formulaire de création » : obligatoires ou
  masqués pour la création seulement, sans modifier leur configuration Dolibarr ; un champ complémentaire
  obligatoire dans sa configuration est affiché verrouillé ; une case à cocher ne peut pas être
  obligatoire (décochée, elle n'envoie rien) ; un séparateur peut seulement être masqué
- Les champs complémentaires restants sont regroupés dans une section repliable « Champs complémentaires »

## 1.3.0 (2026-11-12)
- Réglages : tableau « Champs du formulaire de création » pour rendre des champs OBLIGATOIRES ou les
  MASQUER (code-barres, lots, description, URL, note privée, entrepôt par défaut, seuils de stock, nature,
  poids, dimensions, surface, volume, douane, pays d'origine, unité, catégories, codes comptables, prix de
  vente niveau 1, fournisseur, réf. fournisseur, prix d'achat, qté min, EAN fournisseur)
- Un champ obligatoire est marqué en rouge et reste visible même dans une section repliée ; s'il est vide,
  la création est refusée avec la liste des champs manquants et le formulaire est réaffiché (doActions)
- Champs exigés par Dolibarr (réf., libellé, états vente/achat, code-barres si un module de numérotation
  l'exige) : affichés verrouillés, jamais masqués ; sur le formulaire, une ligne que Dolibarr marque
  obligatoire n'est jamais masquée
- Un champ masqué reste dans le formulaire avec sa valeur par défaut ; obligatoire et masqué s'excluent
- Code-barres : sans module de numérotation, Dolibarr affiche son libellé en rouge mais accepte un code
  vide ; il reste donc masquable (protégé seulement si un module de numérotation l'exige)
- Un champ obligatoire du bloc fournisseur reste visible quand on replie le bloc
- Seuls les champs réellement présents dans le formulaire sont contrôlés (liste postée par le script),
  pour qu'un champ absent (service, configuration) ne bloque jamais la création

## 1.2.3 (2026-11-12)
- Correctif du préremplissage de la 1.2.1, qui ne s'affichait pas : le formulaire de ligne lit la réf.
  fournisseur et le prix en POST seulement (GETPOST(..., 2)), les valeurs étaient posées en GET
- Au retour sur la réception, le champ de recherche produit est validé automatiquement : la recherche est
  rejouée et le prix fournisseur du nouveau produit est sélectionné comme par un clic dans la liste, donc
  Dolibarr remplit lui-même la ligne (prix d'achat, réf. fournisseur, TVA, quantité minimum). Hook formConfirm

## 1.2.2 (2026-11-12)
- Champ « Prix de vente niveau 1 » (libellé du niveau, ex. Magasin) sur le formulaire de création quand
  le multiprix est actif (Dolibarr n'affiche alors aucun prix à la création), TTC par défaut, HT possible ;
  enregistré par updatePrice() au niveau 1. Valeur non numérique ou négative refusée avec un message
- Entrepôt par défaut présélectionné sur le formulaire de création : MAIN_DEFAULT_WAREHOUSE s'il est
  ouvert, sinon l'unique entrepôt ouvert (un choix déjà saisi est conservé)

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
