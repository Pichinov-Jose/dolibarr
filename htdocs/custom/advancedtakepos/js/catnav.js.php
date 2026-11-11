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
 * \file    js/catnav.js.php
 * \ingroup advancedtakepos
 * \brief   Navigation des categories de la caisse (ADVANCEDTAKEPOS_CATEGORY_BREADCRUMB) : fil d'Ariane au-dessus de la
 *          zone des categories ; une categorie qui a des sous-categories les affiche a la place des categories, la
 *          zone produits ne montre que des produits. Le coeur, lui, met les sous-categories en tete de la zone
 *          produits. Disposition mobile (onglets de categories) : comportement natif.
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

$langs->load('advancedtakepos@advancedtakepos');
$home = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposCrumbHome'));
?>
(function () {
	if (window.__advtpCatNav) { return; }
	window.__advtpCatNav = 1;
	if (window.matchMedia('(max-width:767px)').matches) { return; }
	if (typeof LoadProducts !== 'function' || typeof PrintCategories !== 'function' || typeof categories === 'undefined') { return; }
	var HOME = '<?php echo $home; ?>';
	var MAIN = jQuery.isArray(categories) ? categories.slice() : jQuery.map(categories, function (v) { return v; });
	var byId = {}, kids = {}, isMain = {};
	jQuery.each(MAIN, function (i, v) { if (v && v.rowid !== undefined) { byId[String(v.rowid)] = v; isMain[String(v.rowid)] = 1; } });
	// Les sous-categories du coeur sont deja triees par libelle, comme les categories principales.
	jQuery.each((typeof subcategories !== 'undefined' && subcategories) || {}, function (i, v) {
		if (!v || v.rowid === undefined) { return; }
		byId[String(v.rowid)] = v;
		var p = String(v.fk_parent);
		(kids[p] = kids[p] || []).push(v);
	});
	var path = [], active = null;
	function hasKids(id) { return !!(kids[id] && kids[id].length); }
	// Chemin depuis la categorie principale jusqu'a id (inclus) ; [] au-dessus des categories principales.
	function chain(id) {
		var c = [], guard = 0;
		while (id !== undefined && id !== null && byId[id] && guard++ < 20) {
			c.unshift(id);
			if (isMain[id]) { break; }
			id = String(byId[id].fk_parent);
		}
		return c;
	}
	var origLP = window.LoadProducts;
	// Un seul chargement de la zone produits a la fois : le coeur ne verifie pas, a la reponse, que la categorie (ou
	// la recherche) est toujours celle demandee. Une reponse lente arrivait apres la suivante et la recouvrait.
	jQuery.ajaxPrefilter(function (opts, orig, xhr) {
		var u = String(opts.url || '');
		if (u.indexOf('/takepos/ajax/ajax.php') < 0 || (u.indexOf('action=getProducts') < 0 && u.indexOf('action=search') < 0)) { return; }
		if (window.__advtpZoneXhr && window.__advtpZoneXhr.readyState !== 4) { window.__advtpZoneXhr.abort(); }
		window.__advtpZoneXhr = xhr;
	});
	var bar = jQuery('<div id="advtpCrumb" class="advtp-crumb"></div>');
	jQuery('.div4').first().prepend(bar).addClass('advtp-hascrumb');

	function seg(text, k, cls) {
		var s = jQuery('<span class="advtp-crumb-seg"></span>').text(text);
		if (k !== null) { s.attr('data-k', k); }
		if (cls) { s.addClass(cls); }
		return s;
	}
	function renderCrumb() {
		bar.empty();
		var h = seg(HOME, -1, path.length ? '' : 'advtp-crumb-cur');
		h.prepend('<span class="fa fa-home"></span> ');
		bar.append(h);
		jQuery.each(path, function (k, id) {
			bar.append('<span class="advtp-crumb-sep fa fa-chevron-right"></span>');
			bar.append(seg(byId[id] ? byId[id].label : id, k, k === path.length - 1 ? 'advtp-crumb-cur' : ''));
		});
		if (active !== null && !hasKids(active) && byId[active]) {
			bar.append('<span class="advtp-crumb-sep fa fa-chevron-right"></span>');
			bar.append(seg(byId[active].label, null, 'advtp-crumb-cur advtp-crumb-leaf'));
		}
	}
	function markActive() {
		jQuery('.div4 [id^=catdiv]').each(function () {
			if (!/^catdiv\d+$/.test(this.id)) { return; }
			jQuery(this).toggleClass('advtp-catactive', active !== null && String(jQuery(this).data('rowid')) === String(active));
		});
	}
	function showLevel() {
		categories = path.length ? (kids[path[path.length - 1]] || []) : MAIN;
		pagecategories = 0;
		PrintCategories(0);
		renderCrumb();
		markActive();
	}
	// Produits de la categorie, sans ses sous-categories : le coeur lit les sous-categories dans la variable globale.
	function loadOf(id) {
		var saved = subcategories;
		subcategories = [];
		try {
			var pos = -1;
			jQuery('.div4 [id^=catdiv]').each(function () {
				if (/^catdiv\d+$/.test(this.id) && String(jQuery(this).data('rowid')) === String(id)) { pos = parseInt(this.id.replace('catdiv', ''), 10); return false; }
			});
			if (pos >= 0) { origLP(pos, false); } else { jQuery('#prodiv0').data('rowid', id); origLP(0, true); }
		} finally {
			subcategories = saved;
		}
	}
	window.LoadProducts = function (position, issubcat) {
		if (position === 'supplements') { return origLP.apply(this, arguments); }
		var id = issubcat ? jQuery('#prodiv' + position).data('rowid') : jQuery('#catdiv' + position).data('rowid');
		if (id === undefined || id === null || id === '' || !byId[String(id)]) { return origLP.apply(this, arguments); }
		id = String(id);
		var want = hasKids(id) ? chain(id) : chain(String(byId[id].fk_parent));
		active = id;
		if (want.join('/') !== path.join('/')) { path = want; showLevel(); } else { renderCrumb(); markActive(); }
		loadOf(id);
	};
	if (typeof MoreCategories === 'function') {
		var origMC = window.MoreCategories;
		window.MoreCategories = function () { var r = origMC.apply(this, arguments); markActive(); return r; };
	}
	// Le curseur revient dans la recherche (douchette) : le coeur ne le fait qu'a la fin d'un chargement de produits,
	// et « Accueil » n'en charge aucun.
	function refocus() { if (typeof setFocusOnSearchField === 'function') { setFocusOnSearchField(); } }
	bar.on('click', '.advtp-crumb-seg', function () {
		var k = parseInt(jQuery(this).attr('data-k'), 10);
		if (isNaN(k)) { refocus(); return; }
		if (k < 0) { path = []; active = null; showLevel(); refocus(); return; }
		var id = path[k];
		path = path.slice(0, k + 1);
		active = id;
		showLevel();
		loadOf(id);
		refocus();
	});
	// Au chargement, le coeur a deja affiche la premiere categorie, sous-categories comprises : on n'en garde que
	// les produits, la zone des categories reste sur les categories principales.
	// Le script peut arriver pendant ce premier chargement du coeur (envoye avant notre filtre ajax) : on attend qu'il
	// soit fini, sinon sa reponse, sous-categories comprises, recouvrirait la notre.
	function whenIdle(fn) {
		var n = 0;
		(function wait() { if (jQuery.active <= 1 || n++ > 100) { fn(); } else { setTimeout(wait, 50); } })();
	}
	renderCrumb();
	if (typeof currentcat !== 'undefined' && currentcat !== undefined && byId[String(currentcat)]) {
		active = String(currentcat);
		markActive();
		renderCrumb();
		whenIdle(function () { if (String(currentcat) === active) { loadOf(active); } });
	}
})();
