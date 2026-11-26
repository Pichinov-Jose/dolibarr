/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile : script propre à l'objet « facture client ». Chargé après js/receptionmobile.js (script commun) ;
 * utiliser window.PichinovMobile (small, text, titleText, page, labelLines, labelList, markCreateRow).
 * Un seul auteur par fichier : la session de cet objet.
 * - fiche : libellés du formulaire d'ajout / de modification de ligne, derniers événements en fiches ;
 * - liste : libellés de la ligne de total ;
 * - onglet Contacts : libellés des formulaires d'ajout et de la liste des contacts ;
 * - onglet Documents : liste des fichiers en fiches ;
 * - onglet Événements : liste des événements en fiches, filtres derrière un bouton ;
 * - saisie d'un règlement (compta/paiement.php) : liste des factures à régler en fiches.
 * Rendu sous 600 px par css/rm-invoice.css ; sur ordinateur, les classes posées ici ne changent rien.
 */
(function () {
	'use strict';

	/* Column title of each cell position, colspans counted */
	function columnLabels(pm, row) {
		var labels = [];
		if (!row) {
			return labels;
		}
		Array.prototype.forEach.call(row.cells, function (cell) {
			var label = pm.titleText(cell);
			for (var k = 0; k < (cell.colSpan || 1); k++) {
				labels.push(k === 0 ? label : '');
			}
		});
		return labels;
	}

	/* Label each cell of the rows from the column titles; empty cells take no place */
	function labelRows(pm, rows, labels, keepempty) {
		Array.prototype.forEach.call(rows, function (tr) {
			var col = 0;
			Array.prototype.forEach.call(tr.cells, function (td) {
				var label = labels[col] || '';
				col += (td.colSpan || 1);
				if (!keepempty && pm.text(td) === '' && !td.querySelector('a, img, input, select, textarea, span.badge, span[class*="fa"]')) {
					td.classList.add('rm-empty');
					return;
				}
				if (label !== '' && !td.hasAttribute('data-rm-label')) {
					td.setAttribute('data-rm-label', label);
				}
			});
		});
	}

	/* Card: cells of the add / edit line forms without a column class of their own. The buying price is
	   linecolmargin1 in the title, linecolmargin in the add form, margininfos only in the edit form; the edit form
	   (core/tpl/objectline_edit.tpl.php) gives no linecol class to VAT, prices and quantity: found by their field */
	var EDITFIELDS = [
		{field: 'tva_tx', col: 'linecolvat'},
		{field: 'price_ht', col: 'linecoluht'},
		{field: 'multicurrency_subprice', col: 'linecoluht_currency'},
		{field: 'price_ttc', col: 'linecoluttc'},
		{field: 'multicurrency_price_ttc', col: 'linecoluttc_currency'},
		{field: 'qty', col: 'linecolqty'},
		{field: 'units', col: 'linecoluseunit'},
		{field: 'buying_price', col: 'linecolmargin1'}
	];

	function labelLineForms(pm) {
		var table = document.getElementById('tablelines');
		if (!table) {
			return;
		}
		var head = table.querySelector('tr.liste_titre');
		var labels = {};
		if (head) {
			Array.prototype.forEach.call(head.cells, function (cell) {
				var label = pm.titleText(cell);
				if (label === '') {
					return;
				}
				Array.prototype.forEach.call(cell.classList, function (cl) {
					if (/^linecol/.test(cl)) {
						labels[cl] = label;
					}
				});
			});
		}
		Array.prototype.forEach.call(table.querySelectorAll('tr.rm-createrow, tr.rm-editrow'), function (tr) {
			EDITFIELDS.forEach(function (def) {
				var field = tr.querySelector('[name="' + def.field + '"]');
				var td = field ? field.closest('td') : null;
				if (td && labels[def.col] && !td.hasAttribute('data-rm-label') && !td.classList.contains('linecoldescription')) {
					td.setAttribute('data-rm-label', labels[def.col]);
				}
			});
		});
	}

	/* List: total row (Total, then the amounts under their column title) */
	function labelListTotal(pm) {
		var table = document.querySelector('table.liste');
		var total = table ? table.querySelector('tr.liste_total') : null;
		if (!total) {
			return;
		}
		var labels = columnLabels(pm, table.querySelector('tr.liste_titre:not(.liste_titre_filter)'));
		labels[0] = '';
		total.classList.add('rm-total');
		labelRows(pm, [total], labels, false);
		if (total.cells.length) {
			total.cells[0].classList.remove('rm-empty');
		}
	}

	/* Contacts tab (core/tpl/contacts.tpl.php): the add forms are div rows under a title row, the list is a table */
	function labelContacts(pm) {
		var box = document.querySelector('.tableforcontact');
		if (box) {
			var head = box.querySelector('form.tagtr.liste_titre');
			var labels = [];
			if (head) {
				Array.prototype.forEach.call(head.querySelectorAll(':scope > .tagtd'), function (cell, i) {
					labels[i] = pm.titleText(cell);
				});
			}
			Array.prototype.forEach.call(box.querySelectorAll('form.tagtr:not(.liste_titre)'), function (form) {
				Array.prototype.forEach.call(form.querySelectorAll(':scope > .tagtd'), function (cell, i) {
					if (pm.text(cell) === '' && !cell.querySelector('select, input, a')) {
						cell.classList.add('rm-empty');
					} else if (labels[i]) {
						cell.setAttribute('data-rm-label', labels[i]);
					}
				});
			});
		}
		var form = document.getElementById('searchFormList');
		var table = form ? form.querySelector('table.liste') : null;
		if (!table) {
			return;
		}
		table.classList.add('rm-contactlist');
		var names = columnLabels(pm, table.querySelector('tr.liste_titre'));
		Array.prototype.forEach.call(table.querySelectorAll('tr[data-rowid]'), function (tr) {
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (td.querySelector('a[href*="action=deletecontact"]')) {
					td.classList.add('rm-unlink');
				} else if (names[i]) {
					td.setAttribute('data-rm-label', names[i]);
				}
			});
		});
	}

	/* « Filtres » button before the table: the filter row stays folded unless a filter is in use */
	function addFilterToggle(pm, table, filter) {
		var en = ((document.documentElement.getAttribute('lang') || 'fr').substring(0, 2) === 'en');
		var show = en ? 'Filters' : 'Filtres';
		var hide = en ? 'Hide the filters' : 'Masquer les filtres';
		var active = Array.prototype.some.call(filter.querySelectorAll('input[type="text"], select'), function (el) {
			return el.value !== '' && el.value !== '-1' && el.value !== '0';
		});
		if (active) {
			table.classList.add('rm-showfilters');
		}
		var link = document.createElement('a');
		link.href = '#';
		link.className = 'rm-filtertoggle rm-inv-filtertoggle button smallpaddingimp';
		link.textContent = active ? hide : show;
		link.addEventListener('click', function (e) {
			e.preventDefault();
			var shown = table.classList.toggle('rm-showfilters');
			link.textContent = shown ? hide : show;
		});
		if (!pm.small()) {
			link.style.display = 'none';	// Computers keep the native filter row (the CSS only acts under 600 px)
		}
		var wrapper = table.closest('.div-table-responsive-no-min') || table;
		wrapper.parentNode.insertBefore(link, wrapper);
	}

	/* A list of events, one card each, the event ref first */
	function eventCards(pm, table) {
		table.classList.add('rm-eventlist');
		var labels = columnLabels(pm, table.querySelector('tr.liste_titre:not(.liste_titre_filter)'));
		var filter = table.querySelector('tr.liste_titre_filter');
		if (filter) {
			labelRows(pm, [filter], labels, false);
			addFilterToggle(pm, table, filter);
		}
		var rows = Array.prototype.filter.call(table.rows, function (tr) {
			return !tr.classList.contains('liste_titre') && !tr.classList.contains('liste_titre_filter');
		});
		labelRows(pm, rows, labels, false);
		rows.forEach(function (tr) {
			var ref = tr.querySelector('td a[href*="/comm/action/card.php"]');
			if (ref) {
				ref.closest('td').classList.add('rm-main');
			}
		});
	}

	/* Events tab (agenda.php, show_actions_done): the list of events with its filter row */
	function labelEvents(pm) {
		Array.prototype.some.call(document.querySelectorAll('.div-table-responsive-no-min > table.noborder'), function (t) {
			if (t.querySelector('tr.liste_titre_filter')) {
				eventCards(pm, t);
				return true;
			}
			return false;
		});
	}

	/* Card: the last events block (« Les 10 derniers événements »), once the invoice has events */
	function labelCardEvents(pm) {
		Array.prototype.forEach.call(document.querySelectorAll('.fichehalfleft table.noborder, .fichehalfright table.noborder'), function (t) {
			if (!t.classList.contains('paymenttable') && t.querySelector('tr.liste_titre') && t.querySelector('a[href*="/comm/action/card.php"]')) {
				eventCards(pm, t);
			}
		});
	}

	/* Documents tab: the list of files (it carries the id tablelines, but the page is a tab), one card each */
	function labelFiles(pm) {
		var table = document.getElementById('tablelines');
		if (!table || !table.classList.contains('liste')) {
			return;
		}
		table.classList.add('rm-filelist');
		var labels = columnLabels(pm, table.querySelector('tr.liste_titre'));
		var rows = Array.prototype.filter.call(table.rows, function (tr) {
			return !tr.classList.contains('liste_titre');
		});
		labelRows(pm, rows, labels, false);
	}

	/* Payment (compta/paiement.php): the invoices to pay, one card each, the amount to pay in full width */
	function labelPayment(pm) {
		document.body.classList.add('rm-inv-payment');
		var table = document.getElementById('customer-invoices-paiments-list');
		if (!table) {
			return;
		}
		table.classList.add('rm-paylist');
		var labels = columnLabels(pm, table.querySelector('tr.liste_titre'));
		var rows = Array.prototype.filter.call(table.rows, function (tr) {
			return !tr.classList.contains('liste_titre');
		});
		labelRows(pm, rows, labels, false);
		rows.forEach(function (tr) {
			var ref = tr.querySelector('td a[href*="/compta/facture/card.php"]');
			if (ref) {
				ref.closest('td').classList.add('rm-main');
			}
			Array.prototype.forEach.call(tr.cells, function (td) {
				if (td.querySelector('input[type="text"], input:not([type])')) {
					td.classList.add('rm-payinput');
				}
			});
		});
	}

	function init() {
		var pm = window.PichinovMobile;
		var page = pm ? pm.page() : null;
		if (!page || page.key !== 'invoice') {
			return;
		}
		var path = window.location.pathname;
		if (page.type === 'card') {
			labelLineForms(pm);
			labelCardEvents(pm);
		} else if (page.type === 'list') {
			labelListTotal(pm);
		} else if (/\/compta\/facture\/contact\.php$/.test(path)) {
			labelContacts(pm);
		} else if (/\/compta\/facture\/document\.php$/.test(path)) {
			labelFiles(pm);
		} else if (/\/compta\/facture\/agenda\.php$/.test(path)) {
			labelEvents(pm);
		} else if (/\/compta\/paiement\.php$/.test(path)) {
			labelPayment(pm);
		}
	}

	// After the common script, which registers its own DOMContentLoaded listener first
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
