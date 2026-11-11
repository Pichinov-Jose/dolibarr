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
 * \file    js/linedesc.js.php
 * \ingroup advancedtakepos
 * \brief   Fenetre Description de la ligne selectionnee (texte libre enregistre sur la ligne de facture,
 *          comme sur une commande). Lecture par ajax/linedesc.php, enregistrement par l'action native
 *          takepos/invoice.php?action=addnote, dont le bouton n'existe qu'en mode restaurant.
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

top_httphead('application/javascript');

$langs->loadLangs(array('main', 'cashdesk'));
$langs->load('advancedtakepos@advancedtakepos');
$title = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposLineDescTitle'));
$hint = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposLineDescHint'));
$nosel = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposLineDescNoLine'));
$ok = dol_escape_js($langs->transnoentitiesnoconv('Validate'));
$cancel = dol_escape_js($langs->transnoentitiesnoconv('Cancel'));
$token = newToken();
$geturl = dol_buildpath('/advancedtakepos/ajax/linedesc.php', 1);
?>
(function () {
	if (window.__advtpLineDescLoaded) { return; }
	window.__advtpLineDescLoaded = 1;

	var TOKEN = '<?php echo $token; ?>';
	var GETURL = '<?php echo $geturl; ?>';
	var html = '';
	html += '<div id="advtpDesc" class="advtp-overlay">';
	html += '<div class="advtp-card" style="max-width:560px;text-align:left;">';
	html += '<h3 style="margin-top:0;text-align:center;"><?php echo $title; ?></h3>';
	html += '<div id="advtpDescLine" class="opacitymedium" style="margin-bottom:6px;text-align:center;"></div>';
	html += '<textarea id="advtpDescTxt" rows="5" style="width:100%;box-sizing:border-box;font-size:1.1em;padding:8px;"></textarea>';
	html += '<div class="opacitymedium" style="font-size:.85em;margin:4px 0 10px;"><?php echo $hint; ?></div>';
	html += '<div style="text-align:center;white-space:nowrap;">';
	html += '<button type="button" id="advtpDescOk" class="advtp-num advtp-ok"><?php echo $ok; ?></button>';
	html += '<button type="button" id="advtpDescCancel" class="advtp-num"><?php echo $cancel; ?></button>';
	html += '</div>';
	html += '</div></div>';
	jQuery('body').append(html);

	function freshToken() {
		// Le jeton CSRF tourne : le plus recent est dans le fragment #poslines du dernier rendu.
		var m = (jQuery('#poslines').html() || '').match(/token=([a-f0-9]{32,})/);
		return m ? m[1] : TOKEN;
	}
	function close() { jQuery('#advtpDesc').hide(); }

	window.advtpEditDesc = function () {
		if (typeof selectedline === 'undefined' || !selectedline || typeof place === 'undefined') {
			if (typeof window.advtpConfirm === 'function') { window.advtpConfirm('<?php echo $nosel; ?>'); } else { alert('<?php echo $nosel; ?>'); }
			return;
		}
		jQuery.getJSON(GETURL + '?place=' + encodeURIComponent(place) + '&idline=' + selectedline, function (d) {
			if (!d || !d.ok) { alert((d && d.error) ? d.error : 'Error'); return; }
			jQuery('#advtpDescLine').text(d.label || '');
			jQuery('#advtpDescTxt').val(d.desc || '');
			jQuery('#advtpDesc').css('display', 'flex');
			var el = document.getElementById('advtpDescTxt');
			el.focus(); el.setSelectionRange(el.value.length, el.value.length);
		});
	};
	jQuery(document).on('click', '#advtpDescOk', function () {
		var txt = jQuery('#advtpDescTxt').val();
		jQuery('#poslines').load('invoice.php?action=addnote&token=' + freshToken() + '&place=' + encodeURIComponent(place) + '&idline=' + selectedline, { addnote: txt }, function () { close(); });
	});
	jQuery(document).on('click', '#advtpDescCancel', close);
	jQuery(document).on('click', '#advtpDesc', function (e) { if (e.target === this) { close(); } });
	// Echap ferme ; Entree garde son role de retour a la ligne dans la zone de texte.
	jQuery(document).on('keydown', '#advtpDescTxt', function (e) { if (e.key === 'Escape') { e.preventDefault(); close(); } });
})();
