/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile : script propre à l'objet « facture fournisseur ». Chargé après js/receptionmobile.js (script commun) ;
 * utiliser window.PichinovMobile (small, text, titleText, page, labelLines, labelList, markCreateRow).
 * Un seul auteur par fichier : la session de cet objet.
 * Pose des classes et des libellés (data-rm-label) mis en forme sous 600 px par css/rm-supplierinvoice.css ;
 * sur ordinateur, aucune règle ne les utilise.
 * - fiche : libellés de la ligne en modification, règlements en fiches, bloc « Fichiers joints » (nom en entier),
 *   formulaire d'ajout (petit écran) : description dépliée quand on choisit une ligne libre ;
 * - liste : libellés de la ligne « Total » ;
 * - onglets Contacts et Fichiers joints : libellés des formulaires et des listes.
 */
(function () {
	'use strict';

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
		var title = table.querySelector('tr.liste_titre');
		var names = [];
		if (title) {
			Array.prototype.forEach.call(title.cells, function (cell, i) {
				names[i] = pm.titleText(cell);
			});
		}
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

	/* Attached files tab (FormFile::list_of_documents, a table that the core also names #tablelines):
	   file name on its own line, the other cells labelled from the title row */
	function labelFiles(pm) {
		var table = document.querySelector('table#tablelines.liste');
		if (!table) {
			return;
		}
		table.classList.add('rm-filelist');
		var head = table.querySelector('tr.liste_titre');
		var labels = [];
		if (head) {
			Array.prototype.forEach.call(head.cells, function (cell, i) {
				labels[i] = pm.titleText(cell);
			});
		}
		Array.prototype.forEach.call(table.querySelectorAll('tr.oddeven'), function (tr) {
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (i === 0) {
					td.classList.add('rm-main');
				} else if (pm.text(td) === '' && !td.querySelector('a, img, input, select, span[class*="fa"]')) {
					td.classList.add('rm-empty');
				} else if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
			});
		});
	}

	/* Card, payments table (validated invoice): each payment (ref, date, type, bank account, delete, amount) becomes
	   a small card; the total rows (already paid, billed, remainder) stay label / amount. Only when there are payments. */
	function labelPayments(pm) {
		var table = document.querySelector('table.paymenttable');
		if (!table) {
			return;
		}
		var head = table.querySelector('tr.liste_titre');
		var labels = [];
		if (head) {
			Array.prototype.forEach.call(head.cells, function (cell, i) {
				labels[i] = pm.titleText(cell);
			});
		}
		var rows = Array.prototype.filter.call(table.querySelectorAll('tr.oddeven'), function (tr) {
			return !!tr.querySelector('a[href*="/fourn/paiement/card.php"]');
		});
		if (!rows.length) {
			return;
		}
		table.classList.add('rm-paycards');
		rows.forEach(function (tr) {
			tr.classList.add('rm-payrow');
			var last = tr.cells.length - 1;
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (i === 0) {
					td.classList.add('rm-payref');
				} else if (i === last) {
					td.classList.add('rm-payamount');
				} else if (td.querySelector('a[href*="action=deletepayment"]')) {
					td.classList.add('rm-paydelete');
				} else if (pm.text(td) === '') {
					td.classList.add('rm-empty');
				} else if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
			});
		});
	}

	/* Card, « Fichiers joints » block (FormFile::showdocuments, table.formdoc): file name on its own line */
	function markFormdoc(pm) {
		var table = document.querySelector('table.formdoc');
		if (!table) {
			return;
		}
		table.classList.add('rm-formdoc');
		Array.prototype.forEach.call(table.querySelectorAll('tr.oddeven'), function (tr) {
			var last = tr.cells.length - 1;
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (i === 0) {
					td.classList.add('rm-main');
				} else if (pm.text(td) === '' && !td.querySelector('a, img, input, select, span[class*="fa"]')) {
					td.classList.add('rm-empty');
				} else if (i === last) {
					td.classList.add('rm-docactions');
				}
			});
		});
	}

	/* List: the total row (Montant HT, Montant TTC...) gets the labels of its columns */
	function labelListTotal(pm) {
		var table = document.querySelector('table.liste');
		if (!table) {
			return;
		}
		var head = table.querySelector('tr.liste_titre:not(.liste_titre_filter)');
		var labels = [];
		if (head) {
			Array.prototype.forEach.call(head.cells, function (cell) {
				var label = pm.titleText(cell);
				for (var k = 0; k < (cell.colSpan || 1); k++) {
					labels.push(label);
				}
			});
		}
		Array.prototype.forEach.call(table.querySelectorAll('tr.liste_total'), function (tr) {
			var col = 0;
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (i === 0) {
					td.classList.add('rm-totaltitle');
				} else if (pm.text(td) === '') {
					td.classList.add('rm-empty');
				} else if (labels[col]) {
					td.setAttribute('data-rm-label', labels[col]);
				}
				col += (td.colSpan || 1);
			});
		});
	}

	/* Add line form, small screens only: a free line type chosen, its description is needed, unfold it
	   (the cursor to the quantity after a supplier price is chosen comes from the common script, bindScanHelpers) */
	function bindAddForm(pm) {
		var $ = window.jQuery;
		var row = document.querySelector('#tablelines tr.rm-createrow');
		if (!$ || !row) {
			return;
		}
		$('#select_type').on('change', function () {
			var value = String($(this).val() || '');
			if (!pm.small() || value === '' || value === '-1') {
				return;
			}
			var link = row.querySelector('a.rm-desctoggle');
			if (link && row.querySelector('td.linecoldescription .rm-deschidden')) {
				link.click();
			}
		});
	}

	/* Line being edited (objectline_edit.tpl.php): its cells have no linecol* class, so the common script gives
	   them no label. Take the label of the column from the name of the field in the cell. */
	var EDITFIELDS = {
		tva_tx: 'linecolvat',
		price_ht: 'linecoluht',
		price_ttc: 'linecoluttc',
		multicurrency_subprice: 'linecoluht_currency',
		multicurrency_price_ttc: 'linecoluttc_currency',
		qty: 'linecolqty',
		units: 'linecoluseunit',
		remise_percent: 'linecoldiscount'
	};

	function labelEditRow(pm) {
		var table = document.getElementById('tablelines');
		var row = table ? table.querySelector('tr.rm-editrow') : null;
		var head = table ? table.querySelector('tr.liste_titre') : null;
		if (!row || !head) {
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
		Array.prototype.forEach.call(row.cells, function (td) {
			if (td.hasAttribute('data-rm-label')) {
				return;
			}
			var field = td.querySelector('input:not([type="hidden"]), select');
			var col = field ? EDITFIELDS[field.name] : null;
			if (col && labels[col]) {
				td.setAttribute('data-rm-label', labels[col]);
			}
		});
	}

	function init() {
		var pm = window.PichinovMobile;
		var page = pm ? pm.page() : null;
		if (!page || page.key !== 'supplierinvoice') {
			return;
		}
		if (page.type === 'card') {
			labelEditRow(pm);
			labelPayments(pm);
			markFormdoc(pm);
			bindAddForm(pm);
		} else if (page.type === 'list') {
			labelListTotal(pm);
		} else if (/\/contact\.php$/.test(window.location.pathname)) {
			labelContacts(pm);
		} else if (/\/document\.php$/.test(window.location.pathname)) {
			labelFiles(pm);
		}
	}

	// After the common script, which registers its own DOMContentLoaded listener first
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
