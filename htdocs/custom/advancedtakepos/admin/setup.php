<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    admin/setup.php
 * \ingroup advancedtakepos
 * \brief   Page de configuration : toggles des fonctionnalites TakePOS avancees.
 */

// Load Dolibarr environment
$res = 0;
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if ($i > 0 && !empty($tmp[$i - 1]) && $tmp[$i - 1] == '/' && $j > 0) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && !empty($tmp[$i]) && $tmp[$i] == '/') {
	$res = @include substr($tmp, 0, $i)."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $conf, $db, $langs, $user;

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
dol_include_once('/advancedtakepos/class/actions_advancedtakepos.class.php');

// Translations
$langs->loadLangs(array('admin', 'advancedtakepos@advancedtakepos'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */
if ($action == 'setconst') {
	// setter generique gere par ajax_constantonoff ; rien a faire ici
}
if ($action == 'saveparams') {
	$imode = GETPOSTINT('ADVANCEDTAKEPOS_INPUT_MODE');
	$preset = GETPOSTINT('ADVANCEDTAKEPOS_DESKTOP_PRESET');
	$pagerpos = GETPOSTINT('ADVANCEDTAKEPOS_PAGER_POS');
	$mode = GETPOSTINT('ADVANCEDTAKEPOS_PRICE_DISCOUNT_MODE');
	$publevel = GETPOSTINT('ADVANCEDTAKEPOS_PUBLIC_PRICE_LEVEL');
	$promolevel = GETPOSTINT('ADVANCEDTAKEPOS_PROMO_LEVEL');
	$gm = GETPOSTINT('ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE');
	$gt = GETPOSTINT('ADVANCEDTAKEPOS_MAXPRODUCT_TABLET');
	$gd = GETPOSTINT('ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP');
	$tilelines = GETPOSTINT('ADVANCEDTAKEPOS_TILE_LINES');
	$prodtab = GETPOST('ADVANCEDTAKEPOS_PRODUCT_CARD_TAB', 'aZ09');
	$soctab = GETPOST('ADVANCEDTAKEPOS_THIRDPARTY_CARD_TAB', 'aZ09');
	// Sources de codes secondaires : jetons pfp, ef:<champ>, tbl:<table>:<code>:<produit> (noms SQL seulement).
	$altsources = preg_replace('/\s+/', '', GETPOST('ADVANCEDTAKEPOS_ALT_BARCODES', 'alphanohtml'));
	$err = 0;
	if (!in_array($mode, array(0, 1, 2)) || !in_array($imode, array(0, 1, 2, 3))) {
		$err++;
	}
	$maxlevel = getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT');
	if ($maxlevel < 1) {
		$maxlevel = 1;
	}
	if ($publevel < 1 || $publevel > $maxlevel) {
		$err++;
	}
	if ($promolevel < 0 || $promolevel > $maxlevel) {
		$err++;
	}
	if (($gm != 0 && ($gm < 6 || $gm > 60)) || ($gt != 0 && ($gt < 6 || $gt > 60)) || ($gd != 0 && ($gd < 6 || $gd > 60))) {
		$err++;
	}
	if ($tilelines < 2 || $tilelines > 6) {
		$err++;
	}
	if (!array_key_exists($prodtab, ActionsAdvancedTakepos::productCardTabs()) || !array_key_exists($soctab, ActionsAdvancedTakepos::thirdpartyCardTabs())) {
		$err++;
	}
	$alttoken = '(pfp|ef:[a-zA-Z0-9_]+|tbl:[a-zA-Z0-9_]+:[a-zA-Z0-9_]+:[a-zA-Z0-9_]+|ref:pfp|ref:ef:[a-zA-Z0-9_]+)';
	if ($altsources !== '' && !preg_match('/^'.$alttoken.'(,'.$alttoken.')*$/', $altsources)) {
		$err++;
	}
	if (!$err) {
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_INPUT_MODE', $imode, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_PAGER_POS', ($pagerpos == 1 ? 1 : 0), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_DESKTOP_PRESET', $preset, 'chaine', 0, '', $conf->entity);
		// Presets grille desktop : A 24 produits (grille 26 + pager), B 30 (grille 32 + 10/rangee), C natif 22.
		if ($preset == 1) {
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP', 26, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_DESKTOP_PAGER', 1, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_TILES_PER_ROW_DESKTOP', 0, 'chaine', 0, '', $conf->entity);
		} elseif ($preset == 2) {
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP', 32, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_DESKTOP_PAGER', 1, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_TILES_PER_ROW_DESKTOP', 10, 'chaine', 0, '', $conf->entity);
		} elseif ($preset == 3) {
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP', 24, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_DESKTOP_PAGER', 0, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_TILES_PER_ROW_DESKTOP', 0, 'chaine', 0, '', $conf->entity);
		}
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_PRICE_DISCOUNT_MODE', $mode, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_PUBLIC_PRICE_LEVEL', $publevel, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_PROMO_LEVEL', $promolevel, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE', $gm, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_TABLET', $gt, 'chaine', 0, '', $conf->entity);
		// Le preset fixe deja la grille desktop : le champ ne compte qu'en mode manuel (sinon il ecrasait le preset).
		if ($preset == 0) {
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP', $gd, 'chaine', 0, '', $conf->entity);
		}
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_TILE_LINES', $tilelines, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_PRODUCT_CARD_TAB', $prodtab, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_THIRDPARTY_CARD_TAB', $soctab, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_ALT_BARCODES', $altsources, 'chaine', 0, '', $conf->entity);
		// Categorie racine par caisse (0 = celle du reglage TakePOS, commune a toutes les caisses).
		for ($t = 1; $t <= max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS')); $t++) {
			$rootcat = GETPOSTINT('ADVANCEDTAKEPOS_ROOT_CATEGORY_ID'.$t);
			dolibarr_set_const($db, 'ADVANCEDTAKEPOS_ROOT_CATEGORY_ID'.$t, ($rootcat > 0 ? $rootcat : 0), 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('ErrorBadValue'), null, 'errors');
	}
}

/*
 * View
 */
$form = new Form($db);

$help_url = '';
$title = $langs->trans('AdvancedTakeposSetup');
llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-advancedtakepos page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print '<span class="opacitymedium">'.$langs->trans('AdvancedTakeposSetupPage').'</span><br><br>';

// Liste des toggles : constante => (libelle, aide)
$toggles = array(
	'ADVANCEDTAKEPOS_SHOW_STOCK'          => array('AdvTakeposShowStock', 'AdvTakeposShowStockHelp'),
	'ADVANCEDTAKEPOS_SHOW_PUBLIC_PRICE'   => array('AdvTakeposShowPublicPrice', 'AdvTakeposShowPublicPriceHelp'),
	'ADVANCEDTAKEPOS_SHOW_PRICE_DIFF'     => array('AdvTakeposShowPriceDiff', 'AdvTakeposShowPriceDiffHelp'),
	'ADVANCEDTAKEPOS_SHOW_LEVEL_IN_TITLE' => array('AdvTakeposShowLevelInTitle', 'AdvTakeposShowLevelInTitleHelp'),
	'ADVANCEDTAKEPOS_FULLWIDTH_NO_CAT'    => array('AdvTakeposFullwidthNoCat', 'AdvTakeposFullwidthNoCatHelp'),
	'ADVANCEDTAKEPOS_SMALLER_FONT'        => array('AdvTakeposSmallerFont', 'AdvTakeposSmallerFontHelp'),
	'ADVANCEDTAKEPOS_TILES_NO_IMAGE'      => array('AdvTakeposTilesNoImage', 'AdvTakeposTilesNoImageHelp'),
	'ADVANCEDTAKEPOS_HIDE_TERMINAL_DATE'  => array('AdvTakeposHideTerminalDate', 'AdvTakeposHideTerminalDateHelp'),
	'ADVANCEDTAKEPOS_HIDE_CURRENCY'       => array('AdvTakeposHideCurrency', 'AdvTakeposHideCurrencyHelp'),
	'ADVANCEDTAKEPOS_CART_CUSTOMER'       => array('AdvTakeposCartCustomer', 'AdvTakeposCartCustomerHelp'),
	'ADVANCEDTAKEPOS_DELETE_SALE'         => array('AdvTakeposDeleteSaleOpt', 'AdvTakeposDeleteSaleOptHelp'),
	'ADVANCEDTAKEPOS_COMPACT_ACTIONS'     => array('AdvTakeposCompactActions', 'AdvTakeposCompactActionsHelp'),
	'ADVANCEDTAKEPOS_LIGHT_HEADER'        => array('AdvTakeposLightHeader', 'AdvTakeposLightHeaderHelp'),
	'ADVANCEDTAKEPOS_PAGER'               => array('AdvTakeposPager', 'AdvTakeposPagerHelp'),
	'ADVANCEDTAKEPOS_PRODUCT_CARD_POPUP'  => array('AdvTakeposProductCardPopup', 'AdvTakeposProductCardPopupHelp'),
	'ADVANCEDTAKEPOS_LINE_AT_PUBLIC_PRICE' => array('AdvTakeposLineAtPublicPrice', 'AdvTakeposLineAtPublicPriceHelp'),
	'ADVANCEDTAKEPOS_LINE_PUBLIC_PRICE_COL' => array('AdvTakeposLinePublicPriceCol', 'AdvTakeposLinePublicPriceColHelp'),
	'ADVANCEDTAKEPOS_UNIT_PRICE_TTC'     => array('AdvTakeposUnitPriceTtc', 'AdvTakeposUnitPriceTtcHelp'),
	'ADVANCEDTAKEPOS_THIRDPARTY_CARD_POPUP' => array('AdvTakeposThirdpartyCardPopup', 'AdvTakeposThirdpartyCardPopupHelp'),
	'ADVANCEDTAKEPOS_LINE_DESC_EDIT'      => array('AdvTakeposLineDescEdit', 'AdvTakeposLineDescEditHelp'),
	'ADVANCEDTAKEPOS_RECEIPT_LINE_DESC'   => array('AdvTakeposReceiptLineDesc', 'AdvTakeposReceiptLineDescHelp'),
	'ADVANCEDTAKEPOS_SEND_BUTTON'         => array('AdvTakeposSendButton', 'AdvTakeposSendButtonHelp'),
	'ADVANCEDTAKEPOS_RESTORE_CATEGORY'    => array('AdvTakeposRestoreCategory', 'AdvTakeposRestoreCategoryHelp'),
	'ADVANCEDTAKEPOS_SPLIT_TAB'           => array('AdvTakeposSplitTab', 'AdvTakeposSplitTabHelp'),
	'ADVANCEDTAKEPOS_LOCK_PAID_LINES'     => array('AdvTakeposLockPaidLines', 'AdvTakeposLockPaidLinesHelp'),
	'ADVANCEDTAKEPOS_CASHCONTROL_TERMINAL' => array('AdvTakeposCashTerminal', 'AdvTakeposCashTerminalHelp'),
	'ADVANCEDTAKEPOS_CASH_STATUS'         => array('AdvTakeposCashStatus', 'AdvTakeposCashStatusHelp'),
	'ADVANCEDTAKEPOS_CUSTOMER_BUTTON'     => array('AdvTakeposCustomerButton', 'AdvTakeposCustomerButtonHelp'),
	'ADVANCEDTAKEPOS_C_RESETS_LINE'       => array('AdvTakeposCResetsLine', 'AdvTakeposCResetsLineHelp'),
	'ADVANCEDTAKEPOS_CATEGORY_COLORS'     => array('AdvTakeposCategoryColors', 'AdvTakeposCategoryColorsHelp'),
	'ADVANCEDTAKEPOS_CATEGORY_BREADCRUMB' => array('AdvTakeposCategoryBreadcrumb', 'AdvTakeposCategoryBreadcrumbHelp'),
	'ADVANCEDTAKEPOS_SEARCH_COUNT'        => array('AdvTakeposSearchCount', 'AdvTakeposSearchCountHelp'),
	'ADVANCEDTAKEPOS_SEARCH_NO_SOFT_KEYBOARD' => array('AdvTakeposSearchNoSoftKeyboard', 'AdvTakeposSearchNoSoftKeyboardHelp'),
	'ADVANCEDTAKEPOS_POPUP_FIT'           => array('AdvTakeposPopupFit', 'AdvTakeposPopupFitHelp'),
	'ADVANCEDTAKEPOS_POPUP_ESC'           => array('AdvTakeposPopupEsc', 'AdvTakeposPopupEscHelp'),
	'ADVANCEDTAKEPOS_SEARCH_SUPPLIER'     => array('AdvTakeposSearchSupplier', 'AdvTakeposSearchSupplierHelp'),
	'ADVANCEDTAKEPOS_SEARCH_ATTRIBUTES'   => array('AdvTakeposSearchAttributes', 'AdvTakeposSearchAttributesHelp'),
	'ADVANCEDTAKEPOS_SEARCH_FEATURES'     => array('AdvTakeposSearchFeatures', 'AdvTakeposSearchFeaturesHelp'),
	'ADVANCEDTAKEPOS_SERVICE_BADGE'       => array('AdvTakeposServiceBadgeOpt', 'AdvTakeposServiceBadgeOptHelp'),
	'ADVANCEDTAKEPOS_HEADER_REMAIN'       => array('AdvTakeposHeaderRemain', 'AdvTakeposHeaderRemainHelp'),
	'ADVANCEDTAKEPOS_CREDIT_HELP'         => array('AdvTakeposCreditHelp', 'AdvTakeposCreditHelpHelp'),
	'ADVANCEDTAKEPOS_PAY_CONFIRM'         => array('AdvTakeposPayConfirmOpt', 'AdvTakeposPayConfirmOptHelp'),
	'ADVANCEDTAKEPOS_PAY_CANCEL'          => array('AdvTakeposPayCancelOpt', 'AdvTakeposPayCancelOptHelp'),
	'ADVANCEDTAKEPOS_GIFTVOUCHER'         => array('AdvTakeposGvOpt', 'AdvTakeposGvOptHelp'),
	'ADVANCEDTAKEPOS_GV_ALLOW_EXPIRED'    => array('AdvTakeposGvExpiredOpt', 'AdvTakeposGvExpiredOptHelp'),
);

// --- Parametres (valeurs) ---
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saveparams">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('AdvTakeposParams').'</td><td class="center" width="220">'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposInputMode'), $langs->trans('AdvTakeposInputModeHelp'));
print '</td><td class="center">';
$imodes = array(0 => $langs->trans('AdvTakeposIMNative'), 1 => $langs->trans('AdvTakeposIMInline'), 2 => $langs->trans('AdvTakeposIMPopup'), 3 => $langs->trans('AdvTakeposIMPopupOnly'));
print $form->selectarray('ADVANCEDTAKEPOS_INPUT_MODE', $imodes, getDolGlobalInt('ADVANCEDTAKEPOS_INPUT_MODE', 2), 0);
print '</td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposPriceDiscountMode'), $langs->trans('AdvTakeposPriceDiscountModeHelp'));
print '</td><td class="center">';
$modes = array(0 => $langs->trans('AdvTakeposPDModeNative'), 1 => $langs->trans('AdvTakeposPDModeReset'), 2 => $langs->trans('AdvTakeposPDModeConvert'));
print $form->selectarray('ADVANCEDTAKEPOS_PRICE_DISCOUNT_MODE', $modes, getDolGlobalInt('ADVANCEDTAKEPOS_PRICE_DISCOUNT_MODE', 1), 0);
print '</td></tr>';
// Niveau de prix servant de reference au "prix public" affiche a cote du prix applique.
$maxlevel = getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT');
if ($maxlevel < 1) {
	$maxlevel = 1;
}
$levels = array();
for ($l = 1; $l <= $maxlevel; $l++) {
	$lab = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$l);
	$levels[$l] = $langs->trans('AdvTakeposLevelN', $l).($lab ? ' - '.$lab : '');
}
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposPublicPriceLevel'), $langs->trans('AdvTakeposPublicPriceLevelHelp'));
print '</td><td class="center">';
print $form->selectarray('ADVANCEDTAKEPOS_PUBLIC_PRICE_LEVEL', $levels, getDolGlobalInt('ADVANCEDTAKEPOS_PUBLIC_PRICE_LEVEL', 1), 0);
print '</td></tr>';
// Niveau promo : l'ecart entre le tarif du client et ce niveau, quand il est inferieur, devient une remise.
$promolevels = array(0 => $langs->trans('Disabled')) + $levels;
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposPromoLevel'), $langs->trans('AdvTakeposPromoLevelHelp'));
print '</td><td class="center">';
print $form->selectarray('ADVANCEDTAKEPOS_PROMO_LEVEL', $promolevels, getDolGlobalInt('ADVANCEDTAKEPOS_PROMO_LEVEL'), 0);
print '</td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposAltBarcodes'), $langs->trans('AdvTakeposAltBarcodesHelp'));
print '</td><td class="center"><input type="text" name="ADVANCEDTAKEPOS_ALT_BARCODES" value="'.dol_escape_htmltag(getDolGlobalString('ADVANCEDTAKEPOS_ALT_BARCODES')).'" class="minwidth300" placeholder="pfp,ef:ean_kezia"></td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposGridMobile'), $langs->trans('AdvTakeposGridHelp'));
print '</td><td class="center"><input type="number" min="0" max="60" name="ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE" value="'.getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE').'" class="width75"></td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposGridTablet'), $langs->trans('AdvTakeposGridHelp'));
print '</td><td class="center"><input type="number" min="0" max="60" name="ADVANCEDTAKEPOS_MAXPRODUCT_TABLET" value="'.getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_TABLET').'" class="width75"></td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposDesktopPreset'), $langs->trans('AdvTakeposDesktopPresetHelp'));
print '</td><td class="center">';
$presets = array(0 => $langs->trans('AdvTakeposPresetManual'), 1 => $langs->trans('AdvTakeposPresetA'), 2 => $langs->trans('AdvTakeposPresetB'), 3 => $langs->trans('AdvTakeposPresetC'));
print $form->selectarray('ADVANCEDTAKEPOS_DESKTOP_PRESET', $presets, getDolGlobalInt('ADVANCEDTAKEPOS_DESKTOP_PRESET', 1), 0);
print '</td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposPagerPos'), $langs->trans('AdvTakeposPagerPosHelp'));
print '</td><td class="center">';
$ppos = array(0 => $langs->trans('AdvTakeposPagerFloat'), 1 => $langs->trans('AdvTakeposPagerUnderActions'));
print $form->selectarray('ADVANCEDTAKEPOS_PAGER_POS', $ppos, getDolGlobalInt('ADVANCEDTAKEPOS_PAGER_POS'), 0);
print '</td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposGridDesktop'), $langs->trans('AdvTakeposGridHelp'));
print '</td><td class="center"><input type="number" min="0" max="60" name="ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP" value="'.getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP').'" class="width75"></td></tr>';
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposTileLines'), $langs->trans('AdvTakeposTileLinesHelp'));
print '</td><td class="center"><input type="number" min="2" max="6" name="ADVANCEDTAKEPOS_TILE_LINES" value="'.getDolGlobalInt('ADVANCEDTAKEPOS_TILE_LINES', 4).'" class="width75"></td></tr>';
// Onglet d'ouverture des fiches produit et tiers en popup (libelles des onglets du coeur).
$langs->loadLangs(array('products', 'stocks', 'companies', 'agenda'));
$prodtabs = array('card' => $langs->trans('Card'), 'stock' => $langs->trans('Stock'), 'price' => $langs->trans('SellingPrices'),
	'suppliers' => $langs->trans('BuyingPrices'), 'referers' => $langs->trans('Referers'), 'stats' => $langs->trans('Statistics'),
	'documents' => $langs->trans('Documents'), 'agenda' => $langs->trans('Events'));
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposProductCardTab'), $langs->trans('AdvTakeposProductCardTabHelp'));
print '</td><td class="center">';
print $form->selectarray('ADVANCEDTAKEPOS_PRODUCT_CARD_TAB', $prodtabs, getDolGlobalString('ADVANCEDTAKEPOS_PRODUCT_CARD_TAB', 'card'), 0);
print '</td></tr>';
$soctabs = array('card' => $langs->trans('ThirdParty'), 'customer' => $langs->trans('Customer'), 'contact' => $langs->trans('ContactsAddresses'),
	'consumption' => $langs->trans('Referers'), 'note' => $langs->trans('Notes'), 'documents' => $langs->trans('Documents'), 'agenda' => $langs->trans('Events'));
