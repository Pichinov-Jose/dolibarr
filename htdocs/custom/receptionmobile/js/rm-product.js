/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile : script propre à l'objet « produit (fiche, onglets, liste) ». Chargé après js/receptionmobile.js (script commun) ;
 * utiliser window.PichinovMobile (small, text, titleText, page, labelLines, labelList, markCreateRow).
 * Un seul auteur par fichier : la session de cet objet.
 *
 * Pose seulement des classes ; la mise en forme est dans css/rm-product.css, sous 600 px de large :
 * - formulaires (fiche en modification, transfert / correction de stock, prix d'achat, TVA) : table.rm-pstack,
 *   cellules rm-plabel (libellé, au-dessus) et rm-pfield (champ, pleine largeur) ; le formulaire de création
 *   (action add) est laissé au module productquickcreate ;
 * - tableaux des onglets (stock par entrepôt, historique des prix, prix fournisseurs, événements, objets référents,
 *   marges, kits) : table.rm-pcards, une fiche par ligne, libellé de colonne sur chaque cellule (data-rm-label),
 *   cellule principale rm-pmain, boutons modifier / supprimer rm-pactions, cellules vides rm-empty ;
 * - liste des produits : libellé pleine largeur, filtres sans champ masqués, recherche par réf. / libellé /
 *   code-barres pleine largeur ;
 * - filtres des tableaux d'onglets derrière un bouton « Filtres » (a.rm-pfiltertoggle, masqué sur ordinateur) ;
 * - fichiers joints de la fiche : table.rm-pfiles ; barre de filtres de la chronologie : table.rm-pfilterbar ;
 * - graphiques des statistiques : table.rm-pgraph.
 */
(function () {
	'use strict';

	var pm = null;
	var en = ((document.documentElement.getAttribute('lang') || 'fr').substring(0, 2) === 'en');
	var T = {
		filters: en ? 'Filters' : 'Filtres',
		filtershide: en ? 'Hide the filters' : 'Masquer les filtres'
	};

	function cellText(el) {
		return pm.text(el);
	}

	/* A field the user can type in or choose from (hidden inputs and buttons excluded) */
	function hasField(el) {
		return !!el.querySelector('input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="image"]), select, textarea');
	}

	/* ---------- Formulaires : libellé au-dessus du champ ---------- */

	function stackForms() {
		var tables = document.querySelectorAll('div.fiche form table.border, form[name="formprod"] table.border');
		Array.prototype.forEach.call(tables, function (table) {
			if (table.classList.contains('rm-pstack') || table.classList.contains('liste') || table.id === 'tablelines' || !hasField(table)) {
				return;
			}
			// The creation form (action add) belongs to the module productquickcreate, which lays it out itself
			var owner = table.closest('form');
			if (owner && owner.querySelector('input[name="action"][value="add"]')) {
				return;
			}
			table.classList.add('rm-pstack');
			Array.prototype.forEach.call(table.rows, function (tr) {
				if (tr.parentNode.parentNode !== table) {
					return;	// Row of a table nested in a cell
				}
				Array.prototype.forEach.call(tr.cells, function (td) {
					if (td.colSpan > 1 && !hasField(td)) {
						td.classList.add('rm-psection');
					} else if (hasField(td) || td.querySelector('.cke, .select2-container, textarea')) {
						td.classList.add('rm-pfield');
					} else {
						td.classList.add('rm-plabel');
					}
				});
			});
			var form = table.closest('form');
			if (form) {
				form.classList.add('rm-pform');
			}
		});
	}

	/* ---------- Tableaux des onglets : une fiche par ligne ---------- */

	/* Column of each cell, colspans included */
	function columnsOf(tr) {
		var cols = [];
		var col = 0;
		Array.prototype.forEach.call(tr.cells, function (td) {
			cols.push(col);
			col += td.colSpan || 1;
		});
		return cols;
	}

	/* Filters of a tab table (events, referring objects): behind a button, shown at once when one is in use */
	function filterToggle(table, tr) {
		var wrapper = table.closest('.div-table-responsive, .div-table-responsive-no-min') || table;
		if (!wrapper.parentNode || wrapper.parentNode.querySelector(':scope > a.rm-pfiltertoggle')) {
			return;
		}
		var active = Array.prototype.some.call(tr.querySelectorAll('input[type="text"], input:not([type]), select'), function (el) {
			return el.value !== '' && el.value !== '-1' && el.value !== '0';
		});
		var link = document.createElement('a');
		link.href = '#';
		link.className = 'rm-pfiltertoggle button smallpaddingimp';
		link.textContent = active ? T.filtershide : T.filters;
		if (active) {
			table.classList.add('rm-pshowfilters');
		}
		link.addEventListener('click', function (e) {
			e.preventDefault();
			var shown = table.classList.toggle('rm-pshowfilters');
			link.textContent = shown ? T.filtershide : T.filters;
		});
		wrapper.parentNode.insertBefore(link, wrapper);
	}

	function isActionsCell(td) {
		if (cellText(td) !== '') {
			return false;
		}
		return !!td.querySelector('a .fa-pencil-alt, a .fa-trash, a .fa-trash-alt, a.editfielda, a .fa-edit, a img[src*="edit"], a img[src*="delete"], a .fa-clone, a .fa-sync');
	}

	function cardTable(table) {
		if (!table || table.classList.contains('rm-pcards')) {
			return;
		}
		var head = null;
		Array.prototype.some.call(table.rows, function (tr) {
			if (tr.classList.contains('liste_titre') && !tr.classList.contains('liste_titre_filter')) {
				head = tr;
				return true;
			}
			return false;
		});
		var labels = {};
		if (head) {
			var hcols = columnsOf(head);
			Array.prototype.forEach.call(head.cells, function (cell, i) {
				var label = pm.titleText(cell);
				if (label !== '') {
					labels[hcols[i]] = label;
				}
			});
		}
		table.classList.add('rm-pcards');
		var wrapper = table.parentNode;
		if (wrapper && /div-table-responsive/.test(wrapper.className)) {
			wrapper.classList.add('rm-pwrap');
		}
		Array.prototype.forEach.call(table.rows, function (tr) {
			if (tr.parentNode.parentNode !== table || tr === head) {
				return;
			}
			var cols = columnsOf(tr);
			if (tr.classList.contains('liste_titre_filter')) {
				tr.classList.add('rm-pfilterrow');
				Array.prototype.forEach.call(tr.cells, function (td, i) {
					if (td.querySelector('.button_search, button[name^="button_search"]')) {
						td.classList.add('rm-psearchcell');
					} else if (!hasField(td)) {
						td.classList.add('rm-empty');
					} else if (labels[cols[i]]) {
						td.setAttribute('data-rm-label', labels[cols[i]]);
					}
				});
				filterToggle(table, tr);
				return;
			}
			if (tr.classList.contains('liste_titre')) {
				return;
			}
			var mainset = false;
			Array.prototype.forEach.call(tr.cells, function (td, i) {
				if (tr.cells.length === 1) {
					td.classList.add('rm-pwide');
					return;
				}
				if (isActionsCell(td)) {
					td.classList.add('rm-pactions');
					return;
				}
				var txt = cellText(td);
				if (txt === '' && !td.querySelector('img, span.badge, input:not([type="hidden"]), select, span[class*="fa-"]')) {
					td.classList.add('rm-empty');
					return;
				}
				if (!mainset) {
					// A user (author of a price, owner of an event) never leads the card
					var link = td.querySelector('a[href*="card.php"]:not([href*="/user/"])');
					if (link && cellText(link) !== '') {
						td.classList.add('rm-pmain');
						mainset = true;
						return;
					}
				}
				if (labels[cols[i]]) {
					td.setAttribute('data-rm-label', labels[cols[i]]);
				}
				if (txt.length > 28) {
					td.classList.add('rm-pwide');
				}
			});
			// No linked cell (price history, totals): the first filled cell leads the card
			if (!mainset) {
				Array.prototype.some.call(tr.cells, function (td) {
					if (!td.classList.contains('rm-empty') && !td.classList.contains('rm-pactions') && !td.classList.contains('rm-pwide')) {
						td.classList.add('rm-pmain');
						td.removeAttribute('data-rm-label');
						return true;
					}
					return false;
				});
			}
		});
	}

	/* Tables of the tabs that are wider than the screen: every list-like table of the page (a title row and
	   lines), the main fields table of the card and forms excepted */
	function cardTables() {
		var tables = document.querySelectorAll('div.fiche table.noborder, div.fiche table.liste, div.fiche table#tablelines');
		Array.prototype.forEach.call(tables, function (table) {
			if (table.classList.contains('rm-pstack') || table.classList.contains('formdoc') || table.closest('.rm-pcards')) {
				return;
			}
			if (table.querySelector('.dolgraph, .nographyet')) {
				return;
			}
			if (!table.querySelector('tr.liste_titre') || !table.querySelector('tr.oddeven, tr.liste_titre_filter, tr.liste_total')) {
				return;
			}
			// Narrow tables (prices per level) stay tables while they fit in the screen
			var head = table.querySelector('tr.liste_titre:not(.liste_titre_filter)');
			var box = table.parentNode;
			var overflows = pm.small() && box && table.getBoundingClientRect().width > box.clientWidth + 1;
			if (!head || (head.cells.length < 4 && !overflows)) {
				return;
			}
			cardTable(table);
		});
	}

	/* Attached files block of the card (formdoc): each file on its lines, name first, no horizontal scroll */
	function wrapFileRows() {
		Array.prototype.forEach.call(document.querySelectorAll('div.fiche table.formdoc'), function (table) {
			table.classList.add('rm-pfiles');
			var wrapper = table.parentNode;
			if (wrapper && /div-table-responsive/.test(wrapper.className)) {
				wrapper.classList.add('rm-pwrap');
			}
			Array.prototype.forEach.call(table.querySelectorAll('tr.oddeven'), function (tr) {
				Array.prototype.forEach.call(tr.cells, function (td, i) {
					if (i === 0) {
						td.classList.add('rm-pfilename');
					} else if (cellText(td) === '' && !td.querySelector('a, img, input, span[class*="fa-"]')) {
						td.classList.add('rm-empty');
					}
				});
			});
		});
	}

	/* Filter bar of the events timeline (one row of filters above the timeline): it wraps */
	function wrapFilterBars() {
		Array.prototype.forEach.call(document.querySelectorAll('div.fiche form.listactionsfilter table'), function (table) {
			if (!table.classList.contains('rm-pcards') && table.rows.length === 1) {
				table.classList.add('rm-pfilterbar');
			}
		});
	}

	/* ---------- Statistiques : graphiques à la largeur de l'écran ---------- */

	function fitGraphs() {
		Array.prototype.forEach.call(document.querySelectorAll('div.fiche .dolgraph, div.fiche .nographyet'), function (graph) {
			var table = graph.closest('table');
			if (table) {
				table.classList.add('rm-pgraph');
			}
		});
	}

	/* ---------- Liste des produits ---------- */

	function tuneList() {
		var table = document.querySelector('table.liste');
		if (!table) {
			return;
		}
		var filter = table.querySelector('tr.liste_titre_filter');
		var labelcol = -1;
		if (filter) {
			Array.prototype.forEach.call(filter.cells, function (td, i) {
				if (td.querySelector('input[name="search_label"]')) {
					labelcol = i;
				}
				if (td.querySelector('input[name="search_ref"], input[name="search_label"], input[name="search_barcode"]')) {
					td.classList.add('rm-wide');	// The fields used most: the whole width
				} else if (td.querySelector('.button_search, button[name="button_search"], input[name="button_search"]')) {
					td.classList.add('rm-psearchcell');
				} else if (!hasField(td)) {
					td.classList.add('rm-empty');	// Column without a filter (photo, stock...)
				}
			});
		}
		if (labelcol < 0) {
			return;
		}
		Array.prototype.forEach.call(table.querySelectorAll('tr.oddeven'), function (tr) {
			var td = tr.cells[labelcol];
			if (td && !td.classList.contains('rm-empty')) {
				td.classList.add('rm-plabelcell');
			}
		});
	}

	function init() {
		pm = window.PichinovMobile;
		var page = pm ? pm.page() : null;
		if (!page || page.key !== 'product') {
			return;
		}
		if (page.type === 'list') {
			tuneList();
			return;
		}
		stackForms();
		cardTables();
		wrapFileRows();
		wrapFilterBars();
		fitGraphs();
	}

	// After the common script, which registers its own DOMContentLoaded listener first
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
