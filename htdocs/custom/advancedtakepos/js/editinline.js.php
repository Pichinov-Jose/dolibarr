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
 * \file    js/editinline.js.php
 * \ingroup advancedtakepos
 * \brief   Mode saisie 1 : clavier numerique natif conserve, mais la valeur en cours de saisie
 *          s'affiche dans un badge stylise ancre sur la ligne selectionnee (le natif ecrit du
 *          texte brut dans la 1re cellule : illisible).
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
?>
(function () {
	if (window.__advtpEditInlineLoaded) { return; }
	window.__advtpEditInlineLoaded = 1;

	function badge() {
		var b = document.getElementById('advtpInline');
		if (!b) {
			jQuery('body').append('<div id="advtpInline"><span id="advtpInlineLabel"></span><b id="advtpInlineVal"></b></div>');
			b = document.getElementById('advtpInline');
		}
		return b;
	}
	function hide() { jQuery('#advtpInline').hide(); }
	function place(rowid) {
		var row = document.getElementById(String(rowid));
		if (!row) { hide(); return; }
		var r = row.getBoundingClientRect();
		jQuery('#advtpInline').css({ top: (window.scrollY + r.top + r.height / 2) + 'px', left: (window.scrollX + r.right - 14) + 'px' }).show();
	}

	// Apres chaque passage du Edit natif : si une saisie est en cours, on retire le texte brut que le
	// coeur a ecrit dans la 1re cellule et on affiche la valeur dans le badge ancre sur la ligne.
	var nat = window.Edit;
	window.Edit = function (k) {
		var r = (typeof nat === 'function') ? nat(k) : undefined;
		try {
			if (typeof editaction !== 'undefined' && editaction !== '' && typeof selectedline !== 'undefined' && selectedline && typeof editnumber !== 'undefined') {
				var td = jQuery('#' + selectedline).find('td:first');
				var h = td.html() || '';
				var m = h.match(/^([\s\S]*?)<br>\s*([^:<]+):\s*([0-9.]*)$/);
				if (m) {
					td.html(m[1]);
					badge();
					jQuery('#advtpInlineLabel').text(m[2].replace(/^.*?(?:->|-&gt;)\s*/, '').trim());
					jQuery('#advtpInlineVal').text(m[3] === '' ? '…' : m[3]);
					place(selectedline);
				}
				if (k === 'c') { hide(); }
			} else {
				hide();
			}
		} catch (e) { /* jamais bloquer la caisse */ }
		return r;
	};

	// La validation recharge #poslines : le badge disparait avec elle.
	jQuery(document).ajaxComplete(function (e, x, st) {
		if (st && st.url && st.url.indexOf('invoice.php') >= 0) { hide(); }
	});
})();
