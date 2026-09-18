<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \file    class/giftvoucher.class.php
 * \ingroup giftvoucher
 * \brief   Bon d'achat / chèque cadeau au porteur, identifié par son code-barres (ref).
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class GiftVoucher extends CommonObject
{
	public $element = 'giftvoucher';
	public $table_element = 'giftvoucher';
	public $picto = 'fa-gift';

	const STATUS_OPEN = 1;      // émis, non consommé (peut être expiré par la date)
	const STATUS_USED = 2;      // consommé
	const STATUS_CANCELED = 9;  // annulé

	const TYPE_GIFT = 'gift';       // chèque cadeau vendu
	const TYPE_CREDIT = 'credit';   // avoir / bon de retour

	public $ref;
	public $entity;
	public $amount;
	public $type_voucher;
	public $date_emission;
	public $date_validite;
	public $date_exerce;
	public $status;
	public $fk_soc;
	public $fk_facture_emission;
	public $fk_facture;
	public $fk_paiement;
	public $note_public;
	public $date_creation;
	public $fk_user_creat;
	public $fk_user_exerce;
	public $import_key;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Génère le prochain code depuis le masque GIFTVOUCHER_CODE_MASK.
	 *
	 * @return string Code, ou '' si erreur
	 */
	public function getNextCode()
	{
		global $db;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		$mask = getDolGlobalString('GIFTVOUCHER_CODE_MASK', 'BA{yy}{mm}-{0000}');
		$code = get_next_value($db, $mask, 'giftvoucher', 'ref');
		return is_string($code) ? $code : '';
	}

	/**
	 * Create voucher.
	 *
	 * @param User $user User
	 * @return int <0 KO, rowid OK
	 */
	public function create($user)
	{
		global $conf;
		$this->entity = (int) $conf->entity;
		if (empty($this->ref)) {
			$this->ref = $this->getNextCode();
		}
		if (empty($this->ref)) {
			$this->error = 'ErrorNoCode';
			return -1;
		}
		if (empty($this->type_voucher)) {
			$this->type_voucher = self::TYPE_GIFT;
		}
		if (empty($this->date_validite) && !empty($this->date_emission)) {
			$months = ($this->type_voucher == self::TYPE_CREDIT)
				? getDolGlobalInt('GIFTVOUCHER_VALIDITY_MONTHS_CREDIT', 6)
				: getDolGlobalInt('GIFTVOUCHER_VALIDITY_MONTHS_GIFT', 12);
			$this->date_validite = dol_time_plus_duree($this->date_emission, $months, 'm');
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."giftvoucher";
		$sql .= " (entity, ref, amount, type_voucher, date_emission, date_validite, status, fk_soc, fk_facture_emission, note_public, date_creation, fk_user_creat, import_key)";
		$sql .= " VALUES (".((int) $this->entity).", '".$this->db->escape($this->ref)."', ".((float) $this->amount).",";
		$sql .= " '".$this->db->escape($this->type_voucher)."',";
		$sql .= " ".($this->date_emission ? "'".$this->db->idate($this->date_emission)."'" : "NULL").",";
		$sql .= " ".($this->date_validite ? "'".$this->db->idate($this->date_validite)."'" : "NULL").",";
		$sql .= " ".self::STATUS_OPEN.", ".($this->fk_soc > 0 ? (int) $this->fk_soc : "NULL").",";
		$sql .= " ".($this->fk_facture_emission > 0 ? (int) $this->fk_facture_emission : "NULL").",";
		$sql .= " ".($this->note_public ? "'".$this->db->escape($this->note_public)."'" : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).",";
		$sql .= " ".($this->import_key ? "'".$this->db->escape($this->import_key)."'" : "NULL").")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."giftvoucher");
		$this->status = self::STATUS_OPEN;
		return $this->id;
	}

	/**
	 * Fetch by id or code.
	 *
	 * @param int $id Rowid
	 * @param string $ref Voucher code
	 * @return int <0 KO, 0 not found, >0 OK
	 */
	public function fetch($id = 0, $ref = '')
	{
		$sql = "SELECT rowid, entity, ref, amount, type_voucher, date_emission, date_validite, date_exerce, status,";
		$sql .= " fk_soc, fk_facture_emission, fk_facture, fk_paiement, note_public, date_creation, fk_user_creat, fk_user_exerce, import_key";
		$sql .= " FROM ".MAIN_DB_PREFIX."giftvoucher";
		if ($id > 0) {
			$sql .= " WHERE rowid = ".((int) $id);
		} else {
			$sql .= " WHERE ref = '".$this->db->escape(trim($ref))."' AND entity IN (".getEntity('giftvoucher').")";
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->amount = (float) $obj->amount;
		$this->type_voucher = $obj->type_voucher;
		$this->date_emission = $this->db->jdate($obj->date_emission);
		$this->date_validite = $this->db->jdate($obj->date_validite);
		$this->date_exerce = $this->db->jdate($obj->date_exerce);
		$this->status = (int) $obj->status;
		$this->fk_soc = (int) $obj->fk_soc;
		$this->fk_facture_emission = (int) $obj->fk_facture_emission;
		$this->fk_facture = (int) $obj->fk_facture;
		$this->fk_paiement = (int) $obj->fk_paiement;
		$this->note_public = $obj->note_public;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_exerce = (int) $obj->fk_user_exerce;
		$this->import_key = $obj->import_key;
		return 1;
	}

	/**
	 * Le bon est-il encaissable maintenant ? (ouvert et non expiré)
	 *
	 * @return bool
	 */
	public function isRedeemable()
	{
		if ($this->status != self::STATUS_OPEN) {
			return false;
		}
		if ($this->date_validite && $this->date_validite < dol_now('tzserver') - 86400 + 1) {
			// comparaison au jour : expiré si date_validite < aujourd'hui
			if (dol_print_date($this->date_validite, '%Y%m%d') < dol_print_date(dol_now(), '%Y%m%d')) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Consommer le bon (anti double-emploi : refuse si déjà consommé/annulé/expiré).
	 *
	 * @param User $user User
	 * @param int $fk_facture Facture d'encaissement (optionnel)
	 * @param int $force 1 = autoriser un bon expiré (geste commercial)
	 * @return int <0 KO, 1 OK
	 */
	public function redeem($user, $fk_facture = 0, $force = 0)
	{
		if ($this->status == self::STATUS_USED) {
			$this->error = 'GiftVoucherAlreadyUsed';
			return -2;
		}
		if ($this->status == self::STATUS_CANCELED) {
			$this->error = 'GiftVoucherCanceled';
			return -3;
		}
		if (!$force && !$this->isRedeemable()) {
			$this->error = 'GiftVoucherExpired';
			return -4;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."giftvoucher SET status = ".self::STATUS_USED.",";
		$sql .= " date_exerce = '".$this->db->idate(dol_now())."', fk_user_exerce = ".((int) $user->id);
		if ($fk_facture > 0) {
			$sql .= ", fk_facture = ".((int) $fk_facture);
		}
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_OPEN;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->affected_rows($resql) == 0) {
			$this->error = 'GiftVoucherAlreadyUsed'; // course entre deux caisses
			return -2;
		}
		$this->status = self::STATUS_USED;
		$this->date_exerce = dol_now();
		return 1;
	}

	/**
	 * Annuler / réactiver.
	 *
	 * @param User $user User
	 * @param int $newstatus STATUS_CANCELED ou STATUS_OPEN
	 * @return int <0 KO, 1 OK
	 */
	public function setStatusSimple($user, $newstatus)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."giftvoucher SET status = ".((int) $newstatus);
		if ($newstatus == self::STATUS_OPEN) {
			$sql .= ", date_exerce = NULL, fk_user_exerce = NULL, fk_facture = NULL, fk_paiement = NULL";
		}
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->status = (int) $newstatus;
		return 1;
	}

	/**
	 * Delete.
	 *
	 * @param User $user User
	 * @return int <0 KO, 1 OK
	 */
	public function delete($user)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."giftvoucher WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Statut affiché (l'expiration est dérivée de la date de validité).
	 *
	 * @param int $mode Mode from getLibStatut
	 * @return string
	 */
	public function getLibStatut($mode = 0)
	{
		global $langs;
		$langs->load('giftvoucher@giftvoucher');
		if ($this->status == self::STATUS_USED) {
			return dolGetStatus($langs->trans('GiftVoucherStatusUsed'), '', '', 'status6', $mode);
		}
		if ($this->status == self::STATUS_CANCELED) {
			return dolGetStatus($langs->trans('GiftVoucherStatusCanceled'), '', '', 'status9', $mode);
		}
		if (!$this->isRedeemable()) {
			return dolGetStatus($langs->trans('GiftVoucherStatusExpired'), '', '', 'status8', $mode);
		}
		return dolGetStatus($langs->trans('GiftVoucherStatusOpen'), '', '', 'status4', $mode);
	}

	/**
	 * @param int $withpicto With picto
	 * @return string
	 */
	public function getNomUrl($withpicto = 0)
	{
		global $langs;
		$url = dol_buildpath('/giftvoucher/giftvoucher_card.php', 1).'?id='.((int) $this->id);
		$result = '<a href="'.$url.'">';
		if ($withpicto) {
			$result .= img_picto($langs->trans('GiftVoucher'), $this->picto, 'class="paddingright"');
		}
		$result .= dol_escape_htmltag($this->ref).'</a>';
		return $result;
	}
}
