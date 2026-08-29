<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');
$code = GETPOST('code', 'alphanohtml');
print json_encode(array('code' => scNormalize($code), 'candidates' => scLookupCode($db, $code)));
