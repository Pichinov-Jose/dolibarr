# AdvancedTakePOS

Module Dolibarr (v19+, cible v24) qui porte **par hooks** les personnalisations TakePOS
de Serious Connection, sans aucune modification du cœur. Chaque fonction est derrière
une constante `ADVANCEDTAKEPOS_*`, activable dans la page de réglages du module.

## Fonctions

- **Vignettes produits** : stock (entrepôt terminal + total), prix public niveau 1 + % d'écart
  au niveau réel du client, badges alignés sur la présentation des prix.
- **Barre de titre** : badge niveau de prix « N - Nom » à côté du client (dynamique),
  entrepôt en icône, masquage date/devise, barre de recherche étirée, header compact ≤1024px.
- **Paniers** : nom du client sur l'onglet, bouton de suppression réelle du ticket brouillon.
- **Saisie de ligne** : 4 modes (natif, perspective, popup, popup+clavier réduit) — popup
  Qté/Prix/Remise sur le pattern takeposvendeur, remise plafonnée à 100 %, 3 politiques
  prix-vs-remise, mise à jour en une seule requête (`ajax/updatepriceline.php`).
- **Grille produits** : nombre de produits par appareil (mobile/tablette/desktop),
  presets A/B/C, pager dédié, chargement auto quand les catégories sont masquées.

## Structure

- `class/actions_advancedtakepos.class.php` — tous les hooks (`completeAjaxReturnArray`,
  `completeJSProductDisplay`, `completeTakePosInvoiceHeader`, `ActionButtons`) + CSS généré.
- `js/editpopup.js.php`, `js/editinline.js.php` — modes de saisie.
- `ajax/` — `updatepriceline.php`, `deletesale.php`, `setgridsize.php`.
- `admin/setup.php` — réglages (modes, presets, grilles, bascules).
- `core/modules/modAdvancedTakepos.class.php` — descripteur (n° 500124).

## Déploiement

`htdocs/custom/advancedtakepos/`, puis activation dans Accueil → Modules.
Déployé et validé sur erpdev.seriousconnection.com (Dolibarr v24).
