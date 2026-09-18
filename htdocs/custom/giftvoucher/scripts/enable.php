<?php
// Activation CLI du module GiftVoucher (crée table, droits, menus, consts).
if (php_sapi_name() !== 'cli') { die("CLI only\n"); }
$sapi_type = php_sapi_name();
$path = __DIR__.'/../../../';
require_once $path.'master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/giftvoucher/core/modules/modGiftvoucher.class.php';
$user->fetch(1);
$user->loadRights();
$mod = new modGiftvoucher($db);
$result = $mod->init();
print "init giftvoucher: ".$result."\n";
