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
 * \file    ajax/sendchoice.php
 * \ingroup advancedtakepos
 * \brief   Fenetre Envoyer (popup TakePOS) : choix entre la facture en PDF, par le formulaire d'envoi
 *          de la fiche facture, et le ticket dans le corps de l'e-mail, par l'envoi natif takepos/send.php.
 *          Seulement pour une vente validee (reglee) : un brouillon n'a ni numero ni PDF.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
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

$langs->loadLangs(array('main', 'bills', 'cashdesk', 'mails'));
$langs->load('advancedtakepos@advancedtakepos');

if (empty($user->id) || (!$user->hasRight('takepos', 'run') && empty($user->admin))) {
	accessforbidden();
}

$facid = GETPOSTINT('facid');
$invoice = new Facture($db);
$found = ($facid > 0 && $invoice->fetch($facid) > 0);
if ($found) {
	$invoice->fetch_thirdparty();
}

top_htmlhead('', '', 0, 0, array(), array('/takepos/css/pos.css.php'));

print '<body class="center" style="padding:12px;">';
if (!$found) {
	print '<p>'.$langs->trans('ErrorRecordNotFound').'</p>';
} elseif ((int) $invoice->statut == Facture::STATUS_DRAFT) {
	// Brouillon : pas encore de numero ni de PDF, et le ticket ne serait pas definitif.
	print '<p style="font-size:1.2em;">'.$langs->trans('AdvTakeposSendNeedValidated').'</p>';
} else {
	$email = is_object($invoice->thirdparty) ? $invoice->thirdparty->email : '';
	print '<p style="font-size:1.1em;"><b>'.dol_escape_htmltag($invoice->ref).'</b>';
	if (is_object($invoice->thirdparty)) {
		print ' &ndash; '.dol_escape_htmltag($invoice->thirdparty->name);
	}
	print '<br><span class="opacitymedium">'.($email ? dol_escape_htmltag($email) : $langs->trans('AdvTakeposSendNoEmail')).'</span></p>';
	if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
		print '<p class="warning">'.$langs->trans('AdvTakeposSendMailsDisabled').'</p>';
	}
	// Le style calcbutton de pos.css ne vise que les <button> : liens peints au bleu des boutons TakePOS, a la taille du tactile.
	$btnstyle = 'display:inline-block;padding:18px 24px;min-width:220px;font-size:1.2em;text-decoration:none;background:#0066AA;color:#fff;border-radius:6px;';
	print '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:12px;">';
	if ($user->hasRight('facture', 'lire')) {
		// Formulaire d'envoi standard de la fiche facture : modele d'e-mail, destinataires, PDF genere et joint.
		// dol_openinpopup : masque les menus sans les memoriser en session pour la suite de la navigation.
		// Le modele choisi pour la caisse (TAKEPOS_EMAIL_TEMPLATE_INVOICE) est preselectionne, sinon le formulaire part du texte standard.
		$tplid = getDolGlobalInt('TAKEPOS_EMAIL_TEMPLATE_INVOICE');
		$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $invoice->id).'&action=presend&mode=init'.($tplid > 0 ? '&modelmailselected='.$tplid : '').'&dol_hide_topmenu=1&dol_hide_leftmenu=1&dol_openinpopup=advtpsend#formmailbeforetitle';
		print '<a style="'.$btnstyle.'" href="'.$url.'">';
		print '<span class="fa fa-file-pdf paddingrightonly"></span>'.$langs->trans('AdvTakeposSendInvoicePdf').'</a>';
	}
	// Envoi natif du ticket (corps de l'e-mail), le meme que le bouton Envoyer le ticket de TakePOS.
	print '<a style="'.$btnstyle.'" href="'.DOL_URL_ROOT.'/takepos/send.php?facid='.((int) $invoice->id).'">';
	print '<span class="fa fa-receipt paddingrightonly"></span>'.$langs->trans('AdvTakeposSendTicket').'</a>';
	print '</div>';
}
print '</body></html>';
