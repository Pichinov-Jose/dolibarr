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
	$gm = GETPOSTINT('ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE');
	$gt = GETPOSTINT('ADVANCEDTAKEPOS_MAXPRODUCT_TABLET');
	$gd = GETPOSTINT('ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP');
	$err = 0;
	if (!in_array($mode, array(0, 1, 2)) || !in_array($imode, array(0, 1, 2, 3))) {
		$err++;
	}
	if (($gm != 0 && ($gm < 6 || $gm > 60)) || ($gt != 0 && ($gt < 6 || $gt > 60)) || ($gd != 0 && ($gd < 6 || $gd > 60))) {
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
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE', $gm, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_TABLET', $gt, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP', $gd, 'chaine', 0, '', $conf->entity);
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
	'ADVANCEDTAKEPOS_HIDE_TERMINAL_DATE'  => array('AdvTakeposHideTerminalDate', 'AdvTakeposHideTerminalDateHelp'),
	'ADVANCEDTAKEPOS_HIDE_CURRENCY'       => array('AdvTakeposHideCurrency', 'AdvTakeposHideCurrencyHelp'),
	'ADVANCEDTAKEPOS_CART_CUSTOMER'       => array('AdvTakeposCartCustomer', 'AdvTakeposCartCustomerHelp'),
	'ADVANCEDTAKEPOS_DELETE_SALE'         => array('AdvTakeposDeleteSaleOpt', 'AdvTakeposDeleteSaleOptHelp'),
	'ADVANCEDTAKEPOS_COMPACT_ACTIONS'     => array('AdvTakeposCompactActions', 'AdvTakeposCompactActionsHelp'),
	'ADVANCEDTAKEPOS_LIGHT_HEADER'        => array('AdvTakeposLightHeader', 'AdvTakeposLightHeaderHelp'),
	'ADVANCEDTAKEPOS_PAGER'               => array('AdvTakeposPager', 'AdvTakeposPagerHelp'),
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
