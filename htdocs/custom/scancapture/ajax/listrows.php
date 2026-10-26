<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com> - GPL v3+ */
/**
 * Liste des scans par tranches : vue par defaut (aujourd'hui ou non envoyes) ou recherche
 * dans TOUT l'historique (code etiquette, EAN, reference, libelle).
 * GET q (recherche, >= 2 caracteres), offset, fk_inventory (vue par defaut seulement).
 */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
require_once dol_buildpath('/scancapture/lib/scancapture_rows.inc.php');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$q = trim(GETPOST('q', 'alphanohtml'));
$offset = max(0, GETPOSTINT('offset'));
$fk_inventory = GETPOSTINT('fk_inventory');
$limit = 200;

$where = array();
// chaque operateur ne voit que ses scans ; un admin voit tout
if (!$user->admin) { $where[] = "sc.fk_user = ".((int) $user->id); }
if (dol_strlen($q) >= 2) {
	$e = $db->escape($q);
	$where[] = "(sc.code_kezia LIKE '%".$e."%' OR sc.ean LIKE '%".$e."%' OR sc.product_label LIKE '%".$e."%' OR p.ref LIKE '%".$e."%' OR p.label LIKE '%".$e."%')";
} else {
	$where[] = "(sc.datec >= CURDATE() OR sc.sent_to_inv IS NULL)";
	if ($fk_inventory > 0) { $where[] = "(sc.fk_inventory = ".((int) $fk_inventory)." OR sc.fk_inventory IS NULL)"; }
}
$sql = "SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.product_label, sc.status, sc.sent_to_inv, sc.fk_product, sc.stock_before, u.login AS scuser
	FROM ".MAIN_DB_PREFIX."scan_capture sc
	LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = sc.fk_user
	LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = sc.fk_product
	WHERE ".implode(' AND ', $where)."
	ORDER BY sc.rowid DESC LIMIT ".($limit + 1)." OFFSET ".$offset;
$resql = $db->query($sql);
$rows = array();
while ($resql && ($o = $db->fetch_object($resql))) { $rows[] = $o; }
$more = (count($rows) > $limit);
if ($more) { array_pop($rows); }
// scRenderRows attend un resultset : on le rejoue sur la liste deja lue
$html = '';
if (count($rows)) {
	$ids = implode(',', array_map(function ($o) { return (int) $o->rowid; }, $rows));
	$resql2 = $db->query("SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.product_label, sc.status, sc.sent_to_inv, sc.fk_product, sc.stock_before, u.login AS scuser, i.status AS inv_status".(scHasColumn($db, 'scan_capture', 'code_saisi') ? ", sc.code_saisi, sc.code_inconnu" : "")."
		FROM ".MAIN_DB_PREFIX."scan_capture sc LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = sc.fk_user LEFT JOIN ".MAIN_DB_PREFIX."inventory i ON i.rowid = sc.fk_inventory
		WHERE sc.rowid IN (".$ids.") ORDER BY sc.rowid DESC");
	$html = scRenderRows($db, $user, $resql2);
}
print json_encode(array('ok' => true, 'html' => $html, 'count' => count($rows), 'more' => $more, 'search' => (dol_strlen($q) >= 2)));
