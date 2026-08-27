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
 * \file    js/editpopup.js.php
 * \ingroup advancedtakepos
 * \brief   Popup de saisie Quantite / Prix / Remise ligne pour TakePOS, sur le pattern de la
 *          modale vendeur : voile sombre, carte blanche centree, gros boutons. Remplace la
 *          saisie native qui ecrit la valeur dans la ligne selectionnee.
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

$langs->loadLangs(array('main', 'bills', 'cashdesk'));

$labels = array(
	'qty' => dol_escape_js($langs->transnoentitiesnoconv('Qty')),
	'p'   => dol_escape_js($langs->transnoentitiesnoconv('Price')),
	'r'   => dol_escape_js($langs->transnoentitiesnoconv('LineDiscountShort')).' (%)',
);
$hdr = array(
	'qty' => dol_escape_js($langs->transnoentitiesnoconv('Qty')),
	'r'   => dol_escape_js($langs->transnoentitiesnoconv('ReductionShort')),
	'ttc' => dol_escape_js($langs->transnoentitiesnoconv('TotalTTCShort')),
);
$ok = dol_escape_js($langs->transnoentitiesnoconv('Validate'));
$cancel = dol_escape_js($langs->transnoentitiesnoconv('Cancel'));
$token = newToken();
$pdmode = getDolGlobalInt('ADVANCEDTAKEPOS_PRICE_DISCOUNT_MODE', 1);
$uplineurl = dol_buildpath('/advancedtakepos/ajax/updatepriceline.php', 1);
?>
(function () {
	if (window.__advtpEditPopupLoaded) { return; }
	window.__advtpEditPopupLoaded = 1;

	var LABELS = { qty: '<?php echo $labels['qty']; ?>', p: '<?php echo $labels['p']; ?>', r: '<?php echo $labels['r']; ?>' };
	var ACTIONS = { qty: 'updateqty', p: 'updateprice', r: 'updatereduction' };
	var TOKEN = '<?php echo $token; ?>';
	var HDR = { qty: '<?php echo $hdr['qty']; ?>', r: '<?php echo $hdr['r']; ?>', ttc: '<?php echo $hdr['ttc']; ?>' };
	var PDMODE = <?php echo (int) $pdmode; ?>;
	var UPLINE = '<?php echo $uplineurl; ?>'; // 0=garder la remise (natif), 1=remise a zero, 2=convertir en remise
	var open = false, kind = '', value = '', fresh = false, openR = 0, openUnit = 0;

	// --- Modale (pattern vendeur : voile + carte blanche centree) ---
	var html = '';
	html += '<div id="advtpEdit" class="advtp-overlay">';
	html += '<div class="advtp-card">';
	html += '<h3 id="advtpEditTitle" style="margin-top:0;"></h3>';
	html += '<div id="advtpEditLine" class="opacitymedium" style="margin-bottom:6px;"></div>';
	html += '<div id="advtpEditVal" class="advtp-display">&nbsp;</div>';
	html += '<div id="advtpEditHint" class="opacitymedium" style="font-size:.85em;margin-bottom:6px;min-height:1em;"></div>';
	html += '<div>';
	var rows = [['7','8','9'],['4','5','6'],['1','2','3'],['0','.','⌫']];
	for (var r = 0; r < rows.length; r++) {
		for (var c = 0; c < 3; c++) {
			html += '<button type="button" class="advtp-num" data-k="'+rows[r][c]+'">'+rows[r][c]+'</button>';
		}
		html += '<br>';
	}
	html += '</div>';
	html += '<div style="margin-top:12px;">';
	html += '<button type="button" id="advtpEditOk" class="advtp-num advtp-ok"><?php echo $ok; ?></button>';
	html += '<button type="button" id="advtpEditCancel" class="advtp-num"><?php echo $cancel; ?></button>';
	html += '</div>';
	html += '</div></div>';
	jQuery('body').append(html);

	function cellIdx(label) {
		var idx = -1;
		jQuery('#poslines table tr:first td').each(function (i) { if (idx < 0 && jQuery(this).text().indexOf(label) >= 0) { idx = i; } });
		return idx;
	}
	function num(s) {
		var m = String(s).replace(/\u00a0/g, ' ').replace(/,/g, '.').match(/-?\d+(\.\d+)?/);
		return m ? parseFloat(m[0]) : null;
	}
	function currentVal(k) {
		var row = document.getElementById(String(selectedline));
		if (!row || !row.cells) { return ''; }
		var tds = row.cells;
		function at(label) { var i = cellIdx(label); return (i >= 0 && tds[i]) ? num(tds[i].textContent) : null; }
		if (k === 'qty') { var v = at(HDR.qty); return v === null ? '' : String(v); }
		if (k === 'r') { var v = at(HDR.r); return v === null ? '' : String(v); }
		if (k === 'p') { var tot = at(HDR.ttc), q = at(HDR.qty); if (tot === null) { return ''; } if (q) { return String(Math.round((tot / q) * 100) / 100); } return String(tot); }
		return '';
	}
	function show(k) {
		kind = k; open = true;
		openR = parseFloat(currentVal('r')) || 0;
		openUnit = parseFloat(currentVal('p')) || 0;
		value = currentVal(k); fresh = (value !== '');
		window.__advtpDbg = { PDMODE: PDMODE, openR: openR, openUnit: openUnit, kind: k };
		jQuery('#advtpEditTitle').text(LABELS[k]);
		var lt = (typeof selectedtext !== 'undefined') ? String(selectedtext).replace(/<[^>]*>/g, '') : '';
		jQuery('#advtpEditLine').text(lt);
		jQuery('#advtpEditHint').text(k === 'r' ? '100 % = gratuit' : '');
		paint();
		jQuery('#advtpEdit').css('display', 'flex');
	}
	function close() {
		open = false; value = '';
		jQuery('#advtpEdit').hide();
	}
	function paint() {
		jQuery('#advtpEditVal').text(value === '' ? ' ' : value + (kind === 'r' ? ' %' : ''));
	}
	function append(k) {
		if (k === '⌫') { value = value.slice(0, -1); fresh = false; paint(); return; }
		if (fresh && (k === '.' || k === ',' || /^[0-9]$/.test(k))) { value = ''; fresh = false; }
		if (k === '.' || k === ',') {
			if (value.indexOf('.') >= 0) { return; }
			value = (value === '' ? '0' : value) + '.';
			paint(); return;
		}
		if (/^[0-9]$/.test(k)) {
			value += k;
			// Remise : jamais plus de 100 % (100 = gratuit)
			if (kind === 'r' && parseFloat(value) > 100) { value = '100'; }
			paint();
		}
	}
	function freshToken() {
		// Le jeton CSRF tourne a chaque requete : on prend toujours le plus recent, present dans le
		// fragment #poslines rendu par le dernier appel (URLs du module), sinon celui du chargement.
		var m = (jQuery('#poslines').html() || '').match(/token=([a-f0-9]{32,})/);
		return m ? m[1] : TOKEN;
	}
	function urlFor(k, val) {
		return 'invoice.php?action=' + ACTIONS[k] + '&token=' + freshToken() + '&place=' + place + '&idline=' + selectedline + '&number=' + encodeURIComponent(val);
	}
	function validate() {
		if (value === '' || typeof selectedline === 'undefined' || !selectedline) { close(); return; }
		if (kind === 'p' && PDMODE === 2) {
			// Convertir : le prix catalogue reste, le prix saisi devient un % de remise.
			// catalogue = unitaire actuel net de remise / (1 - r/100) ; indeterminable si r=100.
			var catal = (openR < 100 && openUnit > 0) ? (openUnit / (1 - openR / 100)) : 0;
			if (catal > 0) {
				var nr = Math.min(100, Math.max(0, Math.round((1 - parseFloat(value) / catal) * 10000) / 100));
				jQuery('#poslines').load(urlFor('r', String(nr)), function () { close(); });
				return;
			}
			// catalogue inconnu : repli sur le mode 1.
		}
		if (kind === 'p' && PDMODE >= 1 && openR > 0) {
			// Remise a zero : UNE seule requete (prix + remise ensemble), puis re-rendu natif.
			jQuery.getJSON(UPLINE + '?token=' + freshToken() + '&place=' + place + '&idline=' + selectedline + '&number=' + encodeURIComponent(value) + '&remise=0', function (dd) {
				if (dd && dd.ok) { if (typeof Refresh === 'function') { Refresh(); } close(); }
				else { alert((dd && dd.error) ? dd.error : 'Error'); }
			});
			return;
		}
		jQuery('#poslines').load(urlFor(kind, value), function () { close(); });
	}

	jQuery(document).on('click', '#advtpEdit .advtp-num[data-k]', function () { append(jQuery(this).data('k')); });
	jQuery(document).on('click', '#advtpEditOk', validate);
	jQuery(document).on('click', '#advtpEditCancel', close);
	jQuery(document).on('click', '#advtpEdit', function (e) { if (e.target === this) { close(); } });

	// --- Confirmation stylee generique (remplace le confirm() natif, pattern vendeur) ---
	window.advtpConfirm = function (msg, onok) {
		if (!document.getElementById('advtpConfirmBox')) {
			jQuery('body').append('<div id="advtpConfirmBox" class="advtp-overlay"><div class="advtp-card"><div id="advtpConfirmMsg" style="margin:6px 0 16px;font-size:1.1em;"></div><div><button type="button" id="advtpConfirmOk" class="advtp-num advtp-ok"><?php echo $ok; ?></button><button type="button" id="advtpConfirmCancel" class="advtp-num"><?php echo $cancel; ?></button></div></div></div>');
			jQuery(document).on('click', '#advtpConfirmCancel', function () { jQuery('#advtpConfirmBox').hide(); });
			jQuery(document).on('click', '#advtpConfirmBox', function (e) { if (e.target === this) { jQuery(this).hide(); } });
		}
		jQuery('#advtpConfirmMsg').text(msg);
		jQuery('#advtpConfirmOk').off('click').on('click', function () { jQuery('#advtpConfirmBox').hide(); if (onok) { onok(); } });
		jQuery('#advtpConfirmBox').css('display', 'flex');
	};

	// --- Surcharge de Edit() : qty/p/r ouvrent la modale ; pendant qu'elle est ouverte,
	//     le pave numerique principal alimente aussi la saisie. Le reste part au natif. ---
	var nativeEdit = window.Edit;
	window.Edit = function (k) {
		if (open) {
			if (k === 'c') { close(); return; }
			if (k === 'qty' || k === 'p' || k === 'r') { validate(); return; } // OK depuis le pave
			append(String(k));
			return;
		}
		if (k === 'qty' || k === 'p' || k === 'r') {
			if (typeof selectedtext === 'undefined' || typeof selectedline === 'undefined' || !selectedline) {
				return; // pas de ligne selectionnee (comportement natif)
			}
			show(k);
			return;
		}
		if (typeof nativeEdit === 'function') { return nativeEdit(k); }
	};
})();
