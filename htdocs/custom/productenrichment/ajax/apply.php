<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Apply the result of an EAN to the EMPTY fields of an existing product (product card).
 * POST fk_product, ean, sel[] (fields), specs (JSON [{key, fk_attribute, value}]), images[]
 */
require __DIR__.'/_common.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

$pe = new ProductEnrichment($db);
$ean = ProductEnrichment::normalizeEan(GETPOST('ean', 'alphanohtml'));
$product = new Product($db);
if ($ean === '' || $product->fetch(GETPOSTINT('fk_product')) <= 0) {
	peJsonExit(array('ok' => false, 'error' => 'badrequest'));
}
$info = $pe->getCached($ean);
if (!$info) {
	peJsonExit(array('ok' => false, 'error' => 'nocache', 'message' => $langs->transnoentitiesnoconv('PeErrorRunFirst')));
}
$allowed = array('description', 'ref_marque', 'supplier_url', 'barcode', 'sell_price', 'images', 'characteristics');
$sel = array_values(array_intersect((array) GETPOST('sel', 'array:aZ09'), $allowed));
$specs = array();
$posted = json_decode((string) GETPOST('specs', 'restricthtml'), true);
foreach ((is_array($posted) ? $posted : array()) as $s) {
	if (is_array($s) && (int) ($s['fk_attribute'] ?? 0) > 0 && trim((string) ($s['value'] ?? '')) !== '') {
		$specs[] = array('key' => dol_string_nohtmltag((string) ($s['key'] ?? '')), 'fk_attribute' => (int) $s['fk_attribute'], 'value' => dol_string_nohtmltag((string) $s['value']));
	}
}
$images = array_values(array_filter((array) GETPOST('images', 'array:restricthtml'), 'is_string'));

$db->begin();
$res = $pe->applyToProduct($user, $product, $info, $ean, $sel, $specs, $images, 'card');
$db->commit();
peJsonExit(array('ok' => true, 'applied' => $res['applied'], 'errors' => $res['errors']));
