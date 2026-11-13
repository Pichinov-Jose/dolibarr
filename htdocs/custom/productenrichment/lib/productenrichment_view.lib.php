<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
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
 * Display of a stored enrichment result, shared by the data screen and the Enrichment tab of the product.
 * Everything comes from outside (UPCitemdb, eBay, AI): every value is escaped, links are http(s) only.
 */

/**
 * CSS of the display
 *
 * @return string
 */
function peViewCss()
{
	return '<style>
	.pe-badge { display: inline-block; padding: 1px 7px; margin: 1px 3px 1px 0; border-radius: 10px; font-size: 0.85em; border: 1px solid #bbb; white-space: nowrap; }
	.pe-b-upc { background: #eef4ff; border-color: #9bb4e0; }
	.pe-b-ebay { background: #fff6e5; border-color: #e0b866; }
	.pe-b-ai { background: #efe8ff; border-color: #a58be0; }
	.pe-b-none { background: #f3f3f3; color: #777; }
	.pe-b-warn { background: #fdecea; border-color: #e09a92; color: #9b2a1e; }
	.pe-b-ok { background: #e8f5e9; border-color: #8fc59a; color: #24642f; }
	.pe-b-info { background: #f1f1f1; border-color: #bbb; color: #555; }
	.pe-photos img { height: 110px; max-width: 160px; object-fit: contain; vertical-align: middle; margin: 2px 6px 2px 0; border: 1px solid #ddd; background: #fff; }
	.pe-pre { white-space: pre-wrap; word-break: break-word; }
	.pe-kv td:first-child { width: 30%; }
	.pe-small-photo { height: 36px; max-width: 54px; object-fit: contain; vertical-align: middle; border: 1px solid #eee; background: #fff; }
	</style>';
}

/**
 * A safe external link
 *
 * @param string $url  Url
 * @param int    $trunc Length of the text
 * @return string       Html, empty when the url is not http(s)
 */
function peViewLink($url, $trunc = 60)
{
	$url = trim((string) $url);
	if (!preg_match('#^https?://#i', $url)) {
		return '';
	}
	return '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag(dol_trunc(preg_replace('#^https?://#i', '', $url), $trunc)).'</a>';
}

/**
 * Badges of the sources of a result
 *
 * @param array<string,mixed> $info Result
 * @return string                   Html
 */
function peViewSourceBadges($info)
{
	global $langs;

	$out = '';
	if (ProductEnrichment::upcFound($info)) {
		$out .= '<span class="pe-badge pe-b-upc">UPCitemdb</span>';
	}
	if (!empty($info['ebay'])) {
		if (!empty($info['ebay_mismatch'])) {
			$out .= '<span class="pe-badge pe-b-warn" title="'.dol_escape_htmltag(implode(' | ', (array) ($info['ebay_rejected'] ?? array()))).'">eBay '.$langs->trans('PeEbayRejectedShort').'</span>';
		} elseif (count((array) ($info['titles'] ?? array())) && !empty($info['ebay_keyword'])) {
			$out .= '<span class="pe-badge pe-b-ebay" title="'.dol_escape_htmltag($langs->trans('PeEbayKeywordTooltip', ProductEnrichment::ebayMarketLabel((string) ($info['ebay_market'] ?? '')))).'">eBay · '.$langs->trans('PeEbayKeywordShort').'</span>';
		} elseif (count((array) ($info['titles'] ?? array()))) {
			$out .= '<span class="pe-badge pe-b-ebay">eBay</span>';
		} else {
			$out .= '<span class="pe-badge pe-b-none">eBay '.$langs->trans('PeNothingShort').'</span>';
		}
	}
	if (!empty($info['ai'])) {
		$out .= '<span class="pe-badge pe-b-ai">'.$langs->trans('PeAi').((string) ($info['confiance'] ?? '') !== '' ? ' · '.dol_escape_htmltag((string) $info['confiance']) : '').'</span>';
	}
	if (!empty($info['pe_override'])) {
		$out .= '<span class="pe-badge pe-b-info" title="'.dol_escape_htmltag($langs->trans('PeCorrectedTooltip')).'">'.$langs->trans('PeCorrected').'</span>';
	}
	return $out;
}

/**
 * Badges of the anomalies
 *
 * @param string[] $codes See ProductEnrichment::anomalies()
 * @return string         Html
 */
function peViewAnomalyBadges($codes)
{
	global $langs;

	$out = '';
	foreach ((array) $codes as $c) {
		if ($c == 'ebay_rejected') {
			continue;	// Already shown as a source badge
		}
		$out .= '<span class="pe-badge pe-b-warn" title="'.dol_escape_htmltag($langs->trans('PeAnomalyTip_'.$c)).'">'.$langs->trans('PeAnomaly_'.$c).'</span>';
	}
	return $out;
}

/**
 * Has a result found anything
 *
 * @param array<string,mixed> $info Result
 * @return bool
 */
function peViewHasData($info)
{
	return ((string) ($info['title'] ?? '') !== '' || count((array) ($info['titles'] ?? array())) || (string) ($info['desc_courte'] ?? '') !== '' || count(ProductEnrichment::infoImages($info)));
}

/**
 * Products carrying the EAN, with their coherence with the result
 *
 * @param array<int,array{id:int,ref:string,label:string,via:string}> $products Products
 * @param array<string,mixed>                                          $info     Result
 * @param bool                                                         $tablink  Link to the Enrichment tab instead of the card
 * @return string Html
 */
function peViewProducts($products, $info, $tablink = false)
{
	global $langs;

	if (!count($products)) {
		return '<span class="opacitymedium">'.$langs->trans('PeNoProduct').'</span>';
	}
	$out = array();
	foreach ($products as $p) {
		$url = ($tablink ? dol_buildpath('/productenrichment/tab.php', 1).'?id='.((int) $p['id']) : DOL_URL_ROOT.'/product/card.php?id='.((int) $p['id']));
		$ok = ProductEnrichment::resultMatchesLabel((string) $p['label'], $info);
		$out[] = '<a href="'.$url.'">'.img_picto('', 'product', 'class="pictofixedwidth"').dol_escape_htmltag($p['ref']).'</a> <span class="opacitymedium">'.dol_escape_htmltag(dol_trunc($p['label'], 45)).'</span>'
			.' <span class="opacitymedium small">('.$langs->trans('PeVia_'.$p['via']).')</span>'
			.(!$ok ? ' <span class="pe-badge pe-b-warn">'.$langs->trans('PeAnomaly_label_unrelated').'</span>' : '');
	}
	return implode('<br>', $out);
}

/**
 * All the data of a result, as a table
 *
 * @param array<string,mixed> $info Result
 * @return string                   Html
 */
function peViewInfo($info)
{
	global $langs;

	$rows = array();
	$text = function ($k, $label, $pre = false) use ($info, &$rows) {
		$v = trim((string) ($info[$k] ?? ''));
		if ($v !== '') {
			$rows[] = array($label, '<span class="'.($pre ? 'pe-pre' : '').'">'.dol_escape_htmltag($v).'</span>');
		}
	};
	$text('title', $langs->trans('PeJs_Label'));
	$text('brand', $langs->trans('PeBrand'));
	$text('mpn', $langs->trans('PeJs_Mpn'));
	$text('famille', $langs->trans('PeFamily'));
	$text('category', $langs->trans('Category'));
	$text('desc_courte', $langs->trans('PeShortDesc'), true);
	$text('desc_longue', $langs->trans('PeLongDesc'), true);
	$text('prix_public', $langs->trans('PePublicPrice'));
	$text('prix_achat', $langs->trans('PeJs_BuyPrice').' ('.$langs->trans('PeJs_Estimate').')');
	$text('prix_marche', $langs->trans('PeJs_MarketPrice'));
	$page = peViewLink((string) ($info['page_fabricant'] ?? ''), 90);
	if ($page !== '') {
		$rows[] = array($langs->trans('PeJs_ManufacturerPage'), $page);
	}

	// Characteristics, the ones brought by eBay marked
	$specs = (array) ($info['specs'] ?? array());
	if (count($specs)) {
		$frombay = array_flip((array) ($info['specs_ebay'] ?? array()));
		$h = '<table class="nobordernopadding">';
		foreach ($specs as $k => $v) {
			$h .= '<tr><td class="opacitymedium paddingright">'.dol_escape_htmltag((string) $k).'</td><td>'.dol_escape_htmltag(is_array($v) ? implode(', ', $v) : (string) $v).(isset($frombay[$k]) ? ' <span class="pe-badge pe-b-ebay">eBay</span>' : '').'</td></tr>';
		}
		$h .= '</table>';
		$rows[] = array($langs->trans('PeJs_Characteristics'), $h);
	}

	// Variants of the model (AI)
	$variants = (array) ($info['declinaisons'] ?? array());
	if (count($variants)) {
		$current = (string) ($info['declinaison_scannee'] ?? '');
		$h = '<ul class="margintoponly">';
		foreach (array_slice($variants, 0, 30) as $d) {
			$d = (array) $d;
			$txt = implode(' — ', array_filter(array((string) ($d['code'] ?? ''), (string) ($d['libelle'] ?? ''), (string) ($d['ean'] ?? ''))));
			$h .= '<li'.($current !== '' && $current === (string) ($d['code'] ?? '') ? ' class="bold"' : '').'>'.dol_escape_htmltag($txt).'</li>';
		}
		$h .= '</ul>';
		$rows[] = array($langs->trans('PeJs_Variants'), $h);
	}

	// Photos
	$imgs = ProductEnrichment::infoImages($info);
	if (count($imgs)) {
		$h = '<div class="pe-photos">';
		foreach (array_slice($imgs, 0, 8) as $u) {
			if (preg_match('#^https?://#i', $u)) {
				$h .= '<a href="'.dol_escape_htmltag($u).'" target="_blank" rel="noopener noreferrer"><img src="'.dol_escape_htmltag($u).'" alt="" loading="lazy" referrerpolicy="no-referrer"></a>';
			}
		}
		$from = (string) ($info['images_from'] ?? '');
		$h .= '</div>'.($from !== '' ? '<span class="opacitymedium small">'.$langs->trans('PeImagesFrom', $langs->transnoentitiesnoconv('PeSrc_'.$from)).'</span>' : '');
		$rows[] = array($langs->trans('PeJs_Photos'), $h);
	}

	// eBay listings
	$titles = (array) ($info['titles'] ?? array());
	if (count($titles)) {
		$where = '';
		if (!empty($info['ebay_market'])) {
			$where = ' <span class="opacitymedium">('.(!empty($info['ebay_keyword']) ? $langs->trans('PeEbayKeywordShort').', ' : '').dol_escape_htmltag(ProductEnrichment::ebayMarketLabel((string) $info['ebay_market'])).')</span>';
		}
		$rows[] = array($langs->trans('PeEbayTitles').$where, '<span class="pe-pre">'.dol_escape_htmltag(implode("\n", $titles)).'</span>');
	}
	$rejected = (array) ($info['ebay_rejected'] ?? array());
	if (count($rejected)) {
		$rows[] = array($langs->trans('PeEbayRejected'), '<span class="pe-pre opacitymedium">'.dol_escape_htmltag(implode("\n", $rejected)).'</span>');
	}

	// Sources
	$links = array();
	foreach ((array) ($info['sources'] ?? array()) as $u) {
		$l = peViewLink((string) $u, 70);
		if ($l !== '') {
			$links[] = $l;
		}
	}
	if (count($links)) {
		$rows[] = array($langs->trans('PeJs_Sources'), implode('<br>', $links));
	}

	if (!count($rows)) {
		return '<span class="opacitymedium">'.$langs->trans('PeJs_NotFound').'</span>';
	}
	$out = '<table class="border centpercent tableforfield pe-kv">';
	foreach ($rows as $r) {
		$out .= '<tr><td class="titlefield tdtop">'.$r[0].'</td><td>'.$r[1].'</td></tr>';
	}
	$out .= '</table>';
	return $out;
}

/**
 * Journal of the enrichments of a product (what was applied, when, by whom, from which origin)
 *
 * @param DoliDB $db         Database
 * @param int    $fk_product Product
 * @return string            Html
 */
function peViewJournal($db, $fk_product)
{
	global $conf, $langs;

	$sql = "SELECT j.rowid, j.ean, j.origin, j.status, j.options, j.applied, j.message, j.datec, j.date_done, u.login, u.firstname, u.lastname";
	$sql .= " FROM ".MAIN_DB_PREFIX."productenrichment_job as j";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = j.fk_user_creat";
	$sql .= " WHERE j.fk_product = ".((int) $fk_product)." AND j.entity = ".((int) $conf->entity);
	$sql .= " ORDER BY j.rowid DESC";
	$sql .= $db->plimit(100);
	$resql = $db->query($sql);

	$badge = array('pending' => 'status0', 'done' => 'status4', 'nothing' => 'status1', 'notfound' => 'status8', 'mismatch' => 'status1', 'noean' => 'status8', 'error' => 'status8');
	$out = '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	$out .= '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('PeOrigin').'</td><td>EAN</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('PeApplied').'</td><td>'.$langs->trans('PeMessage').'</td><td>'.$langs->trans('User').'</td></tr>';
	$nb = 0;
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$nb++;
		$applied = json_decode((string) $obj->applied, true);
		$txt = array();
		foreach ((is_array($applied) ? $applied : array()) as $k => $v) {
			$txt[] = $langs->trans('PeField_'.$k).(is_numeric($v) && in_array($k, array('images', 'characteristics')) ? ' ('.((int) $v).')' : '');
		}
		$options = json_decode((string) $obj->options, true);
		$out .= '<tr class="oddeven">';
		$out .= '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_done ? $obj->date_done : $obj->datec), 'dayhour').'</td>';
		$out .= '<td>'.$langs->trans('PeOrigin_'.$obj->origin).(!empty($options['ai']) ? ' + '.$langs->trans('PeAi') : '').'</td>';
		$out .= '<td>'.dol_escape_htmltag((string) $obj->ean).'</td>';
		$out .= '<td>'.dolGetStatus($langs->trans('PeStatus_'.$obj->status), '', '', $badge[$obj->status] ?? 'status0', 5).'</td>';
		$out .= '<td>'.dol_escape_htmltag(implode(', ', $txt)).'</td>';
		$out .= '<td class="small">'.dol_escape_htmltag(dol_trunc((string) $obj->message, 160)).'</td>';
		$out .= '<td>'.dol_escape_htmltag(trim($obj->firstname.' '.$obj->lastname) !== '' ? trim($obj->firstname.' '.$obj->lastname) : (string) $obj->login).'</td>';
		$out .= '</tr>';
	}
	if (!$nb) {
		$out .= '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('PeNoJournal').'</span></td></tr>';
	}
	$out .= '</table></div>';
	return $out;
}
