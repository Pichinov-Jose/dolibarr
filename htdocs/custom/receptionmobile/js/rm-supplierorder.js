/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile : script propre à l'objet « commande fournisseur ». Chargé après js/receptionmobile.js (script commun) ;
 * utiliser window.PichinovMobile (small, text, titleText, page, labelLines, labelList, markCreateRow).
 * Un seul auteur par fichier : la session de cet objet.
 *
 * - fiche : libellés du formulaire de modification de ligne (classes linecol* absentes du modèle du cœur),
 *   fichiers générés et « Derniers événements » en petites fiches ;
 * - liste : filtres supplémentaires (catégorie, commercial, produit...) derrière le bouton « Filtres », ligne de total libellée ;
 * - réception des produits (dispatch.php) : une fiche par ligne de commande (produit, quantités, quantité à recevoir,
 *   entrepôt), titres réduits aux commandes globales « Vider » et entrepôt « Forcer à » ;
 * - onglets Contacts, Fichiers joints, Événements : tableaux en fiches, formulaires d'ajout de contact sur une colonne.
 * Tout ne s'applique que sous 600 px (classes posées ici, règles dans css/rm-supplierorder.css sous @media).
 */
(function () {
	'use strict';

	var en = ((document.documentElement.getAttribute('lang') || 'fr').substring(0, 2) === 'en');
	var T = {
		filters: en ? 'Filters' : 'Filtres',
		filtershide: en ? 'Hide the filters' : 'Masquer les filtres'
	};

	function fileName() {
		var m = window.location.pathname.match(/\/([a-z_]+)\.php$/);
		return m ? m[1] : '';
	}

	/* Text of a column title before its first line break: « Qté à ventiler <br> Vider », « Entrepôt <br> <select> » */
	function firstText(cell) {
		var out = '';
		for (var n = cell.firstChild; n; n = n.nextSibling) {
			if (n.nodeName === 'BR') {
				break;
			}
			if (n.nodeType === 3) {
				out += n.nodeValue;
			} else if (n.nodeType === 1 && !n.matches('script, select, input, a, .select2, button')) {
				out += n.textContent;
			}
		}
		out = out.replace(/\s+/g, ' ').trim();
		return (out.length <= 40 ? out : '');
	}

	/* Column position of each cell, colspans counted */
	function positions(tr) {
		var pos = 0;
		var res = [];
		Array.prototype.forEach.call(tr.cells, function (cell) {
			res.push(pos);
			pos += cell.colSpan || 1;
		});
		return res;
	}

	function headLabels(pm, head) {
		var labels = [];
		if (head) {
			var pos = positions(head);
			Array.prototype.forEach.call(head.cells, function (cell, i) {
				labels[pos[i]] = firstText(cell) || pm.titleText(cell);
			});
		}
		return labels;
	}

	function isEmptyCell(pm, td) {
		return pm.text(td) === '' && !td.querySelector('a, img, input:not([type="hidden"]), select, button, span.badge, span[class*="fa-"]');
	}

	/* The responsive wrapper of a table turned into cards must not scroll any more */
	function markWrapper(table) {
		var wrapper = table.parentElement;
		if (wrapper && /div-table-responsive/.test(wrapper.className)) {
			wrapper.classList.add('rm-so-wrap');
		}
	}

	/* A table of the page shown as one card per row: labels from the column titles, first cell = title of the card */
	function cardTable(pm, table, isMain) {
		if (!table || table.classList.contains('rm-so-cards')) {
			return;
		}
		var head = table.querySelector('tr.liste_titre:not(.liste_titre_filter)');
		var labels = headLabels(pm, head);
		Array.prototype.forEach.call(table.rows, function (tr) {
			if (tr.classList.contains('liste_titre') || tr.classList.contains('liste_titre_filter')) {
				return;
			}
			tr.classList.add('rm-so-row');
			var pos = positions(tr);
			var first = !isMain;
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (td.colSpan > 2 && tr.cells.length === 1) {
					td.classList.add('rm-so-full');	// « Aucun », « Pas de contact »...
					return;
				}
				if (isEmptyCell(pm, td)) {
					td.classList.add('rm-so-empty');
					return;
				}
				if (first || (isMain && isMain(td))) {
					td.classList.add('rm-so-main');
					first = false;
					return;
				}
				if (pm.text(td) === '') {
					td.classList.add('rm-so-icon');	// Action or status pictos only
					return;
				}
				if (labels[pos[i]]) {
					td.setAttribute('data-rm-label', labels[pos[i]]);
				}
			});
		});
		table.classList.add('rm-so-cards');
		markWrapper(table);
	}

	/* Filter row of a table behind a button (Événements tab), shown at once when a filter is set */
	function foldFilterRow(pm, table) {
		var filter = table ? table.querySelector('tr.liste_titre_filter') : null;
		if (!filter || document.querySelector('a.rm-so-filtertoggle')) {
			return;
		}
		// Label of each filter from the column titles (the title row comes after the filters)
		var labels = headLabels(pm, table.querySelector('tr.liste_titre:not(.liste_titre_filter)'));
		var pos = positions(filter);
		Array.prototype.forEach.call(filter.cells, function (td, i) {
			var fields = td.querySelectorAll('input[type="text"], input:not([type]), select');
			if (!fields.length && !td.querySelector('button, input[type="image"], .button, a')) {
				td.classList.add('rm-so-empty');
				return;
			}
			if (fields.length > 1) {
				td.classList.add('rm-so-wide');	// date from / to...
			}
			if (labels[pos[i]]) {
				td.setAttribute('data-rm-label', labels[pos[i]]);
			}
		});
		var active = Array.prototype.some.call(filter.querySelectorAll('input[type="text"], select'), function (el) {
			return el.value !== '' && el.value !== '-1' && el.value !== '0';
		});
		var link = document.createElement('a');
		link.href = '#';
		link.className = 'rm-so-filtertoggle button smallpaddingimp';
		link.textContent = active ? T.filtershide : T.filters;
		if (active) {
			table.classList.add('rm-so-showfilters');
		}
		link.addEventListener('click', function (e) {
			e.preventDefault();
			var shown = table.classList.toggle('rm-so-showfilters');
			link.textContent = shown ? T.filtershide : T.filters;
		});
		var wrapper = table.closest('.div-table-responsive, .div-table-responsive-no-min') || table;
		wrapper.parentNode.insertBefore(link, wrapper);
	}

	/* ---------- Fiche ---------- */

	/* Edit form of a line: objectline_edit.tpl.php (24.0.3) gives no linecol* class to the VAT, price and quantity
	   cells, unlike the view and add templates. Add it from the field inside, then label the cell from the titles. */
	var EDITCOLS = {
		tva_tx: 'linecolvat',
		price_ht: 'linecoluht',
		multicurrency_subprice: 'linecoluht_currency',
		price_ttc: 'linecoluttc',
		multicurrency_price_ttc: 'linecoluttc_currency',
		qty: 'linecolqty'
	};

	function fixEditRow(pm) {
		var tr = document.querySelector('#tablelines tr.tredited');
		var head = document.querySelector('#tablelines tr.liste_titre');
		if (!tr || !head) {
			return;
		}
		Array.prototype.forEach.call(tr.cells, function (td) {
			if (/\blinecol/.test(td.className)) {
				return;
			}
			var field = td.querySelector('input[name], select[name]');
			var cl = field ? EDITCOLS[field.name] : null;
			if (!cl) {
				return;
			}
			td.classList.add(cl);
			var th = head.querySelector('.' + cl);
			var label = th ? (firstText(th) || pm.titleText(th)) : '';
			if (label !== '') {
				td.setAttribute('data-rm-label', label);
			}
		});
	}

	function initCard(pm) {
		if (pm.small()) {
			fixEditRow(pm);
		}
		// Generated documents: each file row as a small card (name, then size, date and actions)
		Array.prototype.forEach.call(document.querySelectorAll('table.formdoc tr'), function (tr) {
			if (tr.querySelector('a.documentdownload, a.documentpreview')) {
				tr.classList.add('rm-so-docrow');
			}
		});
		// Latest events: the title of the event heads the card
		cardTable(pm, document.querySelector('table.listactionlargetitle'), function (td) {
			return td.classList.contains('tdoverflowmax250');
		});
	}

	/* ---------- Liste ---------- */

	function initList(pm) {
		var table = document.querySelector('table.liste');
		if (!table) {
			return;
		}
		// Total row: label of each amount from the column titles
		var total = table.querySelector('tr.liste_total');
		if (total) {
			var labels = headLabels(pm, table.querySelector('tr.liste_titre:not(.liste_titre_filter)'));
			var pos = positions(total);
			Array.prototype.forEach.call(total.cells, function (td, i) {
				if (i === 0) {
					td.classList.add('rm-main');
				} else if (pm.text(td) === '') {
					td.classList.add('rm-empty');
				} else if (labels[pos[i]]) {
					td.setAttribute('data-rm-label', labels[pos[i]]);
				}
			});
		}
		// More filters (category, sales representative, product...) above the list: behind the common « Filtres » button
		var more = document.querySelector('div.liste_titre_bydiv');
		var toggle = document.querySelector('a.rm-filtertoggle');
		if (!more || !toggle) {
			return;
		}
		var active = Array.prototype.some.call(more.querySelectorAll('input[type="text"], select, input[type="checkbox"]'), function (el) {
			if (el.type === 'checkbox') {
				return el.checked;
			}
			if (el.multiple) {
				return el.selectedOptions.length > 0;
			}
			return el.value !== '' && el.value !== '-1' && el.value !== '0';
		});
		if (active || table.classList.contains('rm-showfilters')) {
			document.body.classList.add('rm-so-morefilters');
			table.classList.add('rm-showfilters');
			toggle.textContent = T.filtershide;
		}
		toggle.addEventListener('click', function () {
			// The common listener (registered first) has already toggled the filter row of the table
			document.body.classList.toggle('rm-so-morefilters', table.classList.contains('rm-showfilters'));
		});
	}

	/* ---------- Réception des produits (dispatch.php) ---------- */

	function initDispatch(pm) {
		var reset = document.getElementById('autoreset');
		var table = reset ? reset.closest('table') : null;
		if (!table) {
			return;
		}
		table.classList.add('rm-so-dispatch');
		markWrapper(table);
		var head = reset.closest('tr');
		head.classList.add('rm-so-disphead');
		var labels = headLabels(pm, head);
		// Title row: keep only the global commands (« Vider », warehouse « Forcer à »)
		Array.prototype.forEach.call(head.cells, function (cell) {
			if (cell.querySelector('#autoreset')) {
				cell.classList.add('rm-so-dispreset');
			} else if (cell.querySelector('select')) {
				cell.classList.add('rm-so-dispwarehouse');
			} else {
				cell.classList.add('rm-so-empty');
			}
		});
		var current = null;
		Array.prototype.forEach.call(table.rows, function (tr) {
			if (tr === head) {
				return;
			}
			var pos = positions(tr);
			var form = !!tr.querySelector('input.qtydispatchinput, input[name^="lot_number"]');
			tr.classList.add(form ? 'rm-so-dispform' : 'rm-so-dispinfo');
			if (!form) {
				current = tr;
			} else if (current) {
				tr.classList.add('rm-so-dispfollow');
			}
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				var label = labels[pos[i]] || '';
				if (!form && i === 0) {
					td.classList.add('rm-so-dispdesc');
					return;
				}
				// « This product does not use lot/serial numbers »: obvious, takes room on the card
				if (!form && td.classList.contains('dispatch_batch_number') && !td.querySelector('a, input')) {
					td.classList.add('rm-so-empty');
					return;
				}
				if (td.querySelector('input.qtydispatchinput')) {
					td.classList.add('rm-so-dispqty');
				} else if (td.querySelector('.splitbutton')) {
					td.classList.add('rm-so-dispsplit');
					return;
				} else if (td.querySelector('select[name^="entrepot"]')) {
					td.classList.add('rm-so-dispwh');
				}
				if (isEmptyCell(pm, td) || (form && td.colSpan > 1 && !td.querySelector('input:not([type="hidden"]), select'))) {
					td.classList.add('rm-so-empty');
					return;
				}
				if (form && !td.classList.contains('rm-so-dispqty') && !td.classList.contains('rm-so-dispwh')) {
					td.classList.add('rm-so-dispfield');	// lot number, sell-by / eat-by dates, price...
				}
				if (label !== '') {
					td.setAttribute('data-rm-label', label);
				}
			});
		});
		// Products already received (lines of receptions)
		cardTable(pm, document.getElementById('dispatch_received_products'));
	}

	/* ---------- Onglets ---------- */

	function initTabs(pm, file) {
		if (file === 'contact') {
			// Add forms: one field per line; list of the contacts in cards
			var contacthead = document.querySelector('.tableforcontact form.tagtr.liste_titre');
			var contactlabels = [];
			if (contacthead) {
				contacthead.classList.add('rm-so-contacthead');
				Array.prototype.forEach.call(contacthead.querySelectorAll(':scope > .tagtd'), function (cell, i) {
					contactlabels[i] = pm.titleText(cell);
				});
			}
			Array.prototype.forEach.call(document.querySelectorAll('.tableforcontact form.tagtr:not(.liste_titre)'), function (form) {
				form.classList.add('rm-so-contactadd');
				Array.prototype.forEach.call(form.querySelectorAll(':scope > .tagtd'), function (cell, i) {
					if (isEmptyCell(pm, cell)) {
						cell.classList.add('rm-so-empty');
					} else if (contactlabels[i] && !cell.querySelector('input[type="submit"], button')) {
						cell.setAttribute('data-rm-label', contactlabels[i]);
					}
				});
			});
			var contactwrap = document.querySelector('.tableforcontact');
			if (contactwrap) {
				markWrapper(contactwrap);
			}
			Array.prototype.forEach.call(document.querySelectorAll('table.tagtable.liste'), function (table) {
				cardTable(pm, table);
			});
		} else if (file === 'document') {
			cardTable(pm, document.getElementById('tablelines'));
		} else if (file === 'info' || file === 'agenda' || file === 'messaging') {
			Array.prototype.forEach.call(document.querySelectorAll('table.liste, .div-table-responsive-no-min > table.noborder'), function (table) {
				if (table.querySelector('tr.liste_titre') && table.querySelector('tr.oddeven')) {
					if (pm.small()) {
						foldFilterRow(pm, table);	// a button that does nothing on a computer
					}
					cardTable(pm, table);
				}
			});
		}
	}

	function init() {
		var pm = window.PichinovMobile;
		var page = pm ? pm.page() : null;
		if (!page || page.key !== 'supplierorder') {
			return;
		}
		var file = fileName();
		if (page.type === 'card') {
			initCard(pm);
		} else if (page.type === 'list') {
			initList(pm);
		} else if (file === 'dispatch') {
			initDispatch(pm);
		} else {
			initTabs(pm, file);
		}
	}

	// After the common script, which registers its own DOMContentLoaded listener first
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
