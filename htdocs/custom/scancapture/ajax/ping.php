<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com> - GPL v3+ */
/** Maintien de session du scanner : chaque appel reecrit la session (et son delai de vie). */
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
top_httphead('application/json');
$_SESSION['scancapture_ping'] = dol_now();
print json_encode(array('ok' => true, 'user' => $user->login));
