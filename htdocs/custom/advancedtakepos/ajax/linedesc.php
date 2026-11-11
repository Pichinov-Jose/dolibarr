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
 * \file    ajax/linedesc.php
 * \ingroup advancedtakepos
 * \brief   Renvoie la description d'une ligne du panier courant, pour pre-remplir la fenetre Description.
 *          L'enregistrement passe par l'action native takepos/invoice.php?action=addnote.
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

// Droits : meme exigence que l'action native addnote.
if (empty($user->id) || (!$user->hasRight('takepos', 'run') && empty($user->admin))) {
	$out['error'] = 'Forbidden';
	echo json_encode($out);
	exit;
}

$place = GETPOST('place', 'alpha');
$idline = GETPOSTINT('idline');
$term = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';
if ($place === '' || $term === '' || $idline <= 0) {
	$out['error'] = 'Missing place/terminal/line';
	echo json_encode($out);
	exit;
}

// Meme facture que celle que modifiera invoice.php : le brouillon (PROV-POS<terminal>-<place>).
$invoice = new Facture($db);
if ($invoice->fetch(0, '(PROV-POS'.$term.'-'.$place.')') <= 0 || (int) $invoice->statut != Facture::STATUS_DRAFT) {
	$out['error'] = 'No draft sale';
	echo json_encode($out);
	exit;
}
foreach ($invoice->lines as $line) {
	if ((int) $line->id == $idline) {
		$out['ok'] = true;
		$out['label'] = (string) ($line->product_label ? $line->product_label : '');
		// Description de la ligne, sauf quand elle reprend le libelle (ligne libre) : le champ part vide.
		$out['desc'] = ($line->desc !== $line->product_label) ? (string) $line->desc : '';
		break;
	}
}
if (!$out['ok']) {
	$out['error'] = 'Line not found';
}

echo json_encode($out);
