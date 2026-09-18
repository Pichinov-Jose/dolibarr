<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+ — see modGiftvoucher.class.php
 */

/**
 * \file    core/triggers/interface_99_modGiftvoucher_GiftvoucherTriggers.class.php
 * \ingroup giftvoucher
 * \brief   Consommation automatique d'un bon quand un règlement « bon d'achat » porte
 *          son code dans le champ Numéro (num_paiement) — facture classique.
 *          Bloque le règlement si le bon est inconnu, déjà consommé, annulé ou expiré.
 *          (En caisse TakePOS, pas de champ numéro : le contrôle passe par la page Scanner.)
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceGiftvoucherTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'giftvoucher';
		$this->description = 'Redeem gift vouchers referenced in customer payments';
		$this->version = '1.0.0';
		$this->picto = 'fa-gift';
	}

	/**
	 * @param string $action Event code
	 * @param CommonObject $object Object
	 * @param User $user User
	 * @param Translate $langs Langs
	 * @param Conf $conf Conf
	 * @return int <0 KO (bloque l'action), 0 ignoré, >0 OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('giftvoucher')) {
			return 0;
		}
		if ($action === 'BILL_VALIDATE') {
			return $this->autoCreateVouchersFromInvoice($object, $user, $langs);
		}
		if ($action !== 'PAYMENT_CUSTOMER_CREATE') {
			return 0;
		}
		$modeid = getDolGlobalInt('GIFTVOUCHER_PAYMENT_MODE_ID', 109);
		$paymode = 0;
		if (!empty($object->paiementid)) {
			$paymode = (int) $object->paiementid;
		} elseif (!empty($object->fk_paiement)) {
			$paymode = (int) $object->fk_paiement;
		}
		if ($paymode != $modeid) {
			return 0;
		}
		$num = trim((string) ($object->num_payment ?? $object->num_paiement ?? ''));
		if ($num === '') {
			return 0; // pas de code fourni (flux TakePOS) : contrôle via la page Scanner
		}

		dol_include_once('/giftvoucher/class/giftvoucher.class.php');
		$langs->load('giftvoucher@giftvoucher');
		$voucher = new GiftVoucher($this->db);
		$found = $voucher->fetch(0, $num);
		if ($found <= 0) {
			$this->errors[] = $langs->trans('GiftVoucherNotFound', $num);
			return -1;
		}
		$fk_facture = 0;
		if (!empty($object->amounts) && is_array($object->amounts)) {
			$keys = array_keys($object->amounts);
			if (count($keys) == 1) {
				$fk_facture = (int) $keys[0];
			}
		}
		$result = $voucher->redeem($user, $fk_facture);
		if ($result <= 0) {
			$this->errors[] = $langs->trans($voucher->error).' ('.$voucher->ref.')';
			return -1;
		}
		// lier le paiement créé
		if (!empty($object->id)) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."giftvoucher SET fk_paiement = ".((int) $object->id)." WHERE rowid = ".((int) $voucher->id));
		}
		return 1;
	}

	/**
	 * Vente du produit « chèque cadeau » (GIFTVOUCHER_PRODUCT_ID) : un bon par unité vendue,
	 * du montant TTC unitaire de la ligne. Idempotent par facture.
	 *
	 * @param CommonObject $invoice Facture validée
	 * @param User $user User
	 * @param Translate $langs Langs
	 * @return int 0 ou 1 (ne bloque jamais la validation)
	 */
	private function autoCreateVouchersFromInvoice($invoice, User $user, Translate $langs)
	{
		$pid = getDolGlobalInt('GIFTVOUCHER_PRODUCT_ID');
		if ($pid <= 0 || empty($invoice->id)) {
			return 0;
		}
		try {
			$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."giftvoucher WHERE fk_facture_emission = ".((int) $invoice->id);
			$obj = $this->db->fetch_object($this->db->query($sql));
			if ($obj && $obj->nb > 0) {
				return 0; // déjà générés pour cette facture
			}
			$sql = "SELECT qty, total_ttc FROM ".MAIN_DB_PREFIX."facturedet WHERE fk_facture = ".((int) $invoice->id)." AND fk_product = ".((int) $pid);
			$resql = $this->db->query($sql);
			if (!$resql) {
				return 0;
			}
			dol_include_once('/giftvoucher/class/giftvoucher.class.php');
			$created = array();
			while ($line = $this->db->fetch_object($resql)) {
				$qty = max(1, (int) round((float) $line->qty));
				$unit = round((float) $line->total_ttc / $qty, 2);
				for ($i = 0; $i < $qty; $i++) {
					$voucher = new GiftVoucher($this->db);
					$voucher->amount = $unit;
					$voucher->type_voucher = GiftVoucher::TYPE_GIFT;
					$voucher->date_emission = dol_now();
					$voucher->fk_facture_emission = (int) $invoice->id;
					$voucher->fk_soc = !empty($invoice->socid) ? (int) $invoice->socid : 0;
					$voucher->note_public = 'Vendu sur '.$invoice->ref;
					if ($voucher->create($user) > 0) {
						$created[] = $voucher->ref;
					}
				}
			}
			if (count($created)) {
				dol_syslog('Giftvoucher: created '.count($created).' voucher(s) from invoice '.$invoice->ref.': '.implode(', ', $created));
				if (function_exists('setEventMessages')) {
					$langs->load('giftvoucher@giftvoucher');
					setEventMessages($langs->trans('GiftVoucherAutoCreated', count($created), implode(', ', $created)), null, 'mesgs');
				}
			}
		} catch (Exception $e) {
			dol_syslog('Giftvoucher autoCreate error: '.$e->getMessage(), LOG_ERR);
		}
		return 1;
	}
}
