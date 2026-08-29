<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+ — This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or later.
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Inventory scan capture: fast dual-barcode counting grid (Kezia code + product EAN),
 * live product resolution, EAN association, unknown-EAN capture with UPCitemdb enrichment
 * and later mass product creation.
 */
class modScanCapture extends DolibarrModules
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 104985;
		$this->rights_class = 'scancapture';
		$this->family = 'pichinov';
		$this->familyinfo = array('pichinov' => array('position' => '001', 'label' => 'Pichinov'));
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Inventaire scan double code-barres (code Kezia + EAN) avec capture des inconnus';
		$this->editor_name = 'Pichinov';
		$this->version = '1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'barcode';
		$this->langfiles = array('scancapture@scancapture');
		$this->module_parts = array();
		$this->rights = array();
		$this->menu = array();
		$r = 0;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left', 'titre' => 'ScanCaptureMenu', 'mainmenu' => 'products', 'leftmenu' => 'scancapture',
			'url' => '/scancapture/capture.php', 'langs' => 'scancapture@scancapture', 'position' => 1000,
			'enabled' => 'isModEnabled("scancapture")', 'perms' => '$user->hasRight("stock", "creer") || $user->admin', 'target' => '', 'user' => 0,
		);
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=scancapture',
			'type' => 'left', 'titre' => 'ScanReviewMenu', 'mainmenu' => 'products', 'leftmenu' => 'scancapture_review',
			'url' => '/scancapture/review.php', 'langs' => 'scancapture@scancapture', 'position' => 1001,
			'enabled' => 'isModEnabled("scancapture")', 'perms' => '$user->hasRight("stock", "creer") || $user->admin', 'target' => '', 'user' => 0,
		);
	}

	public function init($options = '')
	{
		$sqls = array(
			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."scan_capture (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				datec DATETIME,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				fk_user INTEGER,
				code_kezia VARCHAR(64) NULL,
				ean VARCHAR(64) NULL,
				qty DOUBLE DEFAULT 1,
				fk_product INTEGER NULL,
				match_source VARCHAR(32) NULL,
				product_label VARCHAR(255) NULL,
				candidates TEXT NULL,
				status VARCHAR(16) DEFAULT 'unknown',
				ean_info TEXT NULL,
				fk_inventory INTEGER NULL,
				import_key VARCHAR(14) NULL,
				KEY idx_scap_status (status),
				KEY idx_scap_ean (ean),
				KEY idx_scap_prod (fk_product)
			) ENGINE=innodb",
			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."scan_assoc (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				datec DATETIME,
				code VARCHAR(64) NOT NULL,
				fk_product INTEGER NOT NULL,
				fk_user INTEGER,
				written_to VARCHAR(16),
				KEY idx_sasso_code (code),
				KEY idx_sasso_prod (fk_product)
			) ENGINE=innodb",
		);
		foreach ($sqls as $sql) {
			$this->db->query($sql);
		}
		return $this->_init(array(), $options);
	}
}
