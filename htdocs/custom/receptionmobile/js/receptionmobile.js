/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile (module receptionmobile) : script COMMUN.
 * - pose sur le body les classes de la page prise en charge (rm-on, rm-obj-<objet>, rm-page-card|list|tab, rm-doc),
 *   d'après l'adresse : reception, order, supplierorder, invoice, supplierinvoice, shipment, product ;
 * - documents à lignes (rm-doc) : libellé de colonne sur chaque cellule de #tablelines (data-rm-label), ligne de
 *   saisie marquée rm-createrow, sous 600 px éditeur de description replié et bouton « Lignes » ;
 * - listes : une fiche par ligne (libellés, cellule principale rm-main, filtres derrière un bouton) ;
 * - popups contenant une page : plein écran ; réceptions : aides au scan.
 * Fonctions exposées dans window.PichinovMobile pour les scripts par objet (js/rm-<objet>.js).
 * Chargé sur toutes les pages par Dolibarr (module_parts js) : ne fait rien hors des pages prises en charge.
 */
(function () {
	'use strict';

	var MAXWIDTH = 600;
	var en = ((document.documentElement.getAttribute('lang') || 'fr').substring(0, 2) === 'en');
	var T = {
		desc: en ? '+ Description' : '+ Description',
		deschide: en ? '- Hide the description' : '- Masquer la description',
		lines: en ? 'Lines' : 'Lignes',
		filters: en ? 'Filters' : 'Filtres',
		filtershide: en ? 'Hide the filters' : 'Masquer les filtres'
	};

	function small() {
		return !!(window.matchMedia && window.matchMedia('(max-width: ' + MAXWIDTH + 'px)').matches);
	}

	function text(el) {
		return (el.textContent || '').replace(/\s+/g, ' ').trim();
	}

	/* Label of a column title: its visible text only, never the column selector, a script or a form control */
	function titleText(cell) {
		if (cell.querySelector('script, select, input, .dropdown, dl, ul')) {
			return '';
		}
		var label = text(cell);
		return (label.length <= 40 ? label : '');
	}

	/* ---------- Page prise en charge ---------- */

	// The supplier pages come first: /fourn/commande/card.php also ends with /commande/card.php
	var OBJECTS = [
		{key: 'supplierorder', re: /\/fourn\/commande\/(card|list|dispatch|contact|note|document|info|agenda|messaging)\.php$/, doc: true, link: '/fourn/commande/card.php'},
		{key: 'supplierinvoice', re: /\/fourn\/facture\/(card|list|contact|note|document|info|paiement|agenda|messaging)\.php$/, doc: true, link: '/fourn/facture/card.php'},
		{key: 'order', re: /\/commande\/(card|list|contact|note|document|info|agenda|messaging)\.php$/, doc: true, link: '/commande/card.php'},
		{key: 'invoice', re: /\/compta\/facture\/(card|list|contact|note|document|info|agenda|messaging)\.php$/, doc: true, link: '/compta/facture/card.php'},
		// Payment of a customer invoice (button « Saisir règlement »): a page of its own, without the line rules
		{key: 'invoice', re: /\/compta\/(paiement)\.php$/, doc: false, link: '/compta/facture/card.php'},
		{key: 'shipment', re: /\/expedition\/(card|list|shipment|contact|note|document|info|agenda|messaging)\.php$/, doc: true, link: '/expedition/card.php'},
		{key: 'reception', re: /\/reception\/(card|list|contact|note|document|info|agenda|messaging)\.php$/, doc: true, link: '/reception/card.php'},
		{key: 'product', re: /\/product\/(card|list|price|price_suppliers|fournisseurs|note|document|agenda|messaging|stats\/[a-z_]+|stock\/product|composition\/card)\.php$/, doc: false, link: '/product/card.php'},
		// Margins tab of a product (outside /product/)
		{key: 'product', re: /\/margin\/tabs\/(productMargins)\.php$/, doc: false, link: '/product/card.php'}
	];
	var PAGE = null;

	function detectPage() {
		var path = window.location.pathname;
		for (var i = 0; i < OBJECTS.length; i++) {
			var m = path.match(OBJECTS[i].re);
			if (m) {
				var file = m[1];
				return {
					key: OBJECTS[i].key,
					doc: OBJECTS[i].doc,
					link: OBJECTS[i].link,
					type: (file === 'card' ? 'card' : (file === 'list' ? 'list' : 'tab'))
				};
			}
		}
		return null;
	}

	function markBody(page) {
		var cl = document.body.classList;
		cl.add('rm-on', 'rm-obj-' + page.key, 'rm-page-' + page.type);
		if (page.doc) {
			cl.add('rm-doc');
		}
	}

	/* ---------- Fiche réception ---------- */

	/* The add line row: Dolibarr gives it the class liste_titre_create only when the reception already has
	   lines (objectline_create.tpl.php), so mark it ourselves from its Add button, first line included */
	function markCreateRow() {
		var table = document.getElementById('tablelines');
		if (!table) {
			return;
		}
		var row = table.querySelector('tr.liste_titre_create');
		if (!row) {
			var button = table.querySelector('#addline, input[name="addline"]');
			row = button ? button.closest('tr') : null;
		}
		if (row) {
			row.classList.add('rm-createrow');
		}
	}

	function labelReceptionLines() {
		var table = document.getElementById('tablelines');
		if (!table) {
			return;
		}
		var head = table.querySelector('tr.liste_titre');
		var labels = {};
		if (head) {
			Array.prototype.forEach.call(head.cells, function (cell) {
				var label = titleText(cell);
				Array.prototype.forEach.call(cell.classList, function (cl) {
					if (/^linecol/.test(cl) && label !== '') {
						labels[cl] = label;
					}
					// objectline_title.tpl.php gives the supplier ref title the class linerefsupplier (no "col"), while
					// the cells of the lines have linecolrefsupplier (supplier orders and invoices, 24.0.3)
					if (cl === 'linerefsupplier' && label !== '' && !labels.linecolrefsupplier) {
						labels.linecolrefsupplier = label;
					}
				});
			});
		}
		// The add line form names the buying price column linecoluht
		if (labels.linecolcostprice && !labels.linecoluht) {
			labels.linecoluht = labels.linecolcostprice;
		}
		Array.prototype.forEach.call(table.rows, function (tr) {
			if (tr.classList.contains('liste_titre')) {
				return;
			}
			var isform = tr.classList.contains('rm-createrow');
			if (tr.querySelector('input[name="save"], input[name="cancel"]')) {
				tr.classList.add('rm-editrow');
				isform = true;
			}
			Array.prototype.forEach.call(tr.cells, function (td) {
				if (td.classList.contains('linecoldescription')) {
					return;
				}
				// Saved line: an empty cell (no lot, no supplier ref...) takes no place in the card
				if (!isform && text(td) === '' && !td.querySelector('a, img, input, select, span[class*="fa"]')) {
					td.classList.add('rm-empty');
					return;
				}
				for (var cl in labels) {
					if (Object.prototype.hasOwnProperty.call(labels, cl) && td.classList.contains(cl)) {
						td.setAttribute('data-rm-label', labels[cl]);
						break;
					}
				}
			});
		});
	}

	function collapseLineDescription(tries) {
		var row = document.querySelector('#tablelines tr.rm-createrow');
		if (!row) {
			return;
		}
		var cell = row.querySelector('td.linecoldescription');
		if (!cell || cell.querySelector('a.rm-desctoggle')) {
			return;
		}
		var textarea = cell.querySelector('textarea#dp_desc, textarea[name="dp_desc"]');
		// CKEditor replaces the textarea a little after the page load
		var editor = cell.querySelector('#cke_dp_desc');
		if (!editor && textarea && window.CKEDITOR && (tries || 0) < 30) {
			setTimeout(function () { collapseLineDescription((tries || 0) + 1); }, 100);
			return;
		}
		var block = editor || textarea;
		if (!block) {
			return;
		}
		if (textarea && textarea.value.replace(/<[^>]*>/g, '').trim() !== '') {
			return;	// A description was already typed: keep it visible
		}
		block.classList.add('rm-deschidden');
		var link = document.createElement('a');
		link.href = '#';
		link.className = 'rm-desctoggle';
		link.textContent = T.desc;
		link.addEventListener('click', function (e) {
			e.preventDefault();
			var hidden = block.classList.toggle('rm-deschidden');
			link.textContent = hidden ? T.desc : T.deschide;
		});
		block.parentNode.insertBefore(link, block);
	}

	function addLinesButton() {
		var table = document.getElementById('tablelines');
		if (!table || document.querySelector('a.rm-golines')) {
			return;
		}
		var target = table.querySelector('tr.rm-createrow') || table;
		var button = document.createElement('a');
		button.href = '#';
		button.className = 'rm-golines';
		button.textContent = T.lines;
		button.addEventListener('click', function (e) {
			e.preventDefault();
			target.scrollIntoView({behavior: 'smooth', block: 'start'});
		});
		document.body.appendChild(button);
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) {
				button.style.display = entries[0].isIntersecting ? 'none' : '';
			}).observe(table);
		}
	}

	/* Scanned code: one result = selected at once, then the cursor goes to the quantity */
	function bindScanHelpers() {
		if (!window.jQuery) {
			return;
		}
		var $ = window.jQuery;
		var input = $('#search_idprodfournprice');
		if (!input.length) {
			return;
		}
		input.on('autocompleteresponse', function (event, ui) {
			if (!small()) {
				return;
			}
			var term = String(input.val() || '').trim();
			if (!/^[0-9]{8,14}$/.test(term) || !ui || !ui.content || ui.content.length !== 1 || ui.content[0].disabled) {
				return;
			}
			var inst = null;
			try { inst = input.autocomplete('instance'); } catch (e) { inst = null; }
			if (!inst) {
				return;
			}
			inst._trigger('select', event, {item: ui.content[0]});
			input.val(ui.content[0].value);
			setTimeout(function () { inst.close(); }, 0);
		});
		$('#idprodfournprice').on('change', function () {
			var hidden = $(this);
			var value = String(hidden.val() || '');
			// All screens: a reception has no currency, so objectline_create.tpl.php wrongly finds a
			// multicurrency price (undefined == null) and never copies the buying price into the line.
			// Copy it when a supplier price (numeric id) was chosen and the field stayed empty.
			setTimeout(function () {
				var up = hidden.attr('data-up-locale') || hidden.attr('data-up');
				var price = $('#tablelines tr.rm-createrow input[name="price_ht"]');
				if (/^[0-9]+$/.test(value) && up && price.length && price.val() === '') {
					price.val(up);
				}
			}, 100);
			if (!small() || value === '') {
				return;
			}
			// Dolibarr puts the focus on the description, folded here: go to the quantity
			setTimeout(function () {
				var qty = $('#tablelines tr.rm-createrow input[name="qty"]');
				if (qty.length) {
					qty.trigger('focus');
					qty.get(0).select();
				}
			}, 150);
		});
	}

	/* Popups that hold a page (product creation, supplier prices): full screen */
	function bindFullscreenDialogs() {
		if (!window.jQuery) {
			return;
		}
		window.jQuery(document).on('dialogopen', function (event) {
			if (!small()) {
				return;
			}
			var dialog = window.jQuery(event.target).closest('.ui-dialog');
			if (dialog.find('iframe').length) {
				dialog.addClass('rm-fullscreen');
			}
		});
	}

	/* ---------- Listes ---------- */

	function labelList(mainlink) {
		var table = document.querySelector('table.liste');
		if (!table) {
			return;
		}
		var head = table.querySelector('tr.liste_titre:not(.liste_titre_filter)');
		var labels = [];
		if (head) {
			Array.prototype.forEach.call(head.cells, function (cell, i) {
				labels[i] = titleText(cell);
			});
		}
		var filter = table.querySelector('tr.liste_titre_filter');
		if (filter) {
			Array.prototype.forEach.call(filter.cells, function (td, i) {
				if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
				// A filter with several fields (date from / to...) takes the whole width
				if (td.querySelectorAll('input[type="text"], input:not([type]), select').length > 1) {
					td.classList.add('rm-wide');
				}
			});
		}
		Array.prototype.forEach.call(table.querySelectorAll('tr.oddeven'), function (tr) {
			var mainset = false;
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (td.querySelector('input.checkforselect, input[type="checkbox"]')) {
					td.classList.add('rm-hide');	// Mass actions are not used on the terminal
					return;
				}
				if (!mainset && mainlink && td.querySelector('a[href*="' + mainlink + '"]')) {
					td.classList.add('rm-main');
					mainset = true;
					return;
				}
				if (text(td) === '' && !td.querySelector('img, span.badge, input, select')) {
					td.classList.add('rm-empty');
					return;
				}
				if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
			});
		});
		if (!filter || document.querySelector('a.rm-filtertoggle')) {
			return;
		}
		var active = Array.prototype.some.call(filter.querySelectorAll('input[type="text"], select'), function (el) {
			return el.value !== '' && el.value !== '-1' && el.value !== '0';
		});
		var link = document.createElement('a');
		link.href = '#';
		link.className = 'rm-filtertoggle button smallpaddingimp';
		link.textContent = active ? T.filtershide : T.filters;
		if (active) {
			table.classList.add('rm-showfilters');
		}
		link.addEventListener('click', function (e) {
			e.preventDefault();
			var shown = table.classList.toggle('rm-showfilters');
			link.textContent = shown ? T.filtershide : T.filters;
		});
		var wrapper = table.closest('.div-table-responsive') || table;
		wrapper.parentNode.insertBefore(link, wrapper);
	}

	function init() {
		var body = document.body;
		if (!body) {
			return;
		}
		PAGE = detectPage();
		if (!PAGE) {
			return;
		}
		markBody(PAGE);
		bindFullscreenDialogs();
		if (PAGE.doc && PAGE.type === 'card') {
			markCreateRow();
			labelReceptionLines();
			if (PAGE.key === 'reception') {
				bindScanHelpers();
			}
			if (small()) {
				collapseLineDescription(0);
				addLinesButton();
			}
		} else if (PAGE.type === 'list') {
			labelList(PAGE.link);
		}
	}

	// For the scripts of each object (js/rm-<objet>.js, loaded after this one)
	window.PichinovMobile = {
		small: small,
		text: text,
		titleText: titleText,
		page: function () { return PAGE; },
		labelLines: labelReceptionLines,
		labelList: labelList,
		markCreateRow: markCreateRow
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
