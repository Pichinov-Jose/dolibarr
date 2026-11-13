<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Look up an EAN: UPCitemdb then eBay, plus the AI web search when ai=1.
 * POST ean, ai, label, socid (supplier of the creation form), fk_product (product card)
 */
require __DIR__.'/_common.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

$pe = new ProductEnrichment($db);
$ean = ProductEnrichment::normalizeEan(GETPOST('ean', 'alphanohtml'));
if ($ean === '') {
	peJsonExit(array('ok' => false, 'error' => 'badean', 'message' => $langs->transnoentitiesnoconv('PeErrorBadEan')));
}
$fk_product = GETPOSTINT('fk_product');
$product = null;
if ($fk_product > 0) {
	$product = new Product($db);
	if ($product->fetch($fk_product) <= 0) {
		peJsonExit(array('ok' => false, 'error' => 'noproduct'));
	}
	$product->fetch_optionals();
}
$context = array(
	'label' => ($product ? $product->label : trim(GETPOST('label', 'alphanohtml'))),
	'socid' => GETPOSTINT('socid'),
	'fk_product' => $fk_product,
	'blind' => GETPOSTINT('blind'),
);
$r = $pe->enrich($ean, (bool) GETPOSTINT('ai'), $context);
$info = $r['info'];

// Only what the screens need, images limited to https
$keys = array('title', 'titles', 'famille', 'brand', 'mpn', 'desc_courte', 'desc_longue', 'specs', 'prix_public', 'prix_achat', 'prix_marche', 'page_fabricant', 'declinaisons', 'declinaison_scannee', 'confiance', 'sources');
$out = array();
foreach ($keys as $k) {
	if (isset($info[$k]) && $info[$k] !== '' && $info[$k] !== array()) {
		$out[$k] = $info[$k];
	}
}
$out['images'] = ProductEnrichment::infoImages($info);
$out['manufacturer_page'] = ProductEnrichment::proposedManufacturerPage($info);
$out['description'] = ProductEnrichment::proposedDescription($info);
$out['sell_price'] = (ProductEnrichment::parsePrice($info['prix_public'] ?? '') > 0 ? price2num(ProductEnrichment::parsePrice($info['prix_public']), 'MU') : '');
$out['buy_price'] = (ProductEnrichment::parsePrice($info['prix_achat'] ?? '') > 0 ? price2num(ProductEnrichment::parsePrice($info['prix_achat']), 'MU') : '');
$out['ai'] = !empty($info['ai']);
$out['ebay'] = !empty($info['ebay']);
// Listing found by the full-text search (its seller did not fill the GTIN field): name of the marketplace
$out['ebay_keyword'] = (!empty($info['ebay_keyword']) && !empty($info['titles']) ? ProductEnrichment::ebayMarketLabel((string) ($info['ebay_market'] ?? '')) : '');

$attributes = $pe->featureAttributes();
$data = array(
	'ok' => true,
	'ean' => $ean,
	'found' => ((string) ($info['title'] ?? '') !== ''),
	'steps' => $r['steps'],
	'errors' => (object) ($r['errors'] ?? array()),
	'info' => $out,
	'specs' => $pe->mapSpecs($info, $attributes),
	'attributes' => $attributes,
	'characteristics' => ProductEnrichment::characteristicsEnabled(),
	'quota' => ProductEnrichment::quotaStatus(),
);
if ($product) {
	$data['proposals'] = $pe->proposalsForProduct($product, $info, $ean);
}
peJsonExit($data);
