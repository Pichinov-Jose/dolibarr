<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
$langs->loadLangs(array('products', 'productfamily@productfamily'));
$id = GETPOSTINT('id');
$object = new Product($db);
if ($id > 0) $object->fetch($id);
if (!$user->admin && !$user->hasRight('produit', 'lire')) accessforbidden();

// resolve the family parent of this product: native parent, else extrafield target, else itself
$parent_id = 0;
$resql = $db->query("SELECT fk_product_parent FROM ".MAIN_DB_PREFIX."product_attribute_combination WHERE fk_product_child = ".((int) $id)." LIMIT 1");
if ($resql && ($o = $db->fetch_object($resql))) $parent_id = (int) $o->fk_product_parent;
if (!$parent_id) {
	$resql = $db->query("SELECT pref.rowid FROM ".MAIN_DB_PREFIX."product_extrafields pe JOIN ".MAIN_DB_PREFIX."product pref ON pref.ref = pe.variant_parent_ref WHERE pe.fk_object = ".((int) $id)." AND pe.variant_parent_ref != '' LIMIT 1");
	if ($resql && ($o = $db->fetch_object($resql))) $parent_id = (int) $o->rowid;
}
if (!$parent_id) $parent_id = $id;

llxHeader('', $langs->trans('PfFamilyTab'));
$head = product_prepare_head($object);
print dol_get_fiche_head($head, 'pffamily', $langs->trans('Product'), -1, 'product');
print '<b>'.dol_escape_htmltag($object->ref).'</b> — '.dol_escape_htmltag($object->label).'<br><br>';
print dol_get_fiche_end();
print '<iframe src="'.dol_buildpath('/productfamily/famille.php', 1).'?fk_parent='.$parent_id.'" style="width:100%;height:70vh;border:none"></iframe>';
llxFooter();
$db->close();
