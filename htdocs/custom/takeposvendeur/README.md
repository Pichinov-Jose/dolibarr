# Takeposvendeur

Module Dolibarr (Pichinov) pour **TakePOS** : sélection du **vendeur par vente** (façon Kezia) + **marge par ligne / marge globale** avec **code couleur** des lignes.

Conçu pour Argonaute (reprise Kezia → Dolibarr), réutilisable sur toute instance TakePOS (Dolibarr v18+).

## Fonctionnalités

- **Vendeur par vente** : popup à l'ouverture de la vente (obligatoire tant qu'aucun vendeur n'est choisi), enregistré sur la facture (extrafield `fk_vendeur`, filtrable en liste) et imprimé sur le ticket. Les vendeurs = utilisateurs actifs cochés « Vendeur (caisse) » (extrafield `user.vendeur`).
- **Marge** : marge par ligne (taux de marque) et marge globale de la vente, affichées dans le panneau TakePOS.
- **Code couleur** des lignes selon la marge : ≥ seuil vert → vert, ≥ seuil orange → orange, sinon rouge (seuils et couleurs configurables).
- **Base de coût** paramétrable : PMP, coût de revient, ou meilleur prix d'achat fournisseur.

## Configuration

Accueil → Configuration → Modules → **Takeposvendeur** (onglet *Réglages*) :
seuils de marge, couleurs, base de coût, et bascules (masquer la marge, vendeur facultatif, ne pas imprimer sur le ticket).

Constantes : `TAKEPOSVENDEUR_MARGIN_GREEN`, `TAKEPOSVENDEUR_MARGIN_ORANGE`, `TAKEPOSVENDEUR_COLOR_GREEN|ORANGE|RED`, `TAKEPOSVENDEUR_COST_BASIS`, `TAKEPOSVENDEUR_HIDE_MARGIN`, `TAKEPOSVENDEUR_VENDOR_OPTIONAL`, `TAKEPOSVENDEUR_HIDE_ON_RECEIPT`.

## Architecture

Aucune modification du cœur — tout passe par les hooks TakePOS :

| Fichier | Rôle |
|---|---|
| `core/modules/modTakeposvendeur.class.php` | Descripteur (hooks `takeposinvoice` + `takeposfrontend`, création des extrafields) |
| `class/actions_takeposvendeur.class.php` | Hooks : entête (bouton vendeur + marge globale), ligne (marge + couleur), ticket |
| `ajax/savevendeur.php` | Enregistrement du vendeur choisi sur la facture |
| `admin/setup.php` | Page de configuration |
| `admin/about.php` | À propos + vérification de version (GitHub) |
| `support.php` | Formulaire d'assistance (diagnostics + e-mail, autonome) |
| `class/takeposvendeurupdater.class.php` | Vérification de version via l'API GitHub (pas d'auto-install) |
| `class/takeposvendeursupport.class.php` | Collecte de diagnostics + envoi e-mail |
| `lib/takeposvendeur.lib.php` | Onglets admin |
| `langs/*` | Traductions fr_FR / en_US |

## Licence

GPL v3 — voir [COPYING](COPYING).
