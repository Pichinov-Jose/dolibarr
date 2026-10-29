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
 * \file    advancedpurchasehistory/class/purchasehistory.class.php
 * \ingroup advancedpurchasehistory
 * \brief   Collects the purchase events of a product from the four sources Dolibarr keeps.
 */
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

/**
 * Purchase history of one product.
 *
 * A row is an associative array: source, date (timestamp), socid, ref_fourn, doctype, docid, docref,
 * qty, pu (unit price excl. tax before discount), remise (percent or null), net (unit price after discount),
 * author, variation (percent versus the previous row of the same source and supplier, or null).
 */
class PurchaseHistory
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var array<int,Societe> Suppliers met in the rows, by id
	 */
	public $suppliers = array();

	/**
	 * @var array<int,float> Current lowest unit purchase price per supplier
	 */
	public $currentPrices = array();

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
	 * Codes of the sources, in display order
	 *
	 * @return string[]
	 */
	public static function sources()
	{
		return array('log', 'invoice', 'order', 'reception');
	}

	/**
	 * Load every purchase event of a product, most recent first
	 *
	 * @param int $fk_product Product id
	 * @return array<int,array<string,mixed>>|int<-1,-1> Rows, or -1 on error
	 */
	public function fetchRows($fk_product)
	{
		$p = (int) $fk_product;
		$pre = MAIN_DB_PREFIX;
		$queries = array(
			// the log keeps the price of the supplier price line for its quantity tier: no purchased quantity
			'log' => "SELECT l.rowid, l.datec as d, l.price as pu, l.quantity as tier, NULL as qty, NULL as remise,"
				." pfp.fk_soc, pfp.ref_fourn, 'pricelog' as doctype, NULL as docid, NULL as docref, u.login"
				." FROM ".$pre."product_fournisseur_price_log as l"
				." JOIN ".$pre."product_fournisseur_price as pfp ON pfp.rowid = l.fk_product_fournisseur"
				." LEFT JOIN ".$pre."user as u ON u.rowid = l.fk_user"
				." WHERE pfp.fk_product = ".$p." AND pfp.entity IN (".getEntity('productsupplierprice').")",
			'invoice' => "SELECT fd.rowid, f.datef as d, fd.pu_ht as pu, 1 as tier, fd.qty, fd.remise_percent as remise,"
				." f.fk_soc, fd.ref as ref_fourn, 'supplier_invoice' as doctype, f.rowid as docid, f.ref as docref, u.login"
				." FROM ".$pre."facture_fourn_det as fd"
				." JOIN ".$pre."facture_fourn as f ON f.rowid = fd.fk_facture_fourn"
				." LEFT JOIN ".$pre."user as u ON u.rowid = f.fk_user_author"
				." WHERE fd.fk_product = ".$p." AND f.fk_statut IN (1, 2) AND f.type = 0"
				." AND f.entity IN (".getEntity('supplier_invoice').")",
			'order' => "SELECT cd.rowid, COALESCE(c.date_commande, c.date_valid, c.date_creation) as d, cd.subprice as pu, 1 as tier,"
				." cd.qty, cd.remise_percent as remise, c.fk_soc, cd.ref as ref_fourn, 'order_supplier' as doctype,"
				." c.rowid as docid, c.ref as docref, u.login"
				." FROM ".$pre."commande_fournisseurdet as cd"
				." JOIN ".$pre."commande_fournisseur as c ON c.rowid = cd.fk_commande"
				." LEFT JOIN ".$pre."user as u ON u.rowid = c.fk_user_author"
				." WHERE cd.fk_product = ".$p." AND c.fk_statut IN (1, 2, 3, 4, 5)"
				." AND c.entity IN (".getEntity('supplier_order').")",
			// stock entries carry the unit cost actually booked, discount included
			'reception' => "SELECT m.rowid, m.datem as d, m.price as pu, 1 as tier, m.value as qty, NULL as remise,"
				." COALESCE(r.fk_soc, c.fk_soc) as fk_soc, NULL as ref_fourn,"
				." CASE WHEN r.rowid IS NOT NULL THEN 'reception' WHEN c.rowid IS NOT NULL THEN 'order_supplier' ELSE NULL END as doctype,"
				." COALESCE(r.rowid, c.rowid) as docid, COALESCE(r.ref, c.ref) as docref, u.login"
				." FROM ".$pre."stock_mouvement as m"
				." LEFT JOIN ".$pre."reception as r ON m.origintype = 'reception' AND r.rowid = m.fk_origin"
				." LEFT JOIN ".$pre."commande_fournisseur as c ON m.origintype = 'order_supplier' AND c.rowid = m.fk_origin"
				." LEFT JOIN ".$pre."user as u ON u.rowid = m.fk_user_author"
				." WHERE m.fk_product = ".$p." AND m.type_mouvement IN (0, 3) AND m.value > 0 AND m.price > 0"
				." AND m.origintype IN ('reception', 'order_supplier')",
		);

		$rows = array();
		$order = array_flip(self::sources());
		foreach ($queries as $source => $sql) {
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$pu = (float) $obj->pu;
				if ((float) $obj->tier > 0) {
					$pu = $pu / (float) $obj->tier;
				}
				$remise = ($obj->remise === null ? null : (float) $obj->remise);
				$rows[] = array(
					'source' => $source,
					'rank' => $order[$source],
					'rowid' => (int) $obj->rowid,
					'date' => $this->db->jdate($obj->d),
					'socid' => (int) $obj->fk_soc,
					'ref_fourn' => (string) $obj->ref_fourn,
					'doctype' => $obj->doctype,
					'docid' => (int) $obj->docid,
					'docref' => (string) $obj->docref,
					'qty' => ($obj->qty === null ? null : (float) $obj->qty),
					'pu' => $pu,
					'remise' => $remise,
					'net' => ($remise ? $pu * (1 - $remise / 100) : $pu),
					'author' => (string) $obj->login,
					'variation' => null,
				);
			}
			$this->db->free($resql);
		}

		usort($rows, function ($a, $b) {
			if ($a['date'] != $b['date']) {
				return ($a['date'] < $b['date'] ? -1 : 1);
			}
			if ($a['rank'] != $b['rank']) {
				return $a['rank'] - $b['rank'];
			}
			return $a['rowid'] - $b['rowid'];
		});
		$previous = array();
		foreach ($rows as $i => $row) {
			$key = $row['source'].'|'.$row['socid'];
			if (!empty($previous[$key]) && $row['net'] > 0) {
				$rows[$i]['variation'] = ($row['net'] - $previous[$key]) / $previous[$key] * 100;
			}
			if ($row['net'] > 0) {
				$previous[$key] = $row['net'];
			}
		}
		$rows = array_reverse($rows);

		if ($this->loadCurrentPrices($p) < 0 || $this->loadSuppliers($rows) < 0) {
			return -1;
		}

		return $rows;
	}

	/**
	 * Keep the rows matching the filters
	 *
	 * @param array<int,array<string,mixed>> $rows     Rows from fetchRows()
	 * @param string[]                       $sources  Sources to keep
	 * @param int                            $socid    Supplier to keep, 0 for all
	 * @param int|string                     $datefrom Lower bound timestamp, '' for none
	 * @param int|string                     $dateto   Upper bound timestamp, '' for none
	 * @return array<int,array<string,mixed>>
	 */
	public static function filterRows($rows, $sources, $socid = 0, $datefrom = '', $dateto = '')
	{
		$out = array();
		foreach ($rows as $row) {
			if (!in_array($row['source'], $sources)) {
				continue;
			}
			if ($socid > 0 && $row['socid'] != $socid) {
				continue;
			}
			if ($datefrom !== '' && $row['date'] < $datefrom) {
				continue;
			}
			if ($dateto !== '' && $row['date'] > $dateto) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Per supplier figures: last price, min, max, number of events, quantity received, current price
	 *
	 * @param array<int,array<string,mixed>> $rows Rows, most recent first
	 * @return array<int,array<string,mixed>>      Keyed by supplier id, most active supplier first
	 */
	public function summary($rows)
	{
		$sum = array();
		foreach ($rows as $row) {
			if ($row['net'] <= 0) {
				continue;
			}
			$s = $row['socid'];
			if (!isset($sum[$s])) {
				$sum[$s] = array('last' => $row, 'min' => $row['net'], 'max' => $row['net'], 'nb' => 0, 'qtyreceived' => 0);
			}
			$sum[$s]['min'] = min($sum[$s]['min'], $row['net']);
			$sum[$s]['max'] = max($sum[$s]['max'], $row['net']);
			$sum[$s]['nb']++;
			if ($row['source'] == 'reception') {
				$sum[$s]['qtyreceived'] += $row['qty'];
			}
		}
		foreach ($this->currentPrices as $s => $price) {
			if (!isset($sum[$s])) {
				$sum[$s] = array('last' => null, 'min' => null, 'max' => null, 'nb' => 0, 'qtyreceived' => 0);
			}
		}
		uasort($sum, function ($a, $b) {
			return $b['nb'] - $a['nb'];
		});
		return $sum;
	}

	/**
	 * Monthly unit price per supplier, carried forward from the first purchase, for DolGraph
	 *
	 * @param array<int,array<string,mixed>> $rows      Rows, most recent first
	 * @param int                            $maxseries Number of suppliers drawn (most active ones)
	 * @return array{0:array<int,array<int,mixed>>,1:string[]} Data and legend
	 */
	public function chartData($rows, $maxseries = 6)
	{
		$bysoc = array();
		$count = array();
		$first = null;
		$last = null;
		foreach (array_reverse($rows) as $row) {
			if ($row['net'] <= 0 || $row['socid'] <= 0) {
				continue;
			}
			$month = dol_print_date($row['date'], '%Y-%m');
			$bysoc[$row['socid']][$month] = $row['net'];
			$count[$row['socid']] = (isset($count[$row['socid']]) ? $count[$row['socid']] : 0) + 1;
			$first = ($first === null || $month < $first ? $month : $first);
			$last = ($last === null || $month > $last ? $month : $last);
		}
		if ($first === null) {
			return array(array(), array());
		}
		arsort($count);
		$socids = array_slice(array_keys($count), 0, $maxseries);

		$legend = array();
		foreach ($socids as $s) {
			$legend[] = (isset($this->suppliers[$s]) ? $this->suppliers[$s]->name : '#'.$s);
		}

		$data = array();
		$current = array();
		list($y, $m) = array_map('intval', explode('-', $first));
		list($ly, $lm) = array_map('intval', explode('-', $last));
		while ($y < $ly || ($y == $ly && $m <= $lm)) {
			$month = sprintf('%04d-%02d', $y, $m);
			$line = array(sprintf('%02d/%04d', $m, $y));
			foreach ($socids as $s) {
				if (isset($bysoc[$s][$month])) {
					$current[$s] = $bysoc[$s][$month];
				}
				$line[] = (isset($current[$s]) ? round($current[$s], 2) : null);
			}
			$data[] = $line;
			$m++;
			if ($m > 12) {
				$m = 1;
				$y++;
			}
		}
		return array($data, $legend);
	}

	/**
	 * Load the current lowest unit price per supplier of the product
	 *
	 * @param int $fk_product Product id
	 * @return int<-1,1>
	 */
	private function loadCurrentPrices($fk_product)
	{
		$sql = "SELECT fk_soc, MIN(unitprice) as unitprice FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
		$sql .= " WHERE fk_product = ".((int) $fk_product)." AND entity IN (".getEntity('productsupplierprice').")";
		$sql .= " GROUP BY fk_soc";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$this->currentPrices[(int) $obj->fk_soc] = (float) $obj->unitprice;
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Load the suppliers met in the rows or in the current prices
	 *
	 * @param array<int,array<string,mixed>> $rows Rows
	 * @return int<-1,1>
	 */
	private function loadSuppliers($rows)
	{
		$ids = array_keys($this->currentPrices);
		foreach ($rows as $row) {
			$ids[] = $row['socid'];
		}
		$ids = array_filter(array_unique(array_map('intval', $ids)));
		if (empty($ids)) {
			return 1;
		}
		$sql = "SELECT rowid, nom, name_alias, code_fournisseur FROM ".MAIN_DB_PREFIX."societe WHERE rowid IN (".implode(',', $ids).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$soc = new Societe($this->db);
			$soc->id = (int) $obj->rowid;
			$soc->name = $obj->nom;
			$soc->name_alias = $obj->name_alias;
			$soc->code_fournisseur = $obj->code_fournisseur;
			$soc->fournisseur = 1;
			$this->suppliers[$soc->id] = $soc;
		}
		$this->db->free($resql);
		return 1;
	}
}
