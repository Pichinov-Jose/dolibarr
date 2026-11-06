# Bons d'achat au porteur (giftvoucher)

Module Dolibarr (n° 500127, Pichinov) de **bons d'achat et chèques cadeaux au porteur**, identifiés
par un code-barres : émission à la vente ou à la main, encaissement par scan en caisse ou sur
facture, **blocage du double emploi**, suivi de la consommation et de l'expiration. Il a été écrit
pour la reprise de Kezia chez PecheCenter et tourne sur Dolibarr 24.

## Principe

- Un bon est une ligne de la table `llx_giftvoucher`. Son code (`ref`) est le code-barres imprimé
  (Code 128), unique par entité.
- Deux types : **chèque cadeau** (`gift`, vendu au client) et **avoir / bon de retour** (`credit`).
- Statuts enregistrés : 1 en cours, 2 consommé, 9 annulé. **« Expiré » n'est pas enregistré** :
  c'est un bon en cours dont la date de validité est passée. Il est calculé à l'affichage, dans le
  filtre de la liste et au scan.
- À la consommation, le bon garde la trace complète : date et heure, utilisateur, facture et
  règlement.
- Le double emploi est bloqué en base : la consommation est une mise à jour conditionnelle
  (`… AND status = 1`). Si deux caisses scannent le même bon au même moment, une seule passe.

## Émission

- **À la main** : *Produits | Services > Bons d'achat > Nouveau*. Le code est calculé par le masque
  de numérotation (`BA{yy}{mm}-{0000}` par défaut) ou saisi.
- **Automatiquement à la vente** : on désigne un produit « chèque cadeau » dans les réglages. À la
  validation d'une facture qui le contient (vente TakePOS ou facture classique), le trigger
  `BILL_VALIDATE` crée **un bon par unité vendue**, au montant TTC unitaire de la ligne, rattaché
  à la facture d'émission et au tiers. Il ne recrée rien si la facture a déjà ses bons, et ne bloque
  jamais la validation.
- **Validité** : 12 mois pour un chèque cadeau, 6 mois pour un avoir (réglables), ou date saisie.

## Encaissement

1. **Page Scanner** (`giftvoucher_scan.php`) : pensée pour la douchette, champ en focus
   automatique. Elle affiche le montant, le statut et les dates, puis consomme le bon en un clic,
   avec une référence de facture facultative. Un bon expiré demande le bouton « geste commercial ».
   Un bon déjà consommé affiche la date et la facture de sa consommation.
2. **Fiche du bon** : Consommer (avec confirmation), Annuler, Réactiver (annule la consommation),
   Supprimer.
3. **Facture classique** : règlement en mode « bon d'achat » (BONACH) avec le code du bon dans le
   champ « Numéro ». Le trigger `PAYMENT_CUSTOMER_CREATE` consomme le bon et le lie au règlement.
   Un bon inconnu, consommé, annulé ou expiré **fait échouer le règlement**. Un règlement BONACH
   sans numéro n'est pas contrôlé.
4. **TakePOS** : la fenêtre de règlement de TakePOS n'a pas de champ « Numéro ». Le scan en caisse
   est apporté par le module **advancedtakepos** (réglage `ADVANCEDTAKEPOS_GIFTVOUCHER`) : le bouton
   Bon d'achat ouvre une fenêtre de scan, contrôle le bon, puis encaisse en BONACH le plus petit
   montant entre le bon et le reste dû. Le trigger de giftvoucher consomme alors le bon. Le
   **reliquat** devient un nouveau bon (même type, même date de validité), imprimé automatiquement.
   Sans advancedtakepos, on consomme le bon à côté, par la page Scanner.

Pour TakePOS, le mode BONACH doit être actif et chaque terminal doit avoir un compte bancaire pour
ce mode (`CASHDESK_ID_BANKACCOUNT_BONACH<terminal>`). Prendre un compte interne « Bons d'achat »
de **type compte courant, pas caisse** : un compte de type caisse refuse tout règlement autre qu'en
espèces, et TakePOS annule alors la vente entière.

## Bons nominatifs

Un bon peut porter un tiers (`fk_soc`) : le client à qui il a été vendu ou rendu, ou celui de la
reprise Kezia. Chez Argonaute, presque tous les bons sont nominatifs (586 sur 600) ; chez
PecheCenter, presque aucun.

