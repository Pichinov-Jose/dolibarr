# ChangeLog — Pichinov Enrichissement produit

## 1.2.4 (2026-11-26)
- Téléphone et terminal portable (DT50, 412 px), sous 600 px seulement : sur la fiche de création, les boutons
  « Enrichir » et « Recherche IA » passent sur leur propre ligne sous le code-barres, qui garde toute la largeur
  (il ne restait que 61 px au champ depuis la mise en page mobile de productquickcreate 1.6.0)
- Panneau sous 600 px : une ligne par champ, libellé au-dessus, puis la valeur et les boutons ; propositions de
  libellé sur toute la largeur (le texte s'écrivait une lettre par ligne), boutons de 44 px, plus de défilement
  interne au panneau ; onglet Enrichissement : « Actuel » et « Proposé » écrits devant chaque valeur
- Le libellé choisi est marqué (coche verte) et les boutons « Remplir » cochés, le champ étant souvent hors de
  l'écran sur un terminal
- Ordinateur : rien ne change

## 1.2.3 (2026-11-13)
- Une recherche en erreur dit pourquoi : panneau (« IA : erreur — crédit de l'API Anthropic épuisé… »), écran
  Données d'enrichissement (relance d'une source) et file d'enrichissement. Raisons reconnues : crédit IA épuisé,
  clé refusée, modèle inconnu, trop de demandes, service surchargé, délai dépassé, clés eBay refusées, limite
  UPCitemdb ; sinon le code HTTP et le message du service
- Quand le service refuse l'appel (UPCitemdb, eBay ou IA), la demande n'est plus décomptée du quota du jour
- File d'enrichissement : un produit dont une source était en erreur passe en « Erreur » avec la raison (et non
  plus « Non trouvé ») ; le bouton de relance des erreurs le remet en file

## 1.2.2 (2026-11-13)
- Badge et filtre « UPCitemdb » seulement quand UPCitemdb a trouvé l'article (avant : dès qu'il avait été
  interrogé, même sans résultat). Nouveau repère `upc_found` ; les résultats plus anciens sont déduits de leurs
  données (catégorie, image ou titre venus d'UPCitemdb)
- « Écarter le résultat eBay » efface aussi le titre venu d'eBay quand UPCitemdb avait été interrogé sans rien
  trouver (il était gardé à tort)

## 1.2.1 (2026-11-13)
- eBay : quand la recherche par code produit (eBay France puis US) ne trouve rien, l'EAN est cherché dans le
  texte des annonces sur eBay France, Italie, Espagne, Allemagne, Royaume-Uni puis US (première annonce trouvée),
  sous ses deux écritures (EAN-13 et UPC à 12 chiffres). Vu en réel : 0194151130454 (Mares Quad 2) n'était
  trouvé qu'ainsi, sur eBay Italie et Espagne
- Une annonce trouvée en plein texte n'est retenue que si sa page porte bien l'EAN ; le contrôle de cohérence
  avec le titre déjà connu s'applique comme avant, et rien n'est appliqué sans action de l'utilisateur
- Résultat marqué « eBay · plein texte » (place de marché en infobulle) dans « Données d'enrichissement » et
  l'onglet, et « plein texte, eBay Italie » dans le panneau ; le prix constaté indique la place de marché
- Cache eBay en version 3 : un EAN déjà interrogé sans résultat (version 2) est interrogé une fois de plus, à son
  prochain enrichissement ; aucune relance en masse. Jusqu'à 8 appels eBay pour un EAN introuvable (2 avant),
  le quota du jour compte toujours une recherche par EAN
- Marque et référence fabricant lues aussi dans les caractéristiques en italien, espagnol, allemand et anglais

## 1.2.0 (2026-11-13)
- Écran « Données d'enrichissement » (menu Produits) : tous les EAN connus, du cache du scanner comme de celui du
  module (titre, marque, photo, sources UPCitemdb / eBay / IA, produits liés par code-barres, EAN fournisseur ou
  scan, date), recherche et filtres (résultat, source, cohérence, lié ou non, origine, corrigé)
- Contrôle de cohérence global en tête de liste : trouvés / rien trouvé, sources, liés / non liés, « sans rapport
  avec le produit », « eBay sans rapport », eBay écartés ; chaque nombre ouvre la liste correspondante
- Détail d'un EAN : toutes les données (descriptions, caractéristiques — celles d'eBay marquées —, prix, photos,
  déclinaisons, annonces eBay, sources, JSON brut) et trois actions : relancer une source (UPCitemdb, eBay, IA
  avec confirmation du coût), écarter le résultat eBay, supprimer l'entrée
- Les données du scanner ne sont jamais modifiées : une correction est enregistrée dans le cache du module
  (marque « Corrigé ») et prime ensuite sur le scanner, y compris pour les recherches suivantes
- Onglet « Enrichissement » sur la fiche produit : données enregistrées pour ses EAN (code-barres et EAN
  fournisseurs), cohérence avec son libellé, historique de ce qui a été appliqué (date, origine, champs, auteur) ;
  bouton Enrichir qui ouvre directement le dialogue de la fiche
- eBay : les caractéristiques apportées par eBay sont désormais repérées (`specs_ebay`) pour pouvoir les retirer

## 1.1.2 (2026-11-13)
- Garde-fou « même EAN, autre article » (vu en réel : l'EAN d'une tête plombée AstuFish renvoyait sur eBay une
  lotion pour le corps, dont les photos et les caractéristiques avaient été posées) :
  - eBay : une annonce dont aucun titre ne partage un mot significatif avec le titre déjà connu (UPCitemdb, IA,
    marque) est écartée (statut « sans rapport »), rien n'en est fusionné ;
  - IA lancée après eBay : les photos, caractéristiques et liens d'annonces eBay sans rapport avec l'article
    trouvé par l'IA sont retirés ;
  - application (fiche et masse) : rien n'est appliqué si le résultat ne concorde pas avec le libellé du produit
    (nouveau statut « Sans rapport » dans la file) ; des photos venues d'eBay ne sont posées que si une annonce
    concorde avec le libellé
- Les mots génériques (pêche, leurre, hameçon, pack, taille…) et les nombres seuls ne suffisent pas à concorder

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
