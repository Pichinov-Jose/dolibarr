# ChangeLog — Pichinov Champs par fiche (cardfields)

## 1.0.0 (2026-11-13)
- Réglages par fiche et par mode (création, modification, consultation) : champs OBLIGATOIRES et champs
  MASQUÉS, champs complémentaires compris, sans modifier leur configuration Dolibarr
- Fiche réception : catalogue écrit (entrepôt, projet, dates, poids, dimensions, volume, méthode de
  réception, numéro de suivi, incoterms, modèle, notes, réf. fournisseur, commande d'origine, date de
  création) ; en consultation, l'en-tête peut être réduit aux seules informations utiles avant les lignes
- Champs complémentaires de toutes les fiches, selon leur visibilité Dolibarr dans chaque mode ; un champ
  complémentaire obligatoire dans sa configuration est verrouillé ; une case à cocher ne peut pas être
  obligatoire ; un séparateur peut seulement être masqué
- Relevé des fiches : ouvrir une fiche avec les liens des réglages enregistre ses lignes réellement
  affichées (libellé, champs de saisie, libellé exigé par Dolibarr) ; elles deviennent réglables
- Masqué : ligne ou cellules du champ cachées, valeur par défaut postée ; jamais une ligne que Dolibarr
  marque obligatoire ; en consultation, bloc d'en-tête vide masqué (option)
- Obligatoire : libellé en rouge, refus serveur à l'enregistrement avec la liste des champs manquants et
  formulaire réaffiché ; seuls les champs présents dans le formulaire sont contrôlés ; sur les fiches sans
  formulaire de modification, refus d'enregistrer un champ obligatoire vidé seul (crayon, champs
  complémentaires compris)
- Produit : création laissée au module Création rapide de produit quand il est actif
- Réception sans commande : les dimensions saisies à la création (sizeW/sizeH/sizeS) sont transmises sous
  les noms lus par Dolibarr (trueWidth/trueHeight/trueDepth), elles étaient perdues
