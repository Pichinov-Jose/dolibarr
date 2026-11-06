<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
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
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/actions_giftvoucher.class.php
 * \ingroup giftvoucher
 * \brief   Encart « Bons d'achat » sur la fiche tiers.
 *
 * Chez Argonaute la quasi-totalité des bons est nominative (586 sur 600 à la reprise Kezia),
 * à l'inverse de PecheCenter où presque tout est anonyme : depuis la fiche client, le commerçant
 * voit les bons encore valables et ceux déjà consommés.
 */
class ActionsGiftvoucher
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var string */
	public $resprints = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook formObjectOptions (fiche tiers, consultation) : une ligne « Bons d'achat » avec les bons
	 * du tiers et le total encore valable.
	 *
	 * Retourne toujours 0 : formObjectOptions est un hook « addreplace », une valeur > 0 masquerait
	 * les attributs supplémentaires du tiers et la sortie des autres modules.
	 *
	 * @param array<string,mixed>	$parameters		Hook parameters (contains 'context', 'cols')
	 * @param CommonObject			$object			Current object (Societe on the thirdparty card)
	 * @param string				$action			Current action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		if (empty($parameters['context']) || !in_array('thirdpartycard', explode(':', $parameters['context']))) {
			return 0;
		}
		if (in_array($action, array('create', 'edit')) || !isModEnabled('giftvoucher') || empty($object->id)) {
			return 0;
		}
		if (!$user->hasRight('giftvoucher', 'read')) {
			return 0;
		}
		dol_include_once('/giftvoucher/lib/giftvoucher.lib.php');
		if (!giftvoucherIsNominative($object->id)) {
			return 0; // client par défaut d'une caisse : ses bons sont au porteur
		}

		$sql = "SELECT rowid, ref, amount, type_voucher, date_validite, status";
		$sql .= " FROM ".$this->db->prefix()."giftvoucher";
		$sql .= " WHERE entity IN (".getEntity('giftvoucher').")";
		$sql .= " AND fk_soc = ".((int) $object->id);
		$sql .= " ORDER BY status ASC, date_validite DESC, rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(get_class($this).'::formObjectOptions '.$this->db->lasterror(), LOG_ERR);
			return 0;
		}
		if (!$this->db->num_rows($resql)) {
			$this->db->free($resql);
			return 0;
		}

		$langs->load('giftvoucher@giftvoucher');
		dol_include_once('/giftvoucher/class/giftvoucher.class.php');

		$rows = '';
		$totalopen = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$voucher = new GiftVoucher($this->db);
			$voucher->id = (int) $obj->rowid;
			$voucher->ref = $obj->ref;
			$voucher->status = (int) $obj->status;
			$voucher->date_validite = $this->db->jdate($obj->date_validite);
			if ($voucher->isRedeemable()) {
				$totalopen += (float) $obj->amount;
			}
			$rows .= '<tr class="oddeven">';
			$rows .= '<td>'.$voucher->getNomUrl(1).'</td>';
			$rows .= '<td>'.$langs->trans($obj->type_voucher == GiftVoucher::TYPE_CREDIT ? 'GiftVoucherTypeCredit' : 'GiftVoucherTypeGift').'</td>';
			$rows .= '<td class="right nowraponall">'.price($obj->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
			$rows .= '<td class="center">'.dol_print_date($voucher->date_validite, 'day').'</td>';
			$rows .= '<td class="right">'.$voucher->getLibStatut(3).'</td>';
			$rows .= '</tr>';
		}
		$this->db->free($resql);

		// Une ligne du tableau de la fiche, construite comme celles des attributs supplémentaires
		// (core/tpl/extrafields_view.tpl.php) : libellé à gauche, liste des bons à droite.
		$colspan = empty($parameters['cols']) ? 1 : (int) $parameters['cols'];
		$out = '<tr class="giftvoucher-thirdparty"><td class="tdtop">'.img_picto('', 'fa-gift', 'class="pictofixedwidth"').$langs->trans('GiftVoucherThirdpartyBlock').'</td>';
		$out .= '<td'.($colspan > 1 ? ' colspan="'.$colspan.'"' : '').'>';
		$out .= '<table class="noborder centpercent">';
		$out .= $rows;
		$out .= '<tr class="liste_total">';
		$out .= '<td colspan="2">'.$langs->trans('GiftVoucherThirdpartyTotalOpen').'</td>';
		$out .= '<td class="right nowraponall"><b>'.price($totalopen, 0, $langs, 1, -1, -1, $conf->currency).'</b></td>';
		$out .= '<td colspan="2"></td>';
		$out .= '</tr>';
		$out .= '</table>';
		$out .= '</td></tr>';

		$this->resprints = $out;

		return 0;
	}
}
