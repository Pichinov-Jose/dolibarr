<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Product families: link flat products to a pseudo-parent ref (extrafield variant_parent_ref),
 * aggregate family stock/value across native variant children AND extrafield-linked products.
 */
class modProductFamily extends DolibarrModules
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 104986;
		$this->rights_class = 'productfamily';
		$this->family = 'pichinov';
		$this->familyinfo = array('pichinov' => array('position' => '001', 'label' => 'Pichinov'));
		$this->module_position = '92';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Familles de produits : stock total famille (variantes natives + rattachement extrafield)';
		$this->editor_name = 'Pichinov';
		$this->version = '1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'product';
		$this->langfiles = array('productfamily@productfamily');
		$this->module_parts = array();
		$this->tabs = array('product:+pffamily:PfFamilyTab:productfamily@productfamily:1:/productfamily/tab.php?id=__ID__');
		$this->rights = array();
		$this->menu = array();
		$this->menu[0] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left', 'titre' => 'PfMenu', 'mainmenu' => 'products', 'leftmenu' => 'productfamily',
			'url' => '/productfamily/famille.php', 'langs' => 'productfamily@productfamily', 'position' => 1010,
			'enabled' => 'isModEnabled("productfamily")', 'perms' => '$user->hasRight("produit", "lire") || $user->admin', 'target' => '', 'user' => 0,
		);
	}

	public function init($options = '')
	{
		// create the linking extrafield (idempotent)
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$extrafields->addExtraField('variant_parent_ref', 'Réf famille (pseudo-parent)', 'varchar', 210, '32', 'product', 0, 0, '', '', 1, '', '1', 'Réf du produit pseudo-parent portant l\'IDArt Kezia de la famille', '', 1, 'productfamily@productfamily', 'isModEnabled("productfamily")');
		return $this->_init(array(), $options);
	}
}
