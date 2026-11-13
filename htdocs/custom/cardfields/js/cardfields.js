/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/*
 * Pichinov — Champs par fiche (cardfields)
 *
 * Mandatory and hidden fields of the header of a card, and survey of the fields shown by a card.
 * The header tables of the card are cut into pairs (label cell, value cell). A field is found by the names of
 * its inputs, by selectors (row or value cell) or by the text of its label cell.
 * - hidden: the cells of the field get the class hideobject (a row whose cells are all hidden is hidden as a
 *   whole); the inputs stay in the form, so their value is posted. A pair whose label Dolibarr marks as
 *   required (fieldrequired) is never hidden.
 * - mandatory: the label cell gets the class fieldrequired; the keys of the mandatory fields really present
 *   are posted in cardfields_present so that the server checks only them.
 * The lines of the card (#tablelines) and the lists are never touched.
 */
(function (window, $) {
	'use strict';

	if (!$) {
		return;
	}

	function norm(text) {
		return String(text || '').replace(/ /g, ' ').replace(/\s+/g, ' ').replace(/^\s+/, '').replace(/[\s:*]+$/, '').toLowerCase();
	}

	function labelText(cell) {
		var c = cell.clone();
		c.find('script, style, select, input, textarea, .classfortooltip[title]:empty').remove();
		return $.trim(c.text().replace(/ /g, ' ').replace(/\s+/g, ' '));
	}

	// Tables of the card header: forms (table.border) and consultation (table.tableforfield), not the lines, not the lists
	function headerTables() {
		return $('table.border, table.tableforfield').filter(function () {
			var t = $(this);
			if (t.is('#tablelines') || t.closest('#tablelines').length) {
				return false;
			}
			return !(t.hasClass('nobordernopadding') || t.hasClass('liste') || t.hasClass('noborder'));
		});
	}

	function rowsOf(table) {
		return table.children('tbody').children('tr').add(table.children('tr'));
	}

	// Pairs (label cell, value cell) of the header tables
	function scanPairs() {
		var pairs = [];
		headerTables().each(function () {
			rowsOf($(this)).each(function () {
				var row = $(this);
				var cells = row.children('td, th');
				for (var i = 0; i + 1 < cells.length; i += 2) {
					var lc = cells.eq(i);
					var vc = cells.eq(i + 1);
					var raw = labelText(lc);
					pairs.push({row: row, lc: lc, vc: vc, raw: raw, label: norm(raw)});
				}
			});
		});
		return pairs;
	}

	function pairOfCell(pairs, td) {
		for (var i = 0; i < pairs.length; i++) {
			if (pairs[i].vc[0] === td || pairs[i].lc[0] === td) {
				return pairs[i];
			}
		}
		return null;
	}

	function pairContaining(pairs, el) {
		for (var i = 0; i < pairs.length; i++) {
			if (pairs[i].vc[0] === el || $.contains(pairs[i].vc[0], el)) {
				return pairs[i];
			}
		}
		return null;
	}

	/*
	 * Units of a field: {pair: p} or {row: tr}
	 */
	function locate(field, pairs) {
		var units = [];
		var seen = [];
		function add(unit) {
			var el = unit.pair ? unit.pair.vc[0] : unit.row[0];
			if (seen.indexOf(el) >= 0) {
				return;
			}
			seen.push(el);
			units.push(unit);
		}
		var els = $();
		$.each(field.names || [], function (i, n) {
			els = els.add($('[name="' + String(n).replace(/"/g, '') + '"]').not('input[type=hidden]'));
		});
		$.each(field.sel || [], function (i, s) {
			try {
				els = els.add($(s));
			} catch (e) {
				// Bad selector: ignored
			}
		});
		els.each(function () {
			if ($(this).closest('#tablelines').length) {
				return;
			}
			if (this.tagName === 'TR') {
				add({row: $(this)});
				return;
			}
			var p = (this.tagName === 'TD' ? pairOfCell(pairs, this) : null) || pairContaining(pairs, this);
			if (p) {
				add({pair: p});
			}
		});
		var labels = $.map(field.labels || [], norm);
		if (labels.length) {
			$.each(pairs, function (i, p) {
				if (p.label !== '' && labels.indexOf(p.label) >= 0) {
					add({pair: p});
				}
			});
		}
		return units;
	}

	function coreRequired(unit) {
		var cell = unit.pair ? unit.pair.lc : unit.row.children('td').first();
		if (unit.row && unit.row.find('td.fieldrequired, span.fieldrequired').length) {
			return true;
		}
		return cell.hasClass('fieldrequired') || cell.find('.fieldrequired').length > 0;
	}

	function hide(units, keyOfUnit, ignoreCore) {
		var marked = [];
		$.each(units, function (i, unit) {
			if (coreRequired(unit) && ignoreCore.indexOf(keyOfUnit[i]) < 0) {
				return;	// Never hide a field that Dolibarr itself requires
			}
			if (unit.row) {
				unit.row.addClass('hideobject cardfieldshidden');
			} else {
				unit.pair.lc.add(unit.pair.vc).addClass('cardfieldsmarked');
				marked.push(unit.pair.row[0]);
			}
		});
		// A row whose cells are all hidden is hidden as a whole, else only the cells of the field
		$.each(marked, function (i, tr) {
			var row = $(tr);
			var cells = row.children('td, th');
			if (cells.filter('.cardfieldsmarked').length === cells.length) {
				row.addClass('hideobject cardfieldshidden');
			} else {
				cells.filter('.cardfieldsmarked').addClass('hideobject cardfieldshidden');
			}
		});
	}

	// On the consultation page, hide a half block of the card whose rows are all hidden
	function hideEmptyBlocks() {
		$('.fichecenter').children('.fichehalfleft, .fichehalfright').each(function () {
			var half = $(this);
			var tables = half.find('table.tableforfield, table.border').filter(function () {
				return !$(this).closest('#tablelines').length && !$(this).hasClass('nobordernopadding');
			});
			if (!tables.length) {
				return;
			}
			var others = half.children().filter(function () {
				var c = $(this);
				if (c.is('table') || c.is('br') || c.hasClass('underbanner') || c.hasClass('clearboth')) {
					return false;
				}
				return !(c.is('div') && c.children().length === 1 && c.children('table').length);
			});
			if (others.length) {
				return;
			}
			var visible = 0;
			tables.each(function () {
				rowsOf($(this)).each(function () {
					if (!$(this).hasClass('hideobject') && this.style.display !== 'none') {
						visible++;
					}
				});
			});
			if (!visible) {
				half.addClass('hideobject cardfieldshidden');
			}
		});
	}

	function markRequired(units) {
		$.each(units, function (i, unit) {
			var cell = unit.pair ? unit.pair.lc : unit.row.children('td').first();
			cell.addClass('fieldrequired');
		});
	}

	// Survey: send the pairs of the card (label, names of the inputs) to the setup of the module
	function survey(cfg, pairs) {
		var list = [];
		var done = {};
		$.each(pairs, function (i, p) {
			if (p.row.is('[class*="field_options_"], .trextrafieldseparator') || p.vc.is('[class*="_extras_"]')) {
				return;	// Extrafields: already in the setup
			}
			var inputs = p.vc.find('input[name], select[name], textarea[name]').not('input[type=hidden], input[type=submit], input[type=button], input[type=image], input[name=token], input[name=action]');
			var names = [];
			var canrequire = false;
			var empty = [];
			inputs.each(function () {
				var n = this.name;
				if (names.indexOf(n) < 0) {
					names.push(n);
				}
				var t = (this.type || '').toLowerCase();
				if (this.tagName === 'TEXTAREA' || (this.tagName === 'INPUT' && t !== 'checkbox' && t !== 'radio' && t !== 'file')) {	// A file is not posted with the form values
					canrequire = true;
				}
				if (this.tagName === 'SELECT') {
					var blanks = $(this).find('option').filter(function () {
						return $.trim($(this).text().replace(/ /g, ' ')) === '' || this.value === '' || this.value === '-1';
					});
					if (blanks.length || this.multiple) {
						canrequire = true;
						blanks.each(function () {
							if (empty.indexOf(this.value) < 0) {
								empty.push(this.value);
							}
						});
					}
				}
			});
			var label = p.raw;
			if (label === '' && !names.length) {
				return;
			}
			var id = norm(label) + '|' + names.join(',');
			if (done[id]) {
				return;
			}
			done[id] = 1;
			list.push({label: label.substring(0, 250), names: names.slice(0, 20), empty: empty.slice(0, 20), canrequire: canrequire ? 1 : 0, core: (p.lc.hasClass('fieldrequired') || p.lc.find('.fieldrequired').length) ? 1 : 0});
		});
		$.post(cfg.survey.url, {token: cfg.survey.token, objkey: cfg.survey.objkey, mode: cfg.mode, fields: JSON.stringify(list)}, function (res) {
			var msg = (res && res.message) ? res.message : (cfg.survey.done || 'Survey') + ' : ' + list.length;
			if ($.jnotify) {
				$.jnotify(msg, (res && res.ok) ? 'ok' : 'error', true);
			} else {
				window.alert(msg);
			}
		}, 'json').fail(function () {
			if ($.jnotify) {
				$.jnotify(cfg.survey.failed || 'Survey failed', 'error', true);
			}
		});
	}

	var CardFields = {
		norm: norm,
		scanPairs: scanPairs,
		locate: locate,

		apply: function (cfg) {
			cfg = cfg || {};
			var fields = cfg.fields || {};
			var hidden = cfg.hidden || [];
			var required = cfg.required || [];
			var ignoreCore = cfg.ignoreCore || [];
			var pairs = scanPairs();

			// Survey first: it must record the marks of Dolibarr, not the ones this script adds
			if (cfg.survey) {
				survey(cfg, pairs);
			}

			// Hidden fields first: the mark of a mandatory field must not protect another field of the row
			var hunits = [];
			var hkeys = [];
			$.each(hidden, function (i, key) {
				if (!fields[key]) {
					return;
				}
				$.each(locate(fields[key], pairs), function (j, unit) {
					hunits.push(unit);
					hkeys.push(key);
				});
			});
			hide(hunits, hkeys, ignoreCore);

			var present = [];
			var form = null;
			$.each(required, function (i, key) {
				if (!fields[key]) {
					return;
				}
				var units = locate(fields[key], pairs);
				if (!units.length) {
					return;
				}
				present.push(key);
				markRequired(units);
				if (!form) {
					var anchor = units[0].pair ? units[0].pair.vc : units[0].row;
					var f = anchor.closest('form');
					if (f.length) {
						form = f;
					}
				}
			});
			if (!form) {
				// No mandatory field in this form (another variant of the form): post an empty list all the same,
				// so that the server does not require fields this form does not have
				var f2 = headerTables().first().closest('form');
				if (f2.length) {
					form = f2;
				}
			}
			if (form && (cfg.mode === 'create' || cfg.mode === 'edit')) {
				form.find('input[name=cardfields_present]').remove();
				form.append($('<input type="hidden" name="cardfields_present">').val(present.join(',')));
			}

			if (cfg.mode === 'view' && cfg.hideEmptyBlocks) {
				hideEmptyBlocks();
			}

			return {present: present, pairs: pairs.length};
		}
	};

	window.CardFields = CardFields;
})(window, window.jQuery);
