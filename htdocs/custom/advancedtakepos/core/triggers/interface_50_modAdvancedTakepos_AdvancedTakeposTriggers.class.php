<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    core/triggers/interface_50_modAdvancedTakepos_AdvancedTakeposTriggers.class.php
 * \ingroup advancedtakepos
 * \brief   Reglement par bon d'achat en caisse : rattache le bon scanne au reglement TakePOS.
 *
 * La fenetre Reglement de TakePOS n'a pas de champ numero : le bon scanne (ajax/giftvoucher.php, action arm)
 * est garde en session pour la vente, puis ce trigger le pose dans le numero du reglement. Le trigger du module
 * GiftVoucher (priorite 99, donc apres celui-ci) consomme alors le bon et le lie au reglement, comme pour une
 * facture classique. Le reliquat d'un bon superieur au reglement devient un nouveau bon, dans la meme transaction.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class of triggers for module AdvancedTakepos
 */
class InterfaceAdvancedTakeposTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'other';
		$this->description = 'AdvancedTakepos : reglement par bon d achat en caisse';
		$this->version = '1.0.0';
		$this->picto = 'technic';
	}

	/**
	 * @param string       $action Event code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Langs
	 * @param Conf         $conf   Conf
	 * @return int                 <0 KO (bloque le reglement), 0 ignore, >0 OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'PAYMENT_CUSTOMER_CREATE' || !isModEnabled('advancedtakepos') || !isModEnabled('giftvoucher')
			|| getDolGlobalInt('ADVANCEDTAKEPOS_GIFTVOUCHER') != 1 || empty($_SESSION['advtp_gv']) || !is_array($_SESSION['advtp_gv'])) {
			return 0;
		}
		$modeid = getDolGlobalInt('GIFTVOUCHER_PAYMENT_MODE_ID');
		$paymode = !empty($object->paiementid) ? (int) $object->paiementid : (int) $object->fk_paiement;
		if ($modeid <= 0 || $paymode != $modeid) {
			return 0;
		}
		// Code deja saisi (reglement depuis la fiche facture) : le trigger GiftVoucher s'en charge seul.
		if (trim((string) $object->num_payment) !== '' || empty($object->amounts) || !is_array($object->amounts) || count($object->amounts) != 1) {
			return 0;
		}
		$facid = (int) key($object->amounts);
		$armed = isset($_SESSION['advtp_gv'][$facid]) ? $_SESSION['advtp_gv'][$facid] : null;
		if (empty($armed['code']) || (dol_now() - (int) $armed['time']) > 600) {
			return 0;
		}
		// Un scan ne sert qu'a un reglement.
		unset($_SESSION['advtp_gv'][$facid]);

		dol_include_once('/giftvoucher/class/giftvoucher.class.php');
		$langs->load('giftvoucher@giftvoucher');
		$langs->load('advancedtakepos@advancedtakepos');
		$voucher = new GiftVoucher($this->db);
		if ($voucher->fetch(0, $armed['code']) <= 0) {
			$this->errors[] = $langs->trans('GiftVoucherNotFound', $armed['code']);
			return -1;
		}
		// Etat revu au moment du reglement (le bon a pu servir sur une autre caisse depuis le scan).
		if ($voucher->status == GiftVoucher::STATUS_USED || $voucher->status == GiftVoucher::STATUS_CANCELED) {
			$this->errors[] = $langs->trans($voucher->status == GiftVoucher::STATUS_USED ? 'GiftVoucherAlreadyUsed' : 'GiftVoucherCanceled').' ('.$voucher->ref.')';
			return -1;
		}
		if (empty($armed['force']) && !$voucher->isRedeemable()) {
			$this->errors[] = $langs->trans('GiftVoucherExpired').' ('.$voucher->ref.')';
			return -1;
		}
		$applied = (float) price2num(array_sum($object->amounts), 'MT');
		if ($applied > (float) $voucher->amount + 0.005) {
			$this->errors[] = $langs->trans('AdvTakeposGvAmountTooHigh', $voucher->ref);
			return -1;
		}

		if (!empty($armed['force']) && !$voucher->isRedeemable()) {
			// Bon expire accepte en geste commercial (option et confirmation en caisse) : le trigger GiftVoucher le
			// refuserait, on le consomme ici et on laisse le numero vide sur l'objet pour qu'il ne repasse pas dessus.
			if ($voucher->redeem($user, $facid, 1) <= 0) {
				$this->errors[] = $langs->trans($voucher->error).' ('.$voucher->ref.')';
				return -1;
			}
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."giftvoucher SET fk_paiement = ".((int) $object->id)." WHERE rowid = ".((int) $voucher->id));
		} else {
			$object->num_payment = $voucher->ref;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."paiement SET num_paiement = '".$this->db->escape($voucher->ref)."' WHERE rowid = ".((int) $object->id));

		// Reliquat : nouveau bon du meme type et de la meme fin de validite, imprime apres le reglement.
		$rest = (float) price2num((float) $voucher->amount - $applied, 'MT');
		if ($rest > 0.004) {
			$new = new GiftVoucher($this->db);
			$new->amount = $rest;
			$new->type_voucher = $voucher->type_voucher;
			$new->date_emission = dol_now();
			$new->date_validite = $voucher->date_validite;
			$new->fk_soc = $voucher->fk_soc;
			$new->note_public = $langs->transnoentitiesnoconv('AdvTakeposGvRestNote', $voucher->ref, price($applied), price($voucher->amount));
			if ($new->create($user) <= 0) {
				$this->errors[] = $new->error;
				return -1;
			}
			if (!isset($_SESSION['advtp_gv_print']) || !is_array($_SESSION['advtp_gv_print'])) {
				$_SESSION['advtp_gv_print'] = array();
			}
			$_SESSION['advtp_gv_print'][] = (int) $new->id;
			dol_syslog('advancedtakepos: voucher '.$voucher->ref.' used for '.$applied.' on invoice '.$facid.', rest '.$rest.' as voucher '.$new->ref, LOG_NOTICE);
		} else {
			dol_syslog('advancedtakepos: voucher '.$voucher->ref.' used for '.$applied.' on invoice '.$facid, LOG_NOTICE);
		}
		return 1;
	}
}
