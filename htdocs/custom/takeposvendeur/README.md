# Takeposvendeur

Module Dolibarr (Pichinov) pour **TakePOS** : sélection du **vendeur par vente** (façon Kezia) + **marge par ligne / marge globale** avec **code couleur** des lignes.

Conçu pour Argonaute (reprise Kezia → Dolibarr), réutilisable sur toute instance TakePOS (Dolibarr v18+).

## Fonctionnalités

- **Vendeur par vente** : popup à l'ouverture de la vente (obligatoire tant qu'aucun vendeur n'est choisi), enregistré sur la facture (extrafield `fk_vendeur`, filtrable en liste) et imprimé sur le ticket. Les vendeurs = utilisateurs actifs cochés « Vendeur (caisse) » (extrafield `user.vendeur`).
- **Marge** : marge par ligne (taux de marque) et marge globale de la vente, affichées dans le panneau TakePOS.
- **Colonne marge** : montant en gras coloré avec la devise, arrondi à la devise, taux de marque en dessous, colonne centrée — même ordre sur les lignes et sur le total de l'en-tête.
- **Code couleur** des lignes selon la marge : ≥ seuil vert → vert, ≥ seuil orange → orange, sinon rouge (seuils et couleurs configurables).
- **Alerte « vente sous coût × coefficient »** (option, vide = inactive) : la ligne prend une couleur d'alerte quand son net HT est sous le coût × le coefficient réglé (ex. 1,5). Indépendante de la coloration par la marge, on peut donc avoir : la marge seule, l'alerte seule (marge masquée), ou les deux — l'alerte passe alors par-dessus la couleur de marge sur les lignes concernées.
- **Bandeau vendeur à gauche** : le bloc « Vendeur » est affiché dans la cellule de description de l'entête, et non plus dans une colonne dédiée — cela libère une colonne entière dans la zone des montants. La cellule vide correspondante a donc été retirée de chaque ligne du ticket.
- **Ligne sélectionnée toujours visible** : la couleur de marge étant posée en style inline `!important`, elle masquait la surbrillance native de la classe `selected`. La ligne sélectionnée est désormais repeinte avec **sa propre couleur assombrie**, plus une **bordure bleue** à gauche et en haut/bas — repérable même quand la marge est inconnue.
- **Ventes par vendeur** (menu Facturation › Factures clients › *Ventes par vendeur*, droit « Voir les statistiques de ventes par vendeur ») : pour chaque vendeur, nombre de tickets, ventes TTC, avoirs (nombre et montant), CA net HT et TTC, part du CA, panier moyen TTC, marge HT et taux de marque coloré selon les seuils du module. Ligne « Sans vendeur » et total. Filtres : période (raccourcis aujourd'hui, hier, semaine, mois, mois dernier, trimestre, trimestre dernier, année, année dernière), comparaison avec la même période N-1, N-2 et N-3 (CA net TTC et évolution par vendeur, tickets et panier en info-bulle ; N-1 par défaut), caisses (`pos_source`, ou « Hors caisse »), vendeurs (ou « Sans vendeur »). Tri par colonne, export CSV (séparateur `;`, décimales à virgule), lien vers la liste des factures du vendeur sur la période. Règles de calcul :
  - vendeur = champ `fk_vendeur` de la facture ; un avoir sans vendeur est compté pour le vendeur de sa facture d'origine (TakePOS ne recopie pas le champ sur l'avoir) ;
  - factures validées, payées ou non, mêmes types que le rapport natif « CA par utilisateur » ; CA net = ventes + avoirs ;
  - un avoir ou un trop-perçu utilisé pour régler un ticket (ligne négative `(CREDIT_NOTE)` / `(EXCESS RECEIVED)`) ne diminue pas les ventes : l'avoir est déjà déduit à sa création ;
  - marge = prix d'achat enregistré sur la ligne, sinon coût actuel du produit selon la cascade ci-dessous ; lignes sans coût exclues de la marge (part du CA au coût connu en info-bulle, ⚠ sous 95 %) ;
  - colonnes de marge visibles seulement avec le droit natif « voir les marges » (`margins/liretous`).
- **Cascade de sources de coût** configurable et ordonnable : PMP, coût de revient (`cost_price`), prix d'achat fournisseur (min), et un **extrafield produit** générique (`ef:<nom_champ>`, ex. `ef:kezia_cump`). Le calcul prend la première source > 0 dans l'ordre de priorité choisi (constante `TAKEPOSVENDEUR_COST_SOURCES`, CSV ordonné ; défaut = `pmp,cost_price,supplier_min`).

## Configuration

Accueil → Configuration → Modules → **Takeposvendeur** (onglet *Réglages*) :
seuils de marge, couleurs, alerte sous coût × coefficient et sa couleur, base de coût, et bascules (masquer la marge, vendeur facultatif, ne pas imprimer sur le ticket).

Constantes : `TAKEPOSVENDEUR_MARGIN_GREEN`, `TAKEPOSVENDEUR_MARGIN_ORANGE`, `TAKEPOSVENDEUR_COLOR_GREEN|ORANGE|RED|ALERT`, `TAKEPOSVENDEUR_ALERT_COEF`, `TAKEPOSVENDEUR_COST_BASIS`, `TAKEPOSVENDEUR_HIDE_MARGIN`, `TAKEPOSVENDEUR_VENDOR_OPTIONAL`, `TAKEPOSVENDEUR_HIDE_ON_RECEIPT`.

## Mise à jour vers 1.3.0

Le droit et le menu sont enregistrés à l'activation : après copie des fichiers, désactiver puis réactiver le module (ou relancer son `init()`), puis attribuer le droit « Voir les statistiques de ventes par vendeur » aux responsables (les administrateurs le reçoivent automatiquement).

## Architecture

Aucune modification du cœur — tout passe par les hooks TakePOS :

| Fichier | Rôle |
|---|---|
| `core/modules/modTakeposvendeur.class.php` | Descripteur (hooks `takeposinvoice` + `takeposfrontend`, création des extrafields, droit 5001251, menu Facturation) |
| `class/actions_takeposvendeur.class.php` | Hooks : entête (bouton vendeur + marge globale), ligne (marge + couleur), ticket |
| `ajax/savevendeur.php` | Enregistrement du vendeur choisi sur la facture |
| `stats.php` | Écran « Ventes par vendeur » (filtres, tableau, export CSV) |
| `class/takeposvendeurstats.class.php` | Calcul des statistiques par vendeur (SQL) |
| `admin/setup.php` | Page de configuration |
| `admin/about.php` | À propos + vérification de version (GitHub) |
| `support.php` | Formulaire d'assistance (diagnostics + e-mail, autonome) |
| `class/takeposvendeurupdater.class.php` | Vérification de version via l'API GitHub (pas d'auto-install) |
| `class/takeposvendeursupport.class.php` | Collecte de diagnostics + envoi e-mail |
| `lib/takeposvendeur.lib.php` | Onglets admin |
| `langs/*` | Traductions fr_FR / en_US |

## Licence

GPL v3 — voir [COPYING](COPYING).
