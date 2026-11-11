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
 * \file    ajax/cancelpayment.php
 * \ingroup advancedtakepos
 * \brief   Annule (supprime) le dernier reglement d'une vente TakePOS pas encore soldee, depuis la fenetre
 *          Reglement. Memes controles que l'affichage du bouton (ActionsAdvancedTakepos::checkCancelLastPayment).
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
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
dol_include_once('/advancedtakepos/class/actions_advancedtakepos.class.php');

global $conf, $db, $user, $langs;

$langs->loadLangs(array('main', 'bills'));
$langs->load('advancedtakepos@advancedtakepos');

top_httphead('application/json');

$out = array('ok' => false, 'error' => '');

// Ecriture : POST et jeton de la fenetre Reglement seulement.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('token', 'alpha') !== currentToken()) {
	$out['error'] = $langs->trans('ErrorBadValueForParameter', 'token');
	echo json_encode($out);
	exit;
}

$facid = GETPOSTINT('facid');
$paymentid = GETPOSTINT('paymentid');
$invoice = new Facture($db);
if ($facid <= 0 || $invoice->fetch($facid) <= 0) {
	$out['error'] = $langs->trans('ErrorRecordNotFound');
	echo json_encode($out);
	exit;
}

$check = ActionsAdvancedTakepos::checkCancelLastPayment($db, $user, $invoice);
if (!$check['ok']) {
	$out['error'] = $check['error'];
	echo json_encode($out);
	exit;
}
// Le reglement vise doit etre encore le dernier de la vente (fenetre restee ouverte pendant un autre encaissement).
if ($paymentid != $check['paymentid']) {
	$out['error'] = $langs->trans('AdvTakeposPayCancelChanged');
	echo json_encode($out);
	exit;
}

$payment = new Paiement($db);
if ($payment->fetch($paymentid) <= 0) {
	$out['error'] = $langs->trans('ErrorRecordNotFound');
	echo json_encode($out);
	exit;
}

$db->begin();
$result = $payment->delete($user);
if ($result > 0) {
	$db->commit();
	dol_syslog('advancedtakepos: payment '.$paymentid.' of invoice '.$invoice->ref.' cancelled from POS by '.$user->login, LOG_NOTICE);
	$out['ok'] = true;
} else {
	$db->rollback();
	$out['error'] = $payment->error ? $langs->trans($payment->error) : 'Delete failed';
}

echo json_encode($out);
