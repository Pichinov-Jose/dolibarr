<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
$langs->load('productfamily@productfamily');
if (!$user->admin && !$user->hasRight('produit', 'lire')) accessforbidden();

$search = GETPOST('search', 'alphanohtml');
$fk_parent = GETPOSTINT('fk_parent');

llxHeader('', $langs->trans('PfMenu'));
print load_fiche_titre($langs->trans('PfListTitle'), '', 'product');

// families = native variant links UNION extrafield links (member -> parent product)
$sqlfam = "SELECT fam.parent_id, fam.child_id FROM (";
$sqlfam .= " SELECT pac.fk_product_parent AS parent_id, pac.fk_product_child AS child_id FROM ".MAIN_DB_PREFIX."product_attribute_combination pac";
$sqlfam .= " UNION";
$sqlfam .= " SELECT pref.rowid, p.rowid FROM ".MAIN_DB_PREFIX."product p";
$sqlfam .= " JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid AND pe.variant_parent_ref IS NOT NULL AND pe.variant_parent_ref != ''";
$sqlfam .= " JOIN ".MAIN_DB_PREFIX."product pref ON pref.ref = pe.variant_parent_ref";
$sqlfam .= ") fam";

if ($fk_parent > 0) {
	// ---- family detail ----
	$pp = null;
	$resql = $db->query("SELECT rowid, ref, label, stock FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $fk_parent));
	if ($resql) $pp = $db->fetch_object($resql);
	// cumulated sales for one product (validated+ invoices)
	function pfSales($db, $pid)
	{
		$resql = $db->query("SELECT SUM(fd.qty) AS q, SUM(fd.total_ht) AS ht FROM ".MAIN_DB_PREFIX."facturedet fd JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture WHERE fd.fk_product = ".((int) $pid)." AND f.fk_statut > 0");
		$o = $resql ? $db->fetch_object($resql) : null;
		return array((float) ($o->q ?? 0), (float) ($o->ht ?? 0));
	}
	if ($pp) {
		print '<a href="'.$_SERVER['PHP_SELF'].'">&larr; '.$langs->trans('PfBackToList').'</a><br><br>';
		print load_fiche_titre($langs->trans('PfFamilyOf').' <a href="'.DOL_URL_ROOT.'/product/card.php?id='.$pp->rowid.'">'.dol_escape_htmltag($pp->ref).'</a> — '.dol_escape_htmltag($pp->label), '', '');
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('PfLinkType').'</td><td class="right">'.$langs->trans('Stock').'</td><td class="right">PMP</td><td class="right">'.$langs->trans('PfValue').'</td><td class="right">'.$langs->trans('PfQtySold').'</td><td class="right">'.$langs->trans('PfRevenue').'</td></tr>';
		$sql = "SELECT p.rowid, p.ref, p.label, p.stock, p.pmp, 'native' AS lt FROM ".MAIN_DB_PREFIX."product_attribute_combination pac JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pac.fk_product_child WHERE pac.fk_product_parent = ".((int) $fk_parent);
		$sql .= " UNION SELECT p.rowid, p.ref, p.label, p.stock, p.pmp, 'extrafield' FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE pe.variant_parent_ref = '".$db->escape($pp->ref)."'";
		$sql .= " ORDER BY ref";
		$resql = $db->query($sql);
		$tstock = (float) $pp->stock; $tval = 0; $n = 0;
		list($pq, $pht) = pfSales($db, (int) $pp->rowid);
		$tq = $pq; $tht = $pht;
		print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$pp->rowid.'"><b>'.dol_escape_htmltag($pp->ref).'</b></a></td><td>'.dol_escape_htmltag($pp->label).'</td><td>'.$langs->trans('PfPseudoParent').'</td><td class="right">'.price2num($pp->stock).'</td><td class="right"></td><td class="right"></td><td class="right">'.price2num($pq).'</td><td class="right">'.price($pht).'</td></tr>';
		while ($resql && ($o = $db->fetch_object($resql))) {
			$n++; $tstock += (float) $o->stock; $tval += ((float) $o->stock) * ((float) $o->pmp);
			list($mq, $mht) = pfSales($db, (int) $o->rowid);
			$tq += $mq; $tht += $mht;
			print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$o->rowid.'">'.dol_escape_htmltag($o->ref).'</a></td><td>'.dol_escape_htmltag($o->label).'</td><td>'.($o->lt == 'native' ? $langs->trans('PfNative') : $langs->trans('PfByExtrafield')).'</td><td class="right">'.price2num($o->stock).'</td><td class="right">'.price($o->pmp).'</td><td class="right">'.price(((float) $o->stock) * ((float) $o->pmp)).'</td><td class="right">'.price2num($mq).'</td><td class="right">'.price($mht).'</td></tr>';
		}
		print '<tr class="liste_total"><td colspan="3">'.$langs->trans('Total').' ('.$n.' '.$langs->trans('PfMembers').')</td><td class="right"><b>'.price2num($tstock).'</b></td><td></td><td class="right"><b>'.price($tval).'</b></td><td class="right"><b>'.price2num($tq).'</b></td><td class="right"><b>'.price($tht).'</b></td></tr>';
		print '</table></div>';
	}
} else {
	// ---- families list ----
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'"><input type="text" name="search" value="'.dol_escape_htmltag($search).'" placeholder="'.$langs->trans('PfSearch').'" class="minwidth200"> <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Search').'"></form><br>';
	$sql = "SELECT pp.rowid, pp.ref, pp.label, pp.stock AS pstock, pe2.kezia_idart,";
	$sql .= " COUNT(DISTINCT fam.child_id) AS nb, SUM(pc.stock) AS tstock, SUM(pc.stock * pc.pmp) AS tval";
	$sql .= " FROM (".substr($sqlfam, strlen("SELECT fam.parent_id, fam.child_id FROM ("), -4).") fam";
	$sql .= " JOIN ".MAIN_DB_PREFIX."product pp ON pp.rowid = fam.parent_id";
	$sql .= " JOIN ".MAIN_DB_PREFIX."product pc ON pc.rowid = fam.child_id";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields pe2 ON pe2.fk_object = pp.rowid";
	if ($search !== '') {
		$sql .= " WHERE (pp.ref LIKE '%".$db->escape($search)."%' OR pp.label LIKE '%".$db->escape($search)."%')";
	}
	$sql .= " GROUP BY pp.rowid, pp.ref, pp.label, pp.stock, pe2.kezia_idart ORDER BY pp.ref LIMIT 500";
	$resql = $db->query($sql);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('PfFamilyRef').'</td><td>'.$langs->trans('Label').'</td><td class="right">IDArt</td><td class="right">'.$langs->trans('PfMembers').'</td><td class="right">'.$langs->trans('PfStockParent').'</td><td class="right">'.$langs->trans('PfStockFamily').'</td><td class="right">'.$langs->trans('PfValue').'</td></tr>';
	$n = 0;
	while ($resql && ($o = $db->fetch_object($resql))) {
		$n++;
		print '<tr class="oddeven">';
		print '<td><a href="'.$_SERVER['PHP_SELF'].'?fk_parent='.$o->rowid.'"><b>'.dol_escape_htmltag($o->ref).'</b></a></td>';
		print '<td>'.dol_escape_htmltag($o->label).'</td>';
		print '<td class="right">'.dol_escape_htmltag((string) $o->kezia_idart).'</td>';
		print '<td class="right">'.((int) $o->nb).'</td>';
		print '<td class="right">'.price2num($o->pstock).'</td>';
		print '<td class="right"><b>'.price2num((float) $o->pstock + (float) $o->tstock).'</b></td>';
		print '<td class="right">'.price($o->tval).'</td>';
		print '</tr>';
	}
	if (!$n) print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans('PfNoFamily').'</span></td></tr>';
	print '</table></div>';
}
llxFooter();
$db->close();
