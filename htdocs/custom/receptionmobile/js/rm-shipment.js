/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile : script propre à l'objet « expédition ». Chargé après js/receptionmobile.js (script commun) ;
 * utiliser window.PichinovMobile (small, text, titleText, page, labelLines, labelList, markCreateRow).
 * Un seul auteur par fichier : la session de cet objet.
 * Pose les classes utilisées par css/rm-shipment.css (voir l'en-tête de ce fichier) :
 * - création depuis une commande : en-tête en colonne, une fiche par ligne de commande avec ses sous-lignes
 *   d'entrepôt ou de lot, outils « remplir / remettre à 0 » sortis de l'en-tête masqué ;
 * - fiche : ligne en modification (quantité / entrepôt / lot) et libellés des autres lignes ;
 * - onglet « Expéditions » de la commande, onglets Contacts et Fichiers joints : tableaux en fiches.
 * Les classes sont posées sur tous les écrans ; seules les règles sous 600 px les utilisent. Le seul déplacement
 * d'élément (outils de quantité) n'a lieu que sous 600 px.
 */
(function () {
	'use strict';

	var pm = null;

	function text(el) {
		return (el.textContent || '').replace(/\s+/g, ' ').trim();
	}

	/* Nothing to show: no text, no link, no picture, no field */
	function isEmptyCell(td) {
		return text(td) === '' && !td.querySelector('a, img, input:not([type="hidden"]), select, textarea, span[class*="fa"], span.badge');
	}

	/* Labels of a title row by column position (colspan aware) */
	function headerLabels(row) {
		var labels = [];
		var pos = 0;
		if (!row) {
			return labels;
		}
		Array.prototype.forEach.call(row.cells, function (cell) {
			var label = pm.titleText(cell).replace(/\s*\/\s*$/, '');
			for (var k = 0; k < cell.colSpan; k++) {
				labels[pos + k] = (k === 0 ? label : '');
			}
			pos += cell.colSpan;
		});
		return labels;
	}

	function titleRow(table) {
		return table.querySelector('tr.liste_titre:not(.liste_titre_filter)');
	}

	/* ---------- Création depuis une commande ---------- */

	function createForm() {
		var action = document.querySelector('form input[name="action"][value="add"]');
		var form = action ? action.form : null;
		if (!form || !form.querySelector('input.qtyl')) {
			return;
		}
		form.classList.add('rm-ship-createform');
		var head = form.querySelector('table.border');
		if (head) {
			head.classList.add('rm-ship-stack');
		}
		var lines = form.querySelector('input.qtyl').closest('table');
		if (!lines) {
			return;
		}
		lines.classList.add('rm-ship-lines');
		var title = titleRow(lines);
		moveQtyTools(lines, title);
		var labels = headerLabels(title);
		var subseen = false;
		Array.prototype.forEach.call(lines.rows, function (tr) {
			if (tr.classList.contains('liste_titre')) {
				return;
			}
			var first = tr.cells[0];
			if (first && first.colSpan >= 3 && tr.cells.length >= 3) {
				// Warehouse or lot of the order line above: quantity, then warehouse / lot (stock)
				tr.classList.add('rm-ship-sub');
				tr.cells[1].classList.add('rm-ship-qty');
				tr.cells[2].classList.add('rm-ship-wh');
				if (!subseen) {
					if (labels[3]) {
						tr.cells[1].setAttribute('data-rm-label', labels[3]);
					}
					if (labels[4]) {
						tr.cells[2].setAttribute('data-rm-label', labels[4]);
					}
					subseen = true;
				}
				return;
			}
			tr.classList.add('rm-ship-main');
			subseen = false;
			var pos = 0;
			Array.prototype.forEach.call(tr.cells, function (td) {
				var i = pos;
				pos += td.colSpan;
				if (i === 0) {
					return;
				}
				if (i === 3) {
					td.classList.add('rm-ship-qty');
				} else if (i === 4) {
					td.classList.add('rm-ship-wh');
				}
				if (isEmptyCell(td)) {
					td.classList.add('rm-empty');
					return;
				}
				if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
			});
		});
	}

	/* « Autofill » and « Reset » links live in the hidden title: put them above the lines, with their title as text */
	function moveQtyTools(lines, title) {
		// Desktop keeps them in the title of the column
		if (!pm.small() || !title || document.querySelector('p.rm-ship-qtytools')) {
			return;
		}
		var tools = [document.getElementById('autofill'), document.getElementById('autoreset')].filter(function (el) {
			return el && title.contains(el);
		});
		if (!tools.length) {
			return;
		}
		var bar = document.createElement('p');
		bar.className = 'rm-ship-qtytools';
		tools.forEach(function (el) {
			var img = el.querySelector('[title]');
			var label = img ? img.getAttribute('title') : '';
			// The text between the two links (« / ») stays in the title
			bar.appendChild(el);
			if (label && text(el) === '') {
				var span = document.createElement('span');
				span.className = 'rm-ship-qtytool-label';
				span.textContent = label;
				el.appendChild(span);
			}
		});
		var wrapper = lines.closest('.div-table-responsive-no-min, .div-table-responsive') || lines;
		wrapper.parentNode.insertBefore(bar, wrapper);
	}

	/* ---------- Fiche : ligne en modification ---------- */

	function editLine() {
		var table = document.getElementById('tablelines');
		if (!table) {
			return;
		}
		var head = titleRow(table);
		var other = head ? head.querySelector('.linecoleditlineotherinfo') : null;
		if (other) {
			// One title for the edited line: « Qty to ship - Source warehouse - Lot » → labels of the other lines
			var parts = text(other).split(' - ');
			var map = {linecolqtytoship: parts[0], linecolqtyshipped: parts[0]};
			if (parts.length >= 3) {
				map.linecolwarehousesource = parts[1];
				map.linecolbatch = parts[2];
			} else if (parts.length === 2) {
				map[table.querySelector('td.linecolwarehousesource') ? 'linecolwarehousesource' : 'linecolbatch'] = parts[1];
			}
			Object.keys(map).forEach(function (cl) {
				Array.prototype.forEach.call(table.querySelectorAll('tr:not(.liste_titre) > td.' + cl), function (td) {
					if (!td.hasAttribute('data-rm-label') && !td.classList.contains('rm-empty') && map[cl]) {
						td.setAttribute('data-rm-label', map[cl]);
					}
				});
			});
			Array.prototype.forEach.call(table.querySelectorAll('tr.rm-editrow > td'), function (td) {
				if (td.querySelector('table') && td.querySelector('input.qtyl, select')) {
					td.classList.add('rm-ship-editcell');
					td.setAttribute('data-rm-label', parts.join(' / '));
				}
			});
		}
		Array.prototype.forEach.call(table.querySelectorAll('tr.rm-editrow > td'), function (td) {
			if (td.querySelector('input[name="save"], input[name="cancel"]')) {
				td.classList.add('rm-ship-savecell');
			}
		});
	}

	/* Form « Add a product » of the module orderremainder (draft shipment of an order) */
	function orderRemainderForm() {
		Array.prototype.forEach.call(document.querySelectorAll('.orderremainder-form table.tableforfield'), function (table) {
			table.classList.add('rm-ship-stack');
		});
	}

	/* ---------- Tableaux des onglets ---------- */

	/* One card per row: labels from the title, main cell, empty cells hidden, action buttons at the bottom */
	function cards(table, mainindex) {
		if (!table || table.classList.contains('rm-ship-cards')) {
			return;
		}
		table.classList.add('rm-ship-cards');
		var labels = headerLabels(titleRow(table));
		Array.prototype.forEach.call(table.rows, function (tr) {
			if (tr.classList.contains('liste_titre') || tr.cells.length < 2) {
				return;	// Title, or « No record » row
			}
			var pos = 0;
			Array.prototype.forEach.call(tr.cells, function (td) {
				var i = pos;
				pos += td.colSpan;
				if (i === mainindex) {
					td.classList.add('rm-main');
					return;
				}
				if (td.querySelector('input.checkforselect')) {
					td.classList.add('rm-empty');
					return;
				}
				if (isEmptyCell(td)) {
					td.classList.add('rm-empty');
					return;
				}
				if (!labels[i] && text(td) === '' && td.querySelector('a')) {
					td.classList.add('rm-actions');
					return;
				}
				if (td.classList.contains('actionbuttons')) {
					td.classList.add('rm-actions');
					return;
				}
				if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
				if (text(td).length > 40) {
					td.classList.add('rm-wide');	// A description: the whole width
				}
			});
		});
	}

	/* Order tab « Shipments »: lines of the order (ordered, shipped, remaining, stock) */
	function orderLines() {
		var table = document.getElementById('tablelines');
		if (!table) {
			return;
		}
		var head = titleRow(table);
		if (!head) {
			return;
		}
		table.classList.add('rm-ship-orderlines');
		var labels = headerLabels(head);
		Array.prototype.forEach.call(table.rows, function (tr) {
			if (tr === head) {
				return;
			}
			var pos = 0;
			Array.prototype.forEach.call(tr.cells, function (td) {
				var i = pos;
				pos += td.colSpan;
				if (i === 0) {
					return;
				}
				if (isEmptyCell(td)) {
					td.classList.add('rm-empty');	// Kept in place: the columns stay aligned
				} else if (labels[i]) {
					td.setAttribute('data-rm-label', labels[i]);
				}
			});
		});
	}

	/* Contacts tab: empty cells of the add forms take no place */
	function contactForms() {
		Array.prototype.forEach.call(document.querySelectorAll('.tableforcontact > .tagtr > .tagtd'), function (td) {
			if (isEmptyCell(td)) {
				td.classList.add('rm-empty');
			}
		});
	}

	function init() {
		pm = window.PichinovMobile;
		var page = pm ? pm.page() : null;
		if (!page || page.key !== 'shipment') {
			return;
		}
		var file = (window.location.pathname.match(/\/expedition\/([a-z]+)\.php$/) || [])[1] || '';
		if (file === 'card') {
			createForm();
			editLine();
			orderRemainderForm();
		} else if (file === 'shipment') {
			orderLines();
			// Shipments already made for this order
			Array.prototype.forEach.call(document.querySelectorAll('table.liste'), function (table) {
				cards(table, 0);
			});
		} else if (file === 'contact') {
			contactForms();
			cards(document.querySelector('table.liste'), -1);
		} else if (file === 'document') {
			Array.prototype.forEach.call(document.querySelectorAll('table.liste'), function (table) {
				cards(table, 0);
			});
		}
	}

	// After the common script, which registers its own DOMContentLoaded listener first
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