- **Page Scanner** : un bandeau donne le nom du titulaire, pour que le caissier vérifie. Le bon
  reste encaissable : un proche présente souvent le bon à la place du titulaire.
- **Fiche tiers** (consultation) : une ligne « Bons d'achat et chèques cadeaux » liste les bons du
  client (code, type, montant, validité, statut) et le total encore valable.
- Le **client par défaut d'une caisse TakePOS** (`CASHDESK_ID_THIRDPARTY<terminal>`) n'est pas un
  titulaire : un bon vendu ou rendu en caisse sans client choisi y est rattaché, il reste au
  porteur. Ni bandeau au scan, ni liste sur sa fiche.

## Impression

`giftvoucher_print.php?id=<rowid>` produit un ticket de 80 mm autonome, au style du ticket de
caisse : logo de la société (*Configuration > Société*), coordonnées et SIRET, type de bon, montant
en gros caractères, code-barres Code 128 (généré par Dolibarr), dates d'émission et de validité,
note, QR code du site et remerciement. La page lance l'impression du navigateur à l'ouverture.
Le bouton Imprimer de la fiche l'ouvre dans un nouvel onglet.

Avec le module **takeposdirectprint** (Epson TM, impression directe) :

- le **QR code** est celui du pied du ticket de caisse (réglages `TAKEPOSDIRECTPRINT_QRURL` et
  `TAKEPOSDIRECTPRINT_QRTEXT`, lus par `takeposdirectprintQr()`). Sans ce module, ou sans adresse
  réglée, il n'y a pas de QR code ;
- depuis takeposdirectprint 0.2.2, le bouton Imprimer de la fiche, ouverte depuis une caisse en
  impression directe, envoie le bon à l'imprimante de la caisse ;
- advancedtakepos imprime le bon de reliquat par `TPDP.printUrl()`. Les codes-barres sont redessinés
  en points entiers pour rester lisibles à la douchette.

Rien n'est à régler dans giftvoucher pour cela.

## Réglages

*Configuration > Modules > GiftVoucher > Réglages* :

| Réglage | Constante | Rôle |
|---|---|---|
| Masque des codes | `GIFTVOUCHER_CODE_MASK` | Numérotation des bons créés dans Dolibarr, `BA{yy}{mm}-{0000}` par défaut |
| Validité des chèques cadeaux | `GIFTVOUCHER_VALIDITY_MONTHS_GIFT` | En mois, 12 par défaut |
| Validité des avoirs | `GIFTVOUCHER_VALIDITY_MONTHS_CREDIT` | En mois, 6 par défaut |
| Mode de règlement « bon d'achat » | `GIFTVOUCHER_PAYMENT_MODE_ID` | id dans `llx_c_paiement` du mode BONACH : **il change d'une instance à l'autre** (109 à PecheCenter, 204 à Argonaute) |
| Produit « chèque cadeau » | `GIFTVOUCHER_PRODUCT_ID` | id du produit qui émet les bons à la vente ; 0 = pas d'émission automatique |

Le produit « chèque cadeau » n'est pas créé par le module. Le prendre à prix libre, avec la TVA
voulue (0 % à PecheCenter et à Argonaute). La TVA des bons est une question fiscale, à trancher
avec l'expert-comptable.

## Droits et menus

| Droit | id | Permet |
|---|---|---|
| Lire | 5001271 | Liste, fiche, impression |
| Créer / consommer | 5001272 | Création, scan, consommation, annulation, réactivation |
| Supprimer | 5001273 | Suppression d'un bon |

Menu de gauche *Produits | Services* : Bons d'achat (liste), Scanner un bon, Nouveau.

## Installation

1. Copier le dossier dans `htdocs/custom/giftvoucher`.
2. Activer le module dans *Configuration > Modules* (famille Produits). L'activation crée la table,
   ses index, les droits, les menus et les réglages par défaut. En ligne de commande :
   `php htdocs/custom/giftvoucher/scripts/enable.php`.
