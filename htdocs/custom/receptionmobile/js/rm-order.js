/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile : script propre à l'objet « commande client ». Chargé après js/receptionmobile.js (script commun) ;
 * utiliser window.PichinovMobile (small, text, titleText, page, labelLines, labelList, markCreateRow).
 * Un seul auteur par fichier : la session de cet objet.
 * - fiche : libellés des champs du formulaire d'ajout / de modification de ligne qui n'en ont pas (le formulaire de
 *   modification n'a pas les classes linecol*) ; marque le bloc « derniers événements » (rm-events) ;
 * - onglets Contacts et Documents : tableaux en fiches (rm-cards), formulaire d'ajout de contact libellé ;
 * - liste : filtres repliés quand aucun n'est vraiment actif (l'état « vide » de la liste des commandes vaut -5),
 *   filtres complémentaires (catégories, commercial...) derrière le même bouton « Filtres ».
 * Ne pose que des classes et des attributs : toute la mise en forme est dans css/rm-order.css, sous 600 px.
 */
(function () {
	'use strict';

	var pm = null;

	/* ---------- Fiche ---------- */

	// Field name of the add / edit line form => class of the column title that names it
	var FIELDCOL = {
		tva_tx: 'linecolvat',
		price_ht: 'linecoluht',
		multicurrency_subprice: 'linecoluht_currency',
		price_ht_devise: 'linecoluht_currency',
		price_ttc: 'linecoluttc',
		qty: 'linecolqty',
		units: 'linecoluseunit',
		remise_percent: 'linecoldiscount',
		fournprice_predef: 'linecolmargin1',
		fournprice: 'linecolmargin1',
		buying_price: 'linecolmargin1'
	};

	function labelLineForms() {
		var table = document.getElementById('tablelines');
		if (!table) {
			return;
		}
		var head = table.querySelector('tr.liste_titre');
		if (!head) {
			return;
		}
		var labels = {};
		Array.prototype.forEach.call(head.cells, function (cell) {
			var label = pm.titleText(cell);
			Array.prototype.forEach.call(cell.classList, function (cl) {
				if (/^linecol/.test(cl) && label !== '') {
					labels[cl] = label;
				}
			});
		});
		Array.prototype.forEach.call(table.querySelectorAll('tr.rm-createrow, tr.rm-editrow'), function (tr) {
			Array.prototype.forEach.call(tr.cells, function (td) {
				if (td.hasAttribute('data-rm-label')) {
					return;
				}
				var field = td.querySelector('input[name]:not([type="hidden"]):not([type="radio"]):not([type="submit"]), select[name]');
				var col = field ? FIELDCOL[field.getAttribute('name')] : null;
				if (col && labels[col]) {
					td.setAttribute('data-rm-label', labels[col]);
				}
			});
		});
	}

	/* "Latest events" block: the table that follows the title holding the links to the agenda */
	function markEvents() {
		Array.prototype.forEach.call(document.querySelectorAll('table.table-fiche-title'), function (title) {
			if (!title.querySelector('a[href*="/comm/action/"]')) {
				return;
			}
			var next = title.nextElementSibling;
			var table = next && next.classList.contains('div-table-responsive-no-min') ? next.querySelector(':scope > table') : null;
			if (table) {
				table.classList.add('rm-events');
			}
		});
	}

	/* ---------- Onglets : tableaux en fiches ---------- */

	function cards(table) {
		if (!table || table.classList.contains('rm-cards')) {
			return;
		}
		table.classList.add('rm-cards');
		var head = table.querySelector('tr.liste_titre:not(.liste_titre_filter)');
		var labels = [];
		if (head) {
			Array.prototype.forEach.call(head.cells, function (cell, i) {
				labels[i] = pm.titleText(cell);
			});
		}
		Array.prototype.forEach.call(table.rows, function (tr) {
			if (tr.classList.contains('liste_titre')) {
				return;
			}
			if (tr.cells.length === 1) {
				tr.cells[0].classList.add('rm-wide');	// "None", "No file"...
				return;
			}
			var mainset = false;
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				var txt = pm.text(td);
				if (txt === '' && td.querySelector('.fa-trash, .fa-trash-alt, .fa-unlink, .fa-pen, .fa-pencil-alt, .fa-edit')) {
					td.classList.add('rm-actions');
					return;
				}
				if (txt === '' && !td.querySelector('img, span.badge, span[class*="fa-toggle"], input, select, a')) {
					td.classList.add('rm-empty');
					return;
				}
				if (!mainset) {
					td.classList.add('rm-main');
					mainset = true;
					return;
				}
				if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
			});
		});
	}

	/* Contacts tab: the add form is a div table (one form per row), titles in the first row */
	function labelContactForm() {
		var box = document.querySelector('.tableforcontact');
		if (!box) {
			return;
		}
		var head = box.querySelector('.tagtr.liste_titre');
		var labels = [];
		if (head) {
			Array.prototype.forEach.call(head.querySelectorAll(':scope > .tagtd'), function (cell, i) {
				labels[i] = pm.titleText(cell);
			});
		}
		Array.prototype.forEach.call(box.querySelectorAll('.tagtr:not(.liste_titre)'), function (row) {
			Array.prototype.forEach.call(row.querySelectorAll(':scope > .tagtd'), function (cell, i) {
				if (pm.text(cell) === '' && !cell.querySelector('input:not([type="hidden"]), select, a, img')) {
					cell.classList.add('rm-empty');
					return;
				}
				if (cell.querySelector('input[type="submit"], button')) {
					cell.classList.add('rm-submit');
					return;
				}
				if (labels[i]) {
					cell.setAttribute('data-rm-label', labels[i]);
				}
			});
		});
		cards(document.querySelector('#searchFormList table.liste') || document.querySelector('.fiche table.liste'));
	}

	function cardsDocuments() {
		// Attached files (id tablelines on this page) and linked files
		Array.prototype.forEach.call(document.querySelectorAll('.fiche table.liste'), cards);
	}

	/* ---------- Liste ---------- */

	// A filter is set when its value is not the empty one: '', -1, 0, or an option with no text (-5 for the status here)
	function filterSet(el) {
		if (el.tagName === 'SELECT') {
			var opt = el.options[el.selectedIndex];
			if (!opt || (opt.text || '').trim() === '') {
				return false;
			}
		}
		var v = el.value;
		return v !== '' && v !== '-1' && v !== '0';
	}

	function tidyListFilters() {
		var table = document.querySelector('table.liste');
		var link = document.querySelector('a.rm-filtertoggle');
		var form = document.getElementById('searchFormList');
		if (!table || !link || !form) {
			return;
		}
		var row = table.querySelector('tr.liste_titre_filter');
		var more = form.querySelector('div.liste_titre_bydiv');
		var fields = [];
		if (row) {
			fields = fields.concat(Array.prototype.slice.call(row.querySelectorAll('input[type="text"], select')));
		}
		if (more) {
			fields = fields.concat(Array.prototype.slice.call(more.querySelectorAll('input[type="text"], select')));
		}
		var active = fields.some(filterSet);
		if (table.classList.contains('rm-showfilters') !== active) {
			link.click();	// The common toggle keeps its text and the table class in step
		}
		if (!more) {
			return;
		}
		// The extra filters (categories, sales representative...) follow the same button
		var sync = function () {
			form.classList.toggle('rm-showmore', table.classList.contains('rm-showfilters'));
		};
		sync();
		link.addEventListener('click', function () {
			setTimeout(sync, 0);
		});
		var wrapper = table.closest('.div-table-responsive') || table;
		if (more.compareDocumentPosition(wrapper) & Node.DOCUMENT_POSITION_FOLLOWING) {
			// Put the button above the extra filters
			more.parentNode.insertBefore(link, more);
		}
	}

	function init() {
		pm = window.PichinovMobile;
		var page = pm ? pm.page() : null;
		if (!page || page.key !== 'order') {
			return;
		}
		var path = window.location.pathname;
		if (page.type === 'card') {
			labelLineForms();
			markEvents();
		} else if (page.type === 'list') {
			if (pm.small()) {
				tidyListFilters();
			}
		} else if (/\/contact\.php$/.test(path)) {
			labelContactForm();
		} else if (/\/document\.php$/.test(path)) {
			cardsDocuments();
		}
	}

	// After the common script, which registers its own DOMContentLoaded listener first
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
