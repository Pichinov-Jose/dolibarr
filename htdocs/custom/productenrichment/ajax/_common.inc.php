<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Common start of the ajax endpoints: POST only (the token is checked by main.inc.php),
 * module enabled, right to create products, JSON answer.
 */
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
$res = 0;
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) {
	$res = @include __DIR__.'/../../../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../../../main.inc.php')) {
	$res = @include __DIR__.'/../../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
dol_include_once('/productenrichment/class/productenrichment.class.php');

top_httphead('application/json');
if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] != 'POST' || !isModEnabled('productenrichment') || !$user->hasRight('produit', 'creer')) {
	http_response_code(403);
	print json_encode(array('ok' => false, 'error' => 'forbidden'));
	exit;
}
$langs->load('productenrichment@productenrichment');

/**
 * Print a JSON answer and stop
 *
 * @param array<string,mixed> $data Answer
 * @return void
 */
function peJsonExit($data)
{
	print json_encode($data);
	exit;
}
