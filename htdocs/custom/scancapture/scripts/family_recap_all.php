<?php
/**
 * Recalage global des cumuls famille (extrafields + blocs de notes des pseudo-parents).
 * CLI : php scripts/family_recap_all.php  — rejouable a volonte (recalcul pur).
 */
if (php_sapi_name() !== 'cli') { exit("cli only\n"); }
define('NOSESSION', 1);
$_SERVER['PHP_SELF'] = '/cli';
chdir(__DIR__.'/../../..');
include 'master.inc.php';
require_once __DIR__.'/../lib/scancapture.lib.php';
$parents = array();
$resql = $db->query("SELECT DISTINCT COALESCE(NULLIF(pe.variant_parent_link, 0), p2.rowid) AS pid FROM ".MAIN_DB_PREFIX."product_extrafields pe LEFT JOIN ".MAIN_DB_PREFIX."product p2 ON p2.ref = pe.variant_parent_ref WHERE (pe.variant_parent_link IS NOT NULL AND pe.variant_parent_link > 0) OR (pe.variant_parent_ref IS NOT NULL AND pe.variant_parent_ref != '')");
while ($resql && ($o = $db->fetch_object($resql))) { if ((int) $o->pid > 0) { $parents[(int) $o->pid] = 1; } }
printf("%d pseudo-parent(s) a recalculer\n", count($parents));
foreach (array_keys($parents) as $pid) {
	$cumul = scFamilyRecap($db, $pid);
	$r = $db->query("SELECT ref FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".$pid);
	$ref = ($r && ($x = $db->fetch_object($r))) ? $x->ref : $pid;
	printf("  %-20s cumul trouve = %s\n", $ref, price2num($cumul));
}
print "fini\n";
