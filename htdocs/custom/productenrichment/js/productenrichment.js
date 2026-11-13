/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Panel of the module productenrichment.
 *  - mode "form": product creation form, the proposals fill the fields of the form; photos and
 *    characteristics chosen travel in hidden fields and are applied by the PRODUCT_CREATE trigger
 *  - mode "card": existing product, the proposals are applied to its EMPTY fields (ajax/apply.php)
 * Every text coming from the lookup is inserted as text (never as html).
 */
(function ($) {
	'use strict';

	var PE = {
		cfg: null,
		data: null,

		t: function (key) {
			return (PE.cfg.i18n && PE.cfg.i18n[key]) ? PE.cfg.i18n[key] : key;
		},

		post: function (url, params, done) {
			params.token = PE.cfg.token;
			$.ajax({url: url, type: 'POST', data: params, dataType: 'json', timeout: 150000})
				.done(function (r) { done(r); })
				.fail(function (xhr) { done({ok: false, error: 'http', message: 'HTTP ' + xhr.status}); });
		},

		el: function (tag, cls, text) {
			var e = $('<' + tag + '>');
			if (cls) { e.addClass(cls); }
			if (text !== undefined && text !== null) { e.text(String(text)); }
			return e;
		},

		safeUrl: function (u) {
			return (typeof u === 'string' && /^https?:\/\//i.test(u)) ? u : '';
		},

		/* ---------- lookup ---------- */

		eanInput: function () {
			if (PE.cfg.mode === 'form' && $('input[name=barcode]').length) { return $('input[name=barcode]').first(); }
			return $('#pe_ean');
		},

		run: function (withai) {
			var ean = $.trim(PE.eanInput().val() || '');
			if (!ean) { PE.status(PE.t('NeedEan'), 'error'); $('#pe_row').show(); return; }
			if (PE.cfg.mode === 'form') {
				$('#pe_row').show();
				// The supplier EAN of the creation form is the same code
				if ($('input[name=fourn_barcode]').length && !$.trim($('input[name=fourn_barcode]').val())) { $('input[name=fourn_barcode]').val(ean); }
			}
			var params = {ean: ean, ai: withai ? 1 : 0};
			if (PE.cfg.mode === 'card') {
				params.fk_product = PE.cfg.fkProduct;
			} else {
				params.label = $('input[name=label]').val() || '';
				params.socid = $('select[name=fourn_socid]').val() || 0;
			}
			PE.status(withai ? PE.t('SearchingAi') : PE.t('Searching'), 'wait');
			$('#pe_go, #pe_ai').addClass('butActionRefused').prop('disabled', true);
			PE.post(PE.cfg.urlEnrich, params, function (r) {
				$('#pe_go, #pe_ai').removeClass('butActionRefused').prop('disabled', false);
				if (!r || !r.ok) { PE.status((r && r.message) ? r.message : PE.t('Error'), 'error'); return; }
				PE.data = r;
				PE.status(PE.stepsText(r), r.found ? 'ok' : 'warning');
				if (PE.cfg.aiEnabled) { $('#pe_ai').show(); }
				PE.render();
			});
		},

		stepsText: function (r) {
			var names = {upc: 'UPCitemdb', ebay: 'eBay', ai: PE.t('Ai')};
			var parts = [];
			$.each(r.steps || {}, function (k, v) {
				var more = (k === 'ebay' && r.info && r.info.ebay_keyword && (v === 'ok' || v === 'cached')) ? ' (' + PE.t('EbayKeyword').replace('{m}', r.info.ebay_keyword) + ')' : '';
				parts.push(names[k] + ' : ' + PE.t('Step_' + v) + more);
			});
			return (r.found ? PE.t('Found') : PE.t('NotFound')) + ' (' + parts.join(', ') + ')';
		},

		status: function (msg, kind) {
			var s = $('#pe_status').text(msg).removeClass('pe-ok pe-error pe-warning pe-wait');
			if (kind) { s.addClass('pe-' + kind); }
		},

		/* ---------- fields of the creation form ---------- */

		fieldVal: function (name) {
			if (name === 'desc') {
				if (typeof CKEDITOR === 'object' && CKEDITOR.instances && CKEDITOR.instances.desc) {
					return $.trim($('<div>').html(CKEDITOR.instances.desc.getData()).text());
				}
				return $.trim($('textarea[name=desc]').val() || '');
			}
			return $.trim($('[name=' + name + ']').first().val() || '');
		},

		hasField: function (name) {
			return name === 'desc' ? ($('textarea[name=desc]').length > 0) : ($('[name=' + name + ']').length > 0);
		},

		setField: function (name, value, noconfirm) {
			value = String(value);
			var cur = PE.fieldVal(name);
			if (cur !== '' && cur !== value && !noconfirm) {
				if (!window.confirm(PE.t('ConfirmReplace').replace('{a}', cur.substring(0, 80)).replace('{b}', value.substring(0, 80)))) { return false; }
			}
			if (cur !== '' && noconfirm) { return false; }
			if (name === 'desc') {
				if (typeof CKEDITOR === 'object' && CKEDITOR.instances && CKEDITOR.instances.desc) {
					CKEDITOR.instances.desc.setData($('<div>').text(value).html().replace(/\n/g, '<br>'));
				} else {
					$('textarea[name=desc]').val(value).trigger('change');
				}
			} else {
				$('[name=' + name + ']').first().val(value).trigger('change');
			}
			// A field filled in a folded section must be visible
			$('[name=' + name + ']').first().closest('tr').removeClass('hideobject');
			return true;
		},

		/* ---------- rendering ---------- */

		render: function () {
			var r = PE.data, info = r.info || {}, panel = $('#pe_panel').empty().show();
			if (!r.found && !(info.images || []).length) {
				panel.append(PE.el('div', 'opacitymedium', PE.cfg.aiEnabled ? PE.t('NotFoundTryAi') : PE.t('NotFound')));
				return;
			}
			var head = PE.el('div', 'pe-head');
			head.append(PE.el('strong', '', info.title || ''));
			if (info.brand) { head.append(' ').append(PE.el('span', 'badge badge-secondary', info.brand)); }
			if (info.confiance) { head.append(' ').append(PE.el('span', 'opacitymedium', PE.t('Confidence') + ' : ' + info.confiance)); }
			panel.append(head);

			if (PE.cfg.mode === 'card') { PE.renderCard(panel, r); } else { PE.renderForm(panel, r); }

			PE.renderPhotos(panel, r);
			PE.renderSpecs(panel, r);
			PE.renderVariants(panel, info);
			PE.renderSources(panel, info);
			if (PE.cfg.mode === 'card') {
				var apply = PE.el('a', 'butAction', PE.t('Apply')).attr('href', '#').on('click', function (e) { e.preventDefault(); PE.applyCard(); });
				panel.append($('<div class="center pe-actions">').append(apply));
			}
		},

		row: function (table, label, value, action) {
			var tr = $('<tr class="oddeven">');
			tr.append(PE.el('td', 'titlefield', label));
			tr.append($('<td class="pe-value">').append(value));
			tr.append($('<td class="right nowraponall">').append(action || ''));
			table.append(tr);
			return tr;
		},

		fillButton: function (name, value, label) {
			return PE.el('a', 'button smallpaddingimp', label || PE.t('Fill')).attr('href', '#').on('click', function (e) {
				e.preventDefault();
				var v = (typeof value === 'function') ? value() : value;
				if (PE.setField(name, v, false)) { $(this).addClass('pe-done'); }
			});
		},

		renderForm: function (panel, r) {
			var info = r.info || {}, t = $('<table class="noborder centpercent pe-table">');
			// Label: every candidate is clickable
			var labels = [];
			$.each([info.title].concat(info.titles || []).concat([info.famille]), function (i, l) { if (l && labels.indexOf(l) < 0) { labels.push(l); } });
			if (labels.length && PE.hasField('label')) {
				var chips = $('<div>');
				$.each(labels, function (i, l) {
					chips.append(PE.el('a', 'pe-chip', l).attr('href', '#').on('click', function (e) { e.preventDefault(); PE.setField('label', l, false); }));
				});
				PE.row(t, PE.t('Label'), chips, '');
			}
			if (info.description && PE.hasField('desc')) {
				PE.row(t, PE.t('Description'), PE.el('div', 'pe-desc', info.description), PE.fillButton('desc', function () { return PE.descriptionWithSpecs(); }));
			}
			if (info.mpn) {
				var acts = $('<span>');
				if (PE.hasField('options_ref_marque')) { acts.append(PE.fillButton('options_ref_marque', info.mpn, PE.t('ToBrandRef'))); }
				if (PE.hasField('fourn_ref')) { acts.append(' ').append(PE.fillButton('fourn_ref', info.mpn, PE.t('ToSupplierRef'))); }
				PE.row(t, PE.t('Mpn'), PE.el('span', '', info.mpn), acts);
			}
			if (r.ean) {
				var acts2 = $('<span>');
				if (PE.hasField('barcode')) { acts2.append(PE.fillButton('barcode', r.ean, PE.t('ToBarcode'))); }
				if (PE.hasField('fourn_barcode')) { acts2.append(' ').append(PE.fillButton('fourn_barcode', r.ean, PE.t('ToSupplierBarcode'))); }
				PE.row(t, PE.t('Ean'), PE.el('span', '', r.ean), acts2);
			}
			if (info.sell_price && PE.hasField('pqc_sell_price')) {
				PE.row(t, PE.t('SellPrice'), PE.el('span', '', info.sell_price + ' € TTC' + (info.prix_public && info.prix_public !== info.sell_price ? ' (' + info.prix_public + ')' : '')), PE.fillButton('pqc_sell_price', String(info.sell_price).replace('.', ','))).find('a').on('click', function () { $('select[name=pqc_sell_price_base_type]').val('TTC').trigger('change'); });
			}
			if (info.buy_price && PE.hasField('fourn_price')) {
				PE.row(t, PE.t('BuyPrice'), PE.el('span', '', info.buy_price + ' € HT (' + PE.t('Estimate') + ')'), PE.fillButton('fourn_price', String(info.buy_price).replace('.', ',')));
			}
			if (info.prix_marche) {
				PE.row(t, PE.t('MarketPrice'), PE.el('span', 'opacitymedium', info.prix_marche), '');
			}
			if (info.manufacturer_page && PE.hasField('options_supplier_url')) {
				PE.row(t, PE.t('ManufacturerPage'), $('<a target="_blank" rel="noopener noreferrer">').attr('href', PE.safeUrl(info.manufacturer_page)).text(info.manufacturer_page), PE.fillButton('options_supplier_url', info.manufacturer_page));
			}
			panel.append(t);
			var all = PE.el('a', 'button', PE.t('FillEmpty')).attr('href', '#').on('click', function (e) { e.preventDefault(); PE.fillEmpty(); });
			panel.append($('<div class="pe-actions">').append(all));
		},

		descriptionWithSpecs: function () {
			var r = PE.data, desc = (r.info && r.info.description) ? r.info.description : '', lines = [];
			$('#pe_panel .pe-spec').each(function () {
				var tr = $(this);
				if (!tr.find('.pe-spec-on').is(':checked') || !(parseInt(tr.find('.pe-spec-attr').val(), 10) > 0)) {
					lines.push(tr.data('key') + ' : ' + tr.data('value'));
				}
			});
			return desc + (lines.length ? '\n\n' + lines.join('\n') : '');
		},

		fillEmpty: function () {
			var info = PE.data.info || {};
			var pairs = [['label', info.title], ['desc', info.description ? PE.descriptionWithSpecs() : ''], ['options_ref_marque', info.mpn], ['fourn_ref', info.mpn],
				['barcode', PE.data.ean], ['fourn_barcode', PE.data.ean], ['pqc_sell_price', info.sell_price ? String(info.sell_price).replace('.', ',') : ''],
				['fourn_price', info.buy_price ? String(info.buy_price).replace('.', ',') : ''], ['options_supplier_url', info.manufacturer_page]];
			var n = 0;
			$.each(pairs, function (i, p) {
				if (p[1] && PE.hasField(p[0]) && PE.fieldVal(p[0]) === '') {
					if (PE.setField(p[0], p[1], true)) { n++; }
					if (p[0] === 'pqc_sell_price') { $('select[name=pqc_sell_price_base_type]').val('TTC').trigger('change'); }
				}
			});
			PE.status(PE.t('FieldsFilled').replace('{n}', n), 'ok');
		},

		renderCard: function (panel, r) {
			var p = r.proposals || {}, info = r.info || {}, t = $('<table class="noborder centpercent pe-table">');
			var hd = $('<tr class="liste_titre">');
			hd.append(PE.el('td', '', PE.t('Field'))).append(PE.el('td', '', PE.t('Current'))).append(PE.el('td', '', PE.t('Proposed'))).append(PE.el('td', 'center', PE.t('Apply')));
			t.append(hd);
			var fields = [['description', PE.t('Description')], ['ref_marque', PE.t('Mpn')], ['supplier_url', PE.t('ManufacturerPage')], ['barcode', PE.t('Ean')], ['sell_price', PE.t('SellPrice')]];
			$.each(fields, function (i, f) {
				var pr = p[f[0]];
				if (!pr || pr.proposed === '' || pr.proposed === null) { return; }
				var tr = $('<tr class="oddeven">');
				tr.append(PE.el('td', '', f[1]));
				tr.append(PE.el('td', 'opacitymedium pe-value', pr.current || ''));
				var shown = (f[0] === 'sell_price') ? pr.proposed + ' € TTC (' + PE.t('Estimate') + ')' : pr.proposed;
				tr.append($('<td class="pe-value">').append(PE.el('div', f[0] === 'description' ? 'pe-desc' : '', shown)));
				var cb = $('<input type="checkbox" class="pe-sel">').val(f[0]);
				if (pr.applicable) {
					cb.prop('checked', f[0] !== 'sell_price');
				} else {
					cb.prop('disabled', true);
				}
				var why = pr.why ? PE.el('div', 'opacitymedium small', PE.t('Why_' + pr.why.split(':')[0]) + (pr.why.indexOf(':') > 0 ? ' ' + pr.why.split(':')[1] : '')) : '';
				tr.append($('<td class="center">').append(cb).append(why));
				t.append(tr);
			});
			if (info.prix_marche) {
				var trm = $('<tr class="oddeven">');
				trm.append(PE.el('td', '', PE.t('MarketPrice'))).append(PE.el('td', '', '')).append(PE.el('td', 'opacitymedium', info.prix_marche)).append(PE.el('td', '', ''));
				t.append(trm);
			}
			panel.append(t);
		},

		renderPhotos: function (panel, r) {
			var imgs = (r.info && r.info.images) ? r.info.images : [];
			if (!imgs.length) { return; }
			var applicable = true, why = '';
			if (PE.cfg.mode === 'card' && r.proposals && r.proposals.images) {
				applicable = r.proposals.images.applicable;
				why = r.proposals.images.why;
			}
			var box = $('<div class="pe-photos">');
			box.append(PE.el('div', 'pe-title', PE.t('Photos') + ' (' + PE.t('MaxPhotos').replace('{n}', PE.cfg.maxImages) + ')' + (applicable ? '' : ' — ' + PE.t('Why_' + why))));
			$.each(imgs, function (i, u) {
				var url = PE.safeUrl(u);
				if (!url) { return; }
				var lab = $('<label class="pe-photo">');
				var cb = $('<input type="checkbox" class="pe-img">').val(url).prop('checked', applicable && i < PE.cfg.maxImages).prop('disabled', !applicable);
				lab.append(cb).append($('<img loading="lazy" referrerpolicy="no-referrer" alt="">').attr('src', url));
				box.append(lab);
			});
			box.on('change', '.pe-img', function () {
				if ($('#pe_panel .pe-img:checked').length > PE.cfg.maxImages) { $(this).prop('checked', false); }
				PE.syncHidden();
			});
			panel.append(box);
			PE.syncHidden();
		},

		renderSpecs: function (panel, r) {
			var specs = r.specs || [];
			if (!specs.length) { return; }
			var box = $('<div class="pe-specs">');
			box.append(PE.el('div', 'pe-title', PE.t('Characteristics')));
			if (!r.characteristics) {
				box.append(PE.el('div', 'opacitymedium small', PE.t('NoCharacteristicsModule')));
			} else if ($.isEmptyObject(r.attributes)) {
				box.append(PE.el('div', 'opacitymedium small', PE.t('NoFeatureAttribute')));
			}
			var t = $('<table class="noborder centpercent pe-table">');
			var already = {};
			if (PE.cfg.mode === 'card' && r.proposals && r.proposals.characteristics) {
				$.each(r.proposals.characteristics.proposed || [], function (i, s) { if (s.already) { already[s.key] = true; } });
			}
			$.each(specs, function (i, s) {
				var tr = $('<tr class="oddeven pe-spec">').data('key', s.key).data('value', s.value);
				tr.append(PE.el('td', '', s.key)).append(PE.el('td', '', s.value));
				var sel = $('<select class="flat pe-spec-attr">').append($('<option value="0">').text(PE.t('NoAttribute')));
				$.each(r.attributes || {}, function (id, label) { sel.append($('<option>').val(id).text(label)); });
				sel.val(String(s.fk_attribute || 0));
				var cb = $('<input type="checkbox" class="pe-spec-on">').prop('checked', s.fk_attribute > 0 && !already[s.key]);
				if (!r.characteristics || already[s.key]) { cb.prop('disabled', true); sel.prop('disabled', true); }
				tr.append($('<td>').append(sel)).append($('<td class="center">').append(cb).append(already[s.key] ? PE.el('div', 'opacitymedium small', PE.t('Why_filled')) : ''));
				t.append(tr);
			});
			t.on('change', 'select, input', function () {
				var tr = $(this).closest('tr');
				if ($(this).is('select')) { tr.find('.pe-spec-on').prop('checked', parseInt($(this).val(), 10) > 0); }
				PE.syncHidden();
			});
			box.append(t);
			panel.append(box);
			PE.syncHidden();
		},

		chosenSpecs: function () {
			var out = [];
			$('#pe_panel .pe-spec').each(function () {
				var tr = $(this), fk = parseInt(tr.find('.pe-spec-attr').val(), 10);
				if (tr.find('.pe-spec-on').is(':checked') && fk > 0) { out.push({key: String(tr.data('key')), fk_attribute: fk, value: String(tr.data('value'))}); }
			});
			return out;
		},

		chosenImages: function () {
			var out = [];
			$('#pe_panel .pe-img:checked').each(function () { out.push($(this).val()); });
			return out;
		},

		/* Hidden fields of the creation form, read by the PRODUCT_CREATE trigger */
		syncHidden: function () {
			if (PE.cfg.mode !== 'form') { return; }
			var h = $('#pe_hidden').empty();
			h.append($('<input type="hidden" name="pe_ean_used">').val(PE.data ? PE.data.ean : ''));
			h.append($('<input type="hidden" name="pe_specs">').val(JSON.stringify(PE.chosenSpecs())));
			$.each(PE.chosenImages(), function (i, u) { h.append($('<input type="hidden" name="pe_images[]">').val(u)); });
		},

		renderVariants: function (panel, info) {
			var v = info.declinaisons || [];
			if (!v.length) { return; }
			var box = $('<div class="pe-variants">');
			box.append(PE.el('div', 'pe-title', PE.t('Variants') + (info.famille ? ' — ' + info.famille : '')));
			box.append(PE.el('div', 'opacitymedium small', PE.t('VariantsInfo')));
			var ul = $('<ul>');
			$.each(v.slice(0, 20), function (i, d) {
				var txt = [d.code, d.libelle, d.ean].filter(function (x) { return x; }).join(' — ');
				var li = PE.el('li', (info.declinaison_scannee && d.code === info.declinaison_scannee) ? 'pe-current' : '', txt);
				ul.append(li);
			});
			box.append(ul);
			panel.append(box);
		},

		renderSources: function (panel, info) {
			var s = info.sources || [];
			if (!s.length) { return; }
			var box = $('<div class="pe-sources opacitymedium small">').append(document.createTextNode(PE.t('Sources') + ' : '));
			$.each(s.slice(0, 4), function (i, u) {
				var url = PE.safeUrl(u);
				if (!url) { return; }
				box.append($('<a target="_blank" rel="noopener noreferrer">').attr('href', url).text(url.replace(/^https?:\/\//, '').substring(0, 50))).append(' ');
			});
			panel.append(box);
		},

		/* ---------- product card ---------- */

		applyCard: function () {
			var sel = [];
			$('#pe_panel .pe-sel:checked').each(function () { sel.push($(this).val()); });
			var specs = PE.chosenSpecs(), images = PE.chosenImages();
			if (specs.length) { sel.push('characteristics'); }
			if (images.length) { sel.push('images'); }
			if (!sel.length) { PE.status(PE.t('NothingSelected'), 'warning'); return; }
			PE.status(PE.t('Applying'), 'wait');
			// jQuery sends an array as name[]=value: the keys must not end with [] themselves
			PE.post(PE.cfg.urlApply, {fk_product: PE.cfg.fkProduct, ean: PE.data.ean, sel: sel, specs: JSON.stringify(specs), images: images}, function (r) {
				if (!r || !r.ok) { PE.status((r && r.message) ? r.message : PE.t('Error'), 'error'); return; }
				var n = Object.keys(r.applied || {}).length;
				PE.status((n ? PE.t('Applied').replace('{n}', n) : PE.t('NothingApplied')) + ((r.errors && r.errors.length) ? ' — ' + r.errors.join(' ; ') : ''), (r.errors && r.errors.length) || !n ? 'warning' : 'ok');
				if (n) { setTimeout(function () { window.location.reload(); }, 1200); }
			});
		},

		openCardDialog: function () {
			var d = $('#pe_dialog');
			var w = $(window).width() || 1000, h = $(window).height() || 800;
			d.dialog({width: Math.max(320, Math.min(980, w - 20)), maxHeight: Math.max(400, h - 40), modal: true, title: PE.t('DialogTitle')});
			if (!PE.data && $('#pe_ean').val()) { PE.run(false); }
		},

		/* ---------- start ---------- */

		init: function (cfg) {
			PE.cfg = cfg;
			$(function () {
				$('#pe_go').on('click', function (e) { e.preventDefault(); PE.run(false); });
				$('#pe_ai').on('click', function (e) { e.preventDefault(); PE.run(true); });
				if (cfg.mode === 'form') {
					// After the scripts of the other modules (sections folded by productquickcreate)
					setTimeout(PE.initForm, 0);
				} else {
					$('#pe_ean').on('keydown', function (e) { if (e.keyCode === 13) { e.preventDefault(); PE.run(false); } });
					$('#pe_open').on('click', function (e) { e.preventDefault(); PE.openCardDialog(); });
					// Link "Enrich" of the Enrichment tab: open the dialog and look up at once
					if (cfg.autoOpen) { PE.openCardDialog(); }
				}
			});
		}
	};

	/* Creation form: the EAN comes first. Buttons on the right of the barcode field, panel just under it */
	PE.initForm = function () {
		var bc = $('input[name=barcode]').first();
		if (bc.length) {
			$('#pe_buttons').insertAfter(bc);
			$('#pe_row').insertAfter(bc.closest('tr')).hide();
			$('#pe_eanbox').hide();
			// A scanner ends with Enter: look up instead of submitting the form
			bc.on('keydown', function (e) { if (e.keyCode === 13) { e.preventDefault(); PE.run(false); } });
		} else {
			var labelrow = $('input[name=label]').closest('tr');
			if (labelrow.length) { $('#pe_row').insertAfter(labelrow); }
			$('#pe_eanbox').show();
			$('#pe_ean').on('keydown', function (e) { if (e.keyCode === 13) { e.preventDefault(); PE.run(false); } });
		}
		if (PE.cfg.autoRun && $.trim(PE.eanInput().val())) {
			// EAN given by the reception: show its field (it may be in a folded section) and look it up
			PE.eanInput().closest('tr').removeClass('hideobject');
			PE.run(false);
		}
	};

	/* Reception card: an EAN scanned in the product search of the line and found nowhere can create the product */
	PE.initReception = function (cfg) {
		$(function () {
			var box = null;
			$(document).on('autocompleteresponse', '#search_idprodfournprice', function (e, ui) {
				var input = $(this), term = $.trim(input.val() || '');
				if (!box) { box = $('<span id="pe_create_ean" class="nowraponall"></span>').hide(); input.after(box); }
				box.empty().hide();
				if ((!ui || !ui.content || !ui.content.length) && /^\d{8,14}$/.test(term)) {
					box.append(' ').append($('<a href="#" class="button smallpaddingimp">').text(cfg.texts.create.replace('{e}', term)).on('click', function (ev) {
						ev.preventDefault();
						PE.openCreateDialog(cfg, term);
					})).show();
				}
			});
			$(document).on('input', '#search_idprodfournprice', function () { if (box) { box.hide(); } });
		});
	};

	/* Same dialog as the "New product" button of the line, with the barcode and the supplier EAN filled */
	PE.openCreateDialog = function (cfg, ean) {
		var d = $('#pe_dialog_create');
		if (!d.length) { d = $('<div id="pe_dialog_create" class="hidden"></div>').appendTo('body'); }
		var frame = $('<iframe class="iframedialog" style="border: 0px;" width="100%" height="98%"></iframe>');
		frame.attr('src', cfg.url + '&barcode=' + encodeURIComponent(ean) + '&fourn_barcode=' + encodeURIComponent(ean));
		d.empty().append(frame);
		d.dialog({autoOpen: false, modal: true, height: Math.max(400, window.innerHeight - 150), width: '80%', title: cfg.texts.title});
		d.dialog('open');
	};

	window.ProductEnrichment = PE;
})(jQuery);