3. Vérifier la collation de `llx_giftvoucher` : la table est créée avec la collation par défaut de
   la base. Si elle diffère de celle des tables Dolibarr, les jointures échouent (erreur 1267) :
   `ALTER TABLE llx_giftvoucher CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
4. Régler l'id du mode BONACH de l'instance et, si besoin, le produit « chèque cadeau ».
5. Pour TakePOS : compte « Bons d'achat » sur chaque terminal (voir Encaissement), puis
   advancedtakepos pour le scan en caisse.

**Mise à jour** : copier les fichiers par-dessus. Depuis une version 1.0.x, **désactiver puis
réactiver le module** pour enregistrer le hook de la fiche tiers (1.1.0). La table, les bons et les
réglages sont conservés.

## Utilisation par d'autres modules

advancedtakepos (scan du bon en caisse, bon de reliquat) s'appuie sur les points suivants. Ils
restent stables d'une version à l'autre ; tout changement est annoncé à ses mainteneurs avant
publication.

- **Classe `GiftVoucher`** : `fetch(0, $code)`, `isRedeemable()`, `create($user)` et
  `redeem($user, $fk_facture = 0, $force = 0, $fk_paiement = 0)`. Le 4e paramètre (1.0.2) lie le
  règlement au bon dans la même mise à jour que la consommation.
- **Statuts** : `STATUS_OPEN` = 1 veut toujours dire « ouvert, pas encore consommé » (le filtre SQL
  `status = 1` reste valable), `STATUS_USED` = 2, `STATUS_CANCELED` = 9. Un statut nouveau, par
  exemple « expiré » enregistré, ne prendra jamais la valeur 1.
- **Propriétés** : `id`, `ref`, `amount`, `type_voucher`, `status`, `date_emission`,
  `date_validite`, `date_exerce`, `fk_soc`, `fk_facture`, `fk_paiement`, `note_public`.
- **Trigger** : fichier `interface_99_modGiftvoucher_GiftvoucherTriggers.class.php`, priorité 99.
  Un trigger de priorité plus faible peut donc poser le code du bon dans `num_payment` avant lui.
- **Réglage** `GIFTVOUCHER_PAYMENT_MODE_ID` et **clés de langue** `GiftVoucherNotFound` (avec `%s`),
  `GiftVoucherAlreadyUsed`, `GiftVoucherCanceled`, `GiftVoucherExpired`.
- **Impression** : `giftvoucher_print.php?id=<rowid>`, page autonome qui appelle `window.print()`.

## Reprise de Kezia

Le chargeur ne fait pas partie du module : il appartient à la chaîne de migration Kezia. On reprend
**tous** les bons, consommés et périmés compris, pour trois raisons : repérer au comptoir un bon
déjà utilisé qu'on présente à nouveau, justifier en comptabilité les bons périmés jamais réclamés,
et garder les codes-barres d'origine.

- **PecheCenter** (17/09/2026, `import_key` `KZMIG20260917`) : 2 629 bons, dont 2 175 consommés avec
  leur date et 454 ouverts, parmi lesquels 160 encore valables (5 221,98 €). Le code est l'IDBA
  d'origine : les bons papier en circulation restent scannables.
- **Argonaute** (24/09/2026, `import_key` `KZBA20260924`, chargeur `loaders/load_bons.php`) : code
  `BA` + IDBA sur 6 chiffres, type tiré de `CHQ_Cadeau`, tiers et facture d'émission rattachés
  quand ils sont retrouvés.

Un bon repris est consommé (statut 2) quand Kezia porte une date d'exercice, en cours sinon.

## Limites connues

- Le statut « expiré » n'est pas enregistré : il se déduit de la date de validité.
- Bons nominatifs : le titulaire est affiché, jamais contrôlé. Aucun réglage pour bloquer un bon
  présenté par quelqu'un d'autre.
- Un seul produit « chèque cadeau » par instance.
- La valeur d'un bon émis à la vente est le TTC de la ligne divisé par la quantité : une remise
  sur cette ligne diminue la valeur du bon.
- Depuis la 1.0.1, un QR code est imprimé en bas du bon, plus bas que le code-barres. Vérifier à la
  douchette qu'il ne gêne pas la lecture du code-barres.
- Pas encore de page de statistiques (passif des bons en cours, bons périmés non réclamés).

## Licence

Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>, Pichinov.

GPL v3 ou ultérieure (voir `COPYING`).