print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans('AdvTakeposThirdpartyCardTab'), $langs->trans('AdvTakeposThirdpartyCardTabHelp'));
print '</td><td class="center">';
print $form->selectarray('ADVANCEDTAKEPOS_THIRDPARTY_CARD_TAB', $soctabs, getDolGlobalString('ADVANCEDTAKEPOS_THIRDPARTY_CARD_TAB', 'card'), 0);
print '</td></tr>';
// Categorie racine par caisse : le reglage natif TAKEPOS_ROOT_CATEGORY_ID vaut pour toutes les caisses.
for ($t = 1; $t <= max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS')); $t++) {
	$tname = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$t, $langs->trans('TerminalName', $t));
	print '<tr class="oddeven"><td>';
	print $form->textwithpicto($langs->trans('AdvTakeposRootCategory', $tname), $langs->trans('AdvTakeposRootCategoryHelp'));
	print '</td><td class="center">';
	print $form->select_all_categories(Categorie::TYPE_PRODUCT, getDolGlobalInt('ADVANCEDTAKEPOS_ROOT_CATEGORY_ID'.$t), 'ADVANCEDTAKEPOS_ROOT_CATEGORY_ID'.$t, 64, 0, 0, 0, 'maxwidth200');
	print '</td></tr>';
}
print '</table></div>';
print '<div class="center" style="margin:10px 0 18px;"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Parameter').'</td>';
print '<td class="center" width="120">'.$langs->trans('Status').'</td>';
print '</tr>';

foreach ($toggles as $code => $labels) {
	print '<tr class="oddeven"><td>';
	print $form->textwithpicto($langs->trans($labels[0]), $langs->trans($labels[1]));
	print '</td><td class="center">';
	print ajax_constantonoff($code, array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';
}

print '</table>';
print '</div>';

// End of page
llxFooter();
$db->close();
