<?php
/** POC TakePOS - enregistre le vendeur choisi sur la facture en cours (extrafield fk_vendeur). */
if (!defined('NOCSRFCHECK'))     define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL'))  define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU'))   define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))   define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))   define('NOREQUIREAJAX', '1');

$found = 0;
foreach (array('/../../../main.inc.php', '/../../../../main.inc.php') as $p) {
	if (file_exists(__DIR__.$p)) { require __DIR__.$p; $found = 1; break; }
}
if (!$found) { http_response_code(500); die('main.inc.php introuvable'); }

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

$invoiceid = GETPOSTINT('invoiceid');
$vendeur   = GETPOSTINT('vendeur');

top_httphead('application/json');
$out = array('ok' => false);

if ($invoiceid > 0 && $user->hasRight('takepos', 'run')) {
	$inv = new Facture($db);
	if ($inv->fetch($invoiceid) > 0) {
		$inv->array_options['options_fk_vendeur'] = $vendeur;
		$r = $inv->insertExtraFields();
		$out['ok'] = ($r >= 0);
		$out['invoiceid'] = $invoiceid;
		$out['vendeur'] = $vendeur;
	}
}
echo json_encode($out);
if (is_object($db)) { $db->close(); }
