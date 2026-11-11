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
 * \file    ajax/giftvoucher.php
 * \ingroup advancedtakepos
 * \brief   Bon d'achat scanne dans la fenetre Reglement de TakePOS.
 *          action=check (lecture) : etat du bon et montant applicable a la vente.
 *          action=arm (POST + jeton) : garde le bon en session pour la vente ; le reglement TakePOS qui suit le
 *          rattache au bon (trigger interface_50_modAdvancedTakepos).
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

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
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

global $conf, $db, $user, $langs;

$langs->loadLangs(array('main', 'bills'));
$langs->load('advancedtakepos@advancedtakepos');

top_httphead('application/json');

$out = array('ok' => false, 'error' => '');

if (!isModEnabled('giftvoucher') || getDolGlobalInt('ADVANCEDTAKEPOS_GIFTVOUCHER') != 1 || !dol_include_once('/giftvoucher/class/giftvoucher.class.php')) {
	$out['error'] = 'Disabled';
	echo json_encode($out);
	exit;
}
if (empty($user->id) || !$user->hasRight('takepos', 'run')) {
	$out['error'] = $langs->transnoentitiesnoconv('NotEnoughPermissions');
	echo json_encode($out);
	exit;
}
$langs->load('giftvoucher@giftvoucher');

$action = GETPOST('action', 'aZ09');
$code = trim(GETPOST('code', 'alphanohtml'));
$facid = GETPOSTINT('facid');
$allowexpired = (getDolGlobalInt('ADVANCEDTAKEPOS_GV_ALLOW_EXPIRED') == 1);

if ($action === 'arm' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('token', 'alpha') !== currentToken())) {
	$out['error'] = $langs->transnoentitiesnoconv('ErrorBadValueForParameter', 'token');
	echo json_encode($out);
	exit;
}

// Vente en cours de reglement : une vente TakePOS brouillon ou validee non soldee.
$invoice = new Facture($db);
if ($facid <= 0 || $invoice->fetch($facid) <= 0 || $invoice->module_source !== 'takepos'
	|| !in_array((int) $invoice->status, array(Facture::STATUS_DRAFT, Facture::STATUS_VALIDATED)) || !empty($invoice->paye)
	|| (int) $invoice->type != Facture::TYPE_STANDARD) {
	$out['error'] = $langs->transnoentitiesnoconv('AdvTakeposGvNoSale');
	echo json_encode($out);
	exit;
}
$remain = (float) price2num($invoice->getRemainToPay(), 'MT');

$voucher = new GiftVoucher($db);
if ($code === '' || $voucher->fetch(0, $code) <= 0) {
	$out['state'] = 'unknown';
	$out['message'] = $langs->transnoentitiesnoconv('GiftVoucherNotFound', $code);
	echo json_encode($out);
	exit;
}
$out['ref'] = $voucher->ref;
$out['amount'] = (float) $voucher->amount;
$out['validity'] = $voucher->date_validite ? dol_print_date($voucher->date_validite, 'day') : '';
if ($voucher->status == GiftVoucher::STATUS_USED) {
	$out['state'] = 'used';
	$used = $voucher->date_exerce ? dol_print_date($voucher->date_exerce, 'day') : '?';
	$usedon = '';
	if ($voucher->fk_facture > 0) {
		$f = new Facture($db);
		if ($f->fetch($voucher->fk_facture) > 0) {
			$usedon = $f->ref;
		}
	}
	$out['message'] = $langs->transnoentitiesnoconv('AdvTakeposGvUsed', $used).($usedon !== '' ? ' ('.$usedon.')' : '');
} elseif ($voucher->status == GiftVoucher::STATUS_CANCELED) {
	$out['state'] = 'canceled';
	$out['message'] = $langs->transnoentitiesnoconv('AdvTakeposGvCanceled');
} elseif (!$voucher->isRedeemable()) {
	$out['state'] = 'expired';
	$out['message'] = $langs->transnoentitiesnoconv('AdvTakeposGvExpired', $out['validity']);
	$out['canforce'] = $allowexpired;
} else {
	$out['state'] = 'valid';
}
$usable = ($out['state'] === 'valid' || ($out['state'] === 'expired' && $allowexpired));
$out['apply'] = (float) price2num(min((float) $voucher->amount, $remain), 'MT');
$out['rest'] = (float) price2num(max(0, (float) $voucher->amount - $remain), 'MT');
$out['remain'] = $remain;

if ($action === 'check') {
	$out['ok'] = $usable;
	echo json_encode($out);
	exit;
}

if ($action === 'arm') {
	$force = GETPOSTINT('force');
	if (!$usable || ($out['state'] === 'expired' && !$force) || $out['apply'] <= 0) {
		$out['error'] = !empty($out['message']) ? $out['message'] : $langs->transnoentitiesnoconv('AdvTakeposGvNoSale');
		echo json_encode($out);
		exit;
	}
	if (!isset($_SESSION['advtp_gv']) || !is_array($_SESSION['advtp_gv'])) {
		$_SESSION['advtp_gv'] = array();
	}
	$_SESSION['advtp_gv'][$facid] = array('code' => $voucher->ref, 'time' => dol_now(), 'force' => ($out['state'] === 'expired' ? 1 : 0));
	$out['ok'] = true;
	echo json_encode($out);
	exit;
}

$out['error'] = 'Bad action';
echo json_encode($out);
