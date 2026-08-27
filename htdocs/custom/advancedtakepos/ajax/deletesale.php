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
 * \file    ajax/deletesale.php
 * \ingroup advancedtakepos
 * \brief   Supprime VRAIMENT le brouillon de facture POS du panier courant (l'onglet disparait),
 *          la ou l'action native TakePOS action=delete se contente de le vider.
 */

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

global $conf, $db, $user;

top_httphead('application/json');

$out = array('ok' => false, 'error' => '');

// Droits : meme exigence que les autres actions TakePOS.
if (empty($user->id) || (!$user->hasRight('takepos', 'run') && empty($user->admin))) {
	$out['error'] = 'Forbidden';
	echo json_encode($out);
	exit;
}

$place = GETPOST('place', 'alpha');
$term = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';
if ($place === '' || $term === '') {
	$out['error'] = 'Missing place/terminal';
	echo json_encode($out);
	exit;
}

// La facture POS du panier a pour reference (PROV-POS<terminal>-<place>).
$ref = '(PROV-POS'.$term.'-'.$place.')';

$invoice = new Facture($db);
$r = $invoice->fetch(0, $ref);
if ($r <= 0) {
	// Deja absente : rien a supprimer, mais du point de vue de l'utilisateur c'est un succes.
	$out['ok'] = true;
	$out['note'] = 'already-absent';
	echo json_encode($out);
	exit;
}

// Securite : on ne supprime qu'un BROUILLON (jamais une facture validee/payee).
if ((int) $invoice->statut != Facture::STATUS_DRAFT) {
	$out['error'] = 'Not a draft';
	echo json_encode($out);
	exit;
}

$db->begin();
$resdel = $invoice->delete($user);
if ($resdel > 0) {
	$db->commit();
	$out['ok'] = true;
} else {
	$db->rollback();
	$out['error'] = $invoice->error ? $invoice->error : 'Delete failed';
}

echo json_encode($out);
