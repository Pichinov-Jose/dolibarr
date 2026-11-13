# Pichinov — Champs par fiche (cardfields)

Module Dolibarr maison Pichinov (n° 500137). Pour chaque fiche et chaque mode (création, modification,
consultation), choisir dans les réglages les champs **obligatoires** et les champs **masqués**, champs
complémentaires compris, sans modifier la configuration des champs dans Dolibarr ni le cœur.

Fiches : réception, tiers, contact, produit/service, proposition commerciale, commande client, expédition,
facture client, commande fournisseur, facture fournisseur, projet.

## Fonctionnement
- **Catalogue des champs** : champs écrits à la main pour la fiche réception (en-tête du terminal mobile),
  champs complémentaires de chaque fiche (automatique), et **relevé** : ouvrir une fiche avec
  `&cardfields_survey=1` (liens dans les réglages) enregistre les lignes réellement affichées ; elles
  deviennent réglables.
- **Masqué** : la ligne (ou seulement les cellules du champ quand la ligne en porte plusieurs) reçoit la
  classe `hideobject` ; le champ reste dans le formulaire et poste sa valeur par défaut. Une ligne dont le
  libellé est marqué obligatoire par Dolibarr (`fieldrequired`) n'est jamais masquée. En consultation, un
  bloc de l'en-tête dont tous les champs sont masqués est masqué aussi (option).
- **Obligatoire** : libellé en rouge + contrôle serveur (hook `doActions`) à l'enregistrement de la
  création (`add`) ou de la modification (`update`) ; refus avec la liste des champs manquants et
  réaffichage du formulaire. Seuls les champs présents dans le formulaire sont contrôlés (liste postée
  `cardfields_present`). Sur les fiches sans formulaire de modification (réception, commandes, factures…),
  « obligatoire en modification » refuse d'enregistrer un champ vidé seul en consultation (crayon).
- **Produit** : le formulaire de création reste réglé dans le module Création rapide de produit quand il
  est actif ; ce module règle la modification et la consultation.
- Les lignes des documents (`#tablelines`) et les listes ne sont jamais touchées.

Aucune modification du cœur : hooks `doActions` et `printCommonFooter`, script `js/cardfields.js`.

## Installation
Copier le dossier dans `htdocs/custom/` puis activer « Pichinov — Champs par fiche » dans
Configuration → Modules (groupe Pichinov). Réglages dans la page de configuration du module.

## Support
jose.martinez@pichinov.com — © 2026 Jose Martinez / Pichinov — GPL v3+
