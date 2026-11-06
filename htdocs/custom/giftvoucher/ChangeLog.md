# Changelog giftvoucher

## 1.1.0 (04/11/2026)

Bons nominatifs, repris de la copie d'Argonaute (commit ea370c9c329 du 24/09/2026 sur
`argonaute-dev-24`) et corrigés :

- Page Scanner : bandeau avec le nom du titulaire d'un bon nominatif, sans blocage.
- Fiche tiers : ligne « Bons d'achat et chèques cadeaux » avec les bons du client et le total
  encore valable (hook `formObjectOptions`, contexte `thirdpartycard`).
- Le client par défaut d'une caisse TakePOS n'est pas traité comme titulaire
  (`giftvoucherIsNominative()`).
- Corrections par rapport à la copie d'Argonaute : le hook renvoie 0 (avec 1, il masquait les
  attributs supplémentaires de la fiche tiers et la sortie des autres modules) ; l'encart est une
  ligne du tableau de la fiche, et non un tableau glissé dedans ; il n'apparaît qu'en consultation,
  pas en édition ; la validité se calcule avec `isRedeemable()`, comme partout ailleurs.
- Mise à jour depuis 1.0.x : désactiver puis réactiver le module (déclaration du hook).

## 1.0.2 (04/11/2026)

- `redeem()` accepte un 4e paramètre facultatif, `$fk_paiement` : le règlement qui consomme le bon
  est enregistré dans la même mise à jour que la consommation. Le trigger de règlement l'utilise.
  Un module appelant (advancedtakepos, bon expiré accepté en geste commercial) n'a plus besoin
  d'écrire lui-même dans `llx_giftvoucher`.
- Après `redeem()`, l'objet porte aussi l'utilisateur, la facture et le règlement de la consommation.
- Correctif : la classe charge elle-même `date.lib.php`. Sans date de validité saisie, `create()`
  appelle `dol_time_plus_duree()`, que Dolibarr ne charge pas toujours (validation d'une vente
  TakePOS, ligne de commande) : l'émission automatique à la vente pouvait finir en erreur fatale.
- README : les points du module sur lesquels s'appuient les autres modules (classe, statuts,
  trigger, clés de langue, impression).
- En-têtes : ligne de copyright harmonisée, en-tête ajouté à `scripts/enable.php`.

## 1.0.1 (03/11/2026)

- Ticket du bon au style du ticket de caisse : logo de la société, coordonnées et SIRET, police
  Roboto en gras, type et montant en gros caractères, dates alignées, remerciement.
- QR code du site avant le remerciement : celui du pied du ticket de caisse (réglage
  `TAKEPOSDIRECTPRINT_QRURL` du module takeposdirectprint, lu par `takeposdirectprintQr()`),
  seulement si ce module est actif.
- Rendu identique en impression directe (takeposdirectprint) et dans la boîte d'impression du
  navigateur.

## 1.0.0 (18/09/2026)

- Premier module : table `llx_giftvoucher`, liste, fiche, page Scanner, ticket 80 mm avec
  code-barres Code 128, réglages.
- Émission à la main (masque de numérotation) ou automatique à la vente du produit « chèque
  cadeau » : un bon par unité, au TTC de la ligne (trigger `BILL_VALIDATE`).
- Consommation par la page Scanner, par la fiche, ou par un règlement BONACH portant le code du bon
  dans le champ « Numéro » (trigger `PAYMENT_CUSTOMER_CREATE`, qui refuse un bon inconnu, consommé,
  annulé ou expiré). Double emploi bloqué en base.
- Suivi de la consommation : date, utilisateur, facture et règlement.
- Reprise des 2 629 bons Kezia de PecheCenter, codes-barres d'origine conservés.
