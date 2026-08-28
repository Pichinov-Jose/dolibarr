<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/triggers/interface_99_modAdvancedstockmove_AdvancedstockmoveTriggers.class.php
 * \ingroup advancedstockmove
 * \brief   Sur STOCK_MOVEMENT : habille les mouvements manuels sans origine d'un document.
 *
 * Detection par le prefixe du code de lot (inventorycode) :
 *   COR-  -> document Inventaire (llx_inventory), statut Enregistre
 *   TRA-/MSM- -> document Transfert de stock (module coeur stocktransfer)
 * Le document est cree au premier mouvement du lot (ref = code de lot) et
 * retrouve pour les suivants ; fk_origin/origintype sont ensuite poses sur le
 * mouvement. Un echec ici NE DOIT JAMAIS faire echouer le mouvement : tout est
 * absorbe et journalise en warning.
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceAdvancedstockmoveTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'stock';
		$this->description = 'Wrap manual stock corrections and transfers into origin documents';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'stock';
	}

	/**
	 * @param string    $action Event action code
	 * @param Object    $object Object (MouvementStock on STOCK_MOVEMENT)
	 * @param User      $user   Object user
	 * @param Translate $langs  Object langs
	 * @param Conf      $conf   Object conf
	 * @return int              <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'STOCK_MOVEMENT') {
			return 0;
		}
		if (!isModEnabled('advancedstockmove')) {
			return 0;
		}
		if (empty($object->id)) {
			return 0;
		}
		// Une origine posee par l'appelant (facture, reception, inventaire...) : rien a faire
		if (!empty($object->origin_type) || !empty($object->origintype) || !empty($object->origin_id) || !empty($object->fk_origin)) {
			return 0;
		}
		$code = trim((string) $object->inventorycode);
		if ($code === '') {
			return 0;
		}

		$corprefix = getDolGlobalString('STOCK_CORRECTION_CODE_PREFIX', 'COR-');
		$traprefix = getDolGlobalString('STOCK_TRANSFER_CODE_PREFIX', 'TRA-');
		$msmprefix = getDolGlobalString('STOCK_MASSSTOCKMOVE_CODE_PREFIX', 'MSM-');

		try {
			if (getDolGlobalString('ADVANCEDSTOCKMOVE_WRAP_CORRECTIONS') && $corprefix !== '' && strpos($code, $corprefix) === 0) {
				$this->wrapIntoInventory($object, $user, $conf, $code);
			} elseif (getDolGlobalString('ADVANCEDSTOCKMOVE_WRAP_TRANSFERS') && isModEnabled('stocktransfer')
				&& (($traprefix !== '' && strpos($code, $traprefix) === 0) || ($msmprefix !== '' && strpos($code, $msmprefix) === 0))) {
				$this->wrapIntoStockTransfer($object, $user, $conf, $code);
			}
		} catch (Throwable $e) {
			dol_syslog(get_class($this).': '.$e->getMessage(), LOG_WARNING);
		}
		return 0;
	}

	/**
	 * Correction manuelle -> document Inventaire (ref = code de lot), statut Enregistre.
	 *
	 * @param Object $mv   MouvementStock
	 * @param User   $user User
	 * @param Conf   $conf Conf
	 * @param string $code Batch code
	 * @return void
	 */
	private function wrapIntoInventory($mv, User $user, Conf $conf, $code)
	{
		$px = $this->db->prefix();
		$invid = 0;

		$res = $this->db->query("SELECT rowid FROM ".$px."inventory WHERE (ref = '".$this->db->escape($code)."' OR title = '".$this->db->escape($code)."') AND entity = ".((int) $conf->entity));
		if ($res && ($o = $this->db->fetch_object($res))) {
			$invid = (int) $o->rowid;
		}
		if (!$invid) {
			// Reference : le code de lot par defaut ; un numero type Mercure si un masque est configure
			// (ex. COR{yy}{mm}-{0000}). Le code de lot est alors conserve dans le titre pour regrouper
			// les mouvements suivants du meme lot.
			$ref = $code;
			$mask = getDolGlobalString('ADVANCEDSTOCKMOVE_CORRECTION_MASK');
			if ($mask) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
				$next = get_next_value($this->db, $mask, 'inventory', 'ref', '', null, dol_now());
				if (is_string($next) && $next !== '' && !preg_match('/^Error/i', $next)) {
					$ref = $next;
				}
			}
			$sql = "INSERT INTO ".$px."inventory (entity, ref, title, date_creation, date_inventory, date_validation, fk_user_creat, fk_user_valid, fk_warehouse, status)";
			$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ref)."', '".$this->db->escape($code)."', '".$this->db->idate(dol_now())."',";
			$sql .= " '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."', ".((int) $user->id).", ".((int) $user->id).", ".((int) $mv->entrepot_id).", 2)";
			if (!$this->db->query($sql)) {
				dol_syslog(get_class($this).'::wrapIntoInventory header KO '.$this->db->lasterror(), LOG_WARNING);
				return;
			}
			$invid = (int) $this->db->last_insert_id($px.'inventory');
		}

		$sql = "INSERT INTO ".$px."inventorydet (datec, fk_inventory, fk_warehouse, fk_product, batch, qty_regulated, pmp_real, fk_movement)";
		$sql .= " VALUES ('".$this->db->idate(dol_now())."', ".$invid.", ".((int) $mv->entrepot_id).", ".((int) $mv->product_id).",";
		$sql .= " ".($mv->batch !== '' && $mv->batch !== null ? "'".$this->db->escape($mv->batch)."'" : "NULL").", ".((float) $mv->qty).", ".((float) $mv->price).", ".((int) $mv->id).")";
		if (!$this->db->query($sql)) {
			dol_syslog(get_class($this).'::wrapIntoInventory line KO '.$this->db->lasterror(), LOG_WARNING);
		}

		$this->stampOrigin((int) $mv->id, $invid, 'inventory');
	}

	/**
	 * Transfert manuel -> document Transfert de stock (module coeur stocktransfer).
	 * Ligne creee au mouvement negatif (source connue), completee au positif (destination).
	 *
	 * @param Object $mv   MouvementStock
	 * @param User   $user User
	 * @param Conf   $conf Conf
	 * @param string $code Batch code
	 * @return void
	 */
	private function wrapIntoStockTransfer($mv, User $user, Conf $conf, $code)
	{
		$px = $this->db->prefix();
		$stid = 0;
		$isout = ((float) $mv->qty < 0);
		$wh = (int) $mv->entrepot_id;

		$res = $this->db->query("SELECT rowid FROM ".$px."stocktransfer_stocktransfer WHERE (ref = '".$this->db->escape($code)."' OR label = '".$this->db->escape($code)."') AND entity = ".((int) $conf->entity));
		if ($res && ($o = $this->db->fetch_object($res))) {
			$stid = (int) $o->rowid;
		}
		if (!$stid) {
			dol_include_once('/product/stock/stocktransfer/class/stocktransfer.class.php');
			$ref = $code;
			$mask = getDolGlobalString('ADVANCEDSTOCKMOVE_TRANSFER_MASK');
			if ($mask) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
				$next = get_next_value($this->db, $mask, 'stocktransfer_stocktransfer', 'ref', '', null, dol_now());
				if (is_string($next) && $next !== '' && !preg_match('/^Error/i', $next)) {
					$ref = $next;
				}
			}
			$st = new StockTransfer($this->db);
			$st->ref = $ref;
			$st->label = $code;
			$st->date_creation = dol_now();
			$st->fk_warehouse_source = $isout ? $wh : 0;
			$st->fk_warehouse_destination = $isout ? 0 : $wh;
			$st->status = 1;
			$stid = $st->create($user);
			if ($stid <= 0) {
				dol_syslog(get_class($this).'::wrapIntoStockTransfer header KO '.$st->error, LOG_WARNING);
				return;
			}
			$this->db->query("UPDATE ".$px."stocktransfer_stocktransfer SET status = 1 WHERE rowid = ".((int) $stid));
		} else {
			// Complete le cote d'entete encore inconnu
			$col = $isout ? 'fk_warehouse_source' : 'fk_warehouse_destination';
			$this->db->query("UPDATE ".$px."stocktransfer_stocktransfer SET ".$col." = ".$wh." WHERE rowid = ".$stid." AND (".$col." IS NULL OR ".$col." = 0)");
		}

		if ($isout) {
			$sql = "INSERT INTO ".$px."stocktransfer_stocktransferline (fk_stocktransfer, fk_product, qty, fk_warehouse_source, fk_warehouse_destination, pmp, batch)";
			$sql .= " VALUES (".$stid.", ".((int) $mv->product_id).", ".abs((float) $mv->qty).", ".$wh.", 0, ".((float) $mv->price).",";
			$sql .= " ".($mv->batch !== '' && $mv->batch !== null ? "'".$this->db->escape($mv->batch)."'" : "NULL").")";
			if (!$this->db->query($sql)) {
				dol_syslog(get_class($this).'::wrapIntoStockTransfer line KO '.$this->db->lasterror(), LOG_WARNING);
			}
		} else {
			// Retrouve la ligne du mouvement sortant (destination encore a 0)
			$lid = 0;
			$res = $this->db->query("SELECT rowid FROM ".$px."stocktransfer_stocktransferline WHERE fk_stocktransfer = ".$stid." AND fk_product = ".((int) $mv->product_id)." AND fk_warehouse_destination = 0 ORDER BY rowid LIMIT 1");
			if ($res && ($o = $this->db->fetch_object($res))) {
				$lid = (int) $o->rowid;
			}
			if ($lid) {
				$this->db->query("UPDATE ".$px."stocktransfer_stocktransferline SET fk_warehouse_destination = ".$wh." WHERE rowid = ".$lid);
			} else {
				$sql = "INSERT INTO ".$px."stocktransfer_stocktransferline (fk_stocktransfer, fk_product, qty, fk_warehouse_source, fk_warehouse_destination, pmp, batch)";
				$sql .= " VALUES (".$stid.", ".((int) $mv->product_id).", ".abs((float) $mv->qty).", 0, ".$wh.", ".((float) $mv->price).",";
				$sql .= " ".($mv->batch !== '' && $mv->batch !== null ? "'".$this->db->escape($mv->batch)."'" : "NULL").")";
				if (!$this->db->query($sql)) {
					dol_syslog(get_class($this).'::wrapIntoStockTransfer line KO '.$this->db->lasterror(), LOG_WARNING);
				}
			}
		}

		$this->stampOrigin((int) $mv->id, $stid, 'StockTransfer@product/stock/stocktransfer');
	}

	/**
	 * Pose fk_origin/origintype sur le mouvement (uniquement s'il n'en a toujours pas).
	 *
	 * @param int    $mvid   Movement rowid
	 * @param int    $originid Origin document rowid
	 * @param string $origintype Origin type string
	 * @return void
	 */
	private function stampOrigin($mvid, $originid, $origintype)
	{
		if ($mvid <= 0 || $originid <= 0) {
			return;
		}
		$sql = "UPDATE ".$this->db->prefix()."stock_mouvement SET fk_origin = ".((int) $originid).", origintype = '".$this->db->escape($origintype)."'";
		$sql .= " WHERE rowid = ".((int) $mvid)." AND (origintype IS NULL OR origintype = '')";
		if (!$this->db->query($sql)) {
			dol_syslog(get_class($this).'::stampOrigin KO '.$this->db->lasterror(), LOG_WARNING);
		}
	}
}
