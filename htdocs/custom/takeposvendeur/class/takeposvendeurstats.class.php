<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/takeposvendeurstats.class.php
 * \ingroup takeposvendeur
 * \brief   Calcul des statistiques de ventes par vendeur.
 *
 * Vendeur d'une vente = extrafield facture fk_vendeur (choisi en caisse).
 * Un avoir sans vendeur prend celui de sa facture d'origine (TakePOS ne recopie
 * pas les champs complementaires quand il cree un avoir).
 *
 * Perimetre : factures clients validees (payees ou non), memes types que le
 * rapport natif « CA par utilisateur » (acomptes exclus si
 * FACTURE_DEPOSITS_ARE_JUST_PAYMENTS).
 *
 * Un avoir (ou trop-percu) utilise pour regler un ticket y figure en ligne negative :
 * cette ligne est ignoree, l'avoir etant deja deduit du CA a sa creation.
 *
 * Marge : prix d'achat enregistre sur la ligne s'il est renseigne, sinon cout
 * actuel du produit selon la cascade du module (TAKEPOSVENDEUR_COST_SOURCES),
 * comme la colonne marge de la caisse. Les lignes sans cout connu sont exclues
 * du calcul de marge (meme regle que la marge globale en caisse).
 */
class TakeposvendeurStats
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** Valeur speciale du filtre caisse : ventes hors caisse (pos_source vide). */
	const CAISSE_NONE = '_none_';

	/** Valeur speciale du filtre vendeur : ventes sans vendeur. */
	const VENDEUR_NONE = -1;

	/**
	 * @param DoliDB $db Handler base de donnees
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Cascade ordonnee des sources de cout (meme lecture que la caisse).
	 *
	 * @return string[]
	 */
	public static function costSources()
	{
		$csv = getDolGlobalString('TAKEPOSVENDEUR_COST_SOURCES', '');
		if ($csv !== '') {
			$list = array();
			foreach (explode(',', $csv) as $s) {
				$s = trim($s);
				if ($s !== '') {
					$list[] = $s;
				}
			}
			if (!empty($list)) {
				return $list;
			}
		}
		$base = getDolGlobalString('TAKEPOSVENDEUR_COST_BASIS', '');
		if ($base == 'cost') {
			return array('cost_price', 'pmp', 'supplier_min');
		}
		if ($base == 'supplier') {
			return array('supplier_min', 'pmp', 'cost_price');
		}
		return array('pmp', 'cost_price', 'supplier_min');
	}

	/**
	 * Colonnes de llx_product_extrafields (pour valider les sources ef:).
	 *
	 * @return string[]
	 */
	private function productExtrafieldColumns()
	{
		$cols = array();
		$resql = $this->db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."product_extrafields");
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$cols[] = $obj->Field;
			}
		}
		return $cols;
	}

	/**
	 * Expression SQL du cout unitaire produit selon la cascade + jointures necessaires.
	 * Alias attendus : d = ligne de facture.
	 *
	 * @param  string $joins Jointures SQL a ajouter (sortie)
	 * @return string        Expression SQL (NULL si aucune source > 0)
	 */
	private function productCostSql(&$joins)
	{
		$parts = array();
		$needP = false;
		$needSf = false;
		$needEf = false;
		$efcols = null;
		foreach (self::costSources() as $s) {
			if ($s === 'pmp') {
				$parts[] = "CASE WHEN p.pmp > 0 THEN p.pmp END";
				$needP = true;
			} elseif ($s === 'cost_price') {
				$parts[] = "CASE WHEN p.cost_price > 0 THEN p.cost_price END";
				$needP = true;
			} elseif ($s === 'supplier_min') {
				$parts[] = "CASE WHEN sf.minprice > 0 THEN sf.minprice END";
				$needSf = true;
			} elseif (strncmp($s, 'ef:', 3) === 0) {
				$field = preg_replace('/[^a-zA-Z0-9_]/', '', substr($s, 3));
				if ($field === '') {
					continue;
				}
				if ($efcols === null) {
					$efcols = $this->productExtrafieldColumns();
				}
				if (!in_array($field, $efcols, true)) {
					continue; // champ absent : source ignoree, comme en caisse
				}
				$parts[] = "CASE WHEN pe.".$field." > 0 THEN pe.".$field." END";
				$needEf = true;
			}
		}
		$joins = '';
		if ($needP) {
			$joins .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = d.fk_product";
		}
		if ($needEf) {
			$joins .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = d.fk_product";
		}
		if ($needSf) {
			// Meme valeur que la caisse : MIN(price) des prix fournisseurs > 0.
			$joins .= " LEFT JOIN (SELECT fk_product, MIN(price) as minprice FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE price > 0 GROUP BY fk_product) as sf ON sf.fk_product = d.fk_product";
		}
		if (empty($parts)) {
			return 'NULL';
		}
		return 'COALESCE('.implode(', ', $parts).')';
	}

	/**
	 * Condition SQL (alias de ligne $alias) : ligne qui consomme un credit client
	 * (avoir ou trop-percu converti en remise, insere en ligne negative sur un ticket).
	 * Ce credit est un moyen de paiement : l'avoir est deja deduit du CA a sa creation,
	 * le trop-percu n'a jamais ete du CA. Ces lignes ne diminuent donc pas les ventes.
	 * Meme logique pour les acomptes quand ils sont traites comme des paiements.
	 *
	 * @param  string $alias Alias de la table facturedet
	 * @return string
	 */
	private function creditLineSql($alias)
	{
		$markers = array("'(CREDIT_NOTE)'", "'(EXCESS RECEIVED)'");
		if (getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS')) {
			$markers[] = "'(DEPOSIT)'";
		}
		return "(".$alias.".fk_remise_except IS NOT NULL AND ".$alias.".description IN (".implode(',', $markers)."))";
	}

	/**
	 * Expression SQL du vendeur d'une facture (avoir : repli sur la facture d'origine).
	 *
	 * @return string
	 */
	private function vendeurSql()
	{
		return "COALESCE(NULLIF(ef.fk_vendeur, 0), NULLIF(efs.fk_vendeur, 0))";
	}

	/**
	 * FROM + WHERE communs (factures filtrees).
	 *
	 * @param  array  $filters    date_start, date_end (timestamps), caisses (string[]), vendeurs (int[])
	 * @param  string $extraJoins Jointures supplementaires (lignes, produits)
	 * @param  string $extraWhere Conditions supplementaires (commencant par AND)
	 * @return string
	 */
	private function baseFromWhere($filters, $extraJoins = '', $extraWhere = '')
	{
		$sql = " FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_extrafields as ef ON ef.fk_object = f.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_extrafields as efs ON efs.fk_object = f.fk_facture_source AND f.type = 2";
		$sql .= $extraJoins;
		$sql .= " WHERE f.entity IN (".getEntity('invoice').")";
		$sql .= " AND f.fk_statut IN (1,2)";
		$sql .= $extraWhere;
		if (getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS')) {
			$sql .= " AND f.type IN (0,1,2,5)";
		} else {
			$sql .= " AND f.type IN (0,1,2,3,5)";
		}
		if (!empty($filters['date_start'])) {
			$sql .= " AND f.datef >= '".$this->db->idate($filters['date_start'])."'";
		}
		if (!empty($filters['date_end'])) {
			$sql .= " AND f.datef <= '".$this->db->idate($filters['date_end'])."'";
		}

		// Caisses (pos_source) ; CAISSE_NONE = hors caisse.
		if (!empty($filters['caisses'])) {
			$vals = array();
			$none = false;
			foreach ($filters['caisses'] as $c) {
				if ($c === self::CAISSE_NONE) {
					$none = true;
				} elseif ($c !== '') {
					$vals[] = "'".$this->db->escape($c)."'";
				}
			}
			$or = array();
			if (!empty($vals)) {
				$or[] = "f.pos_source IN (".implode(',', $vals).")";
			}
			if ($none) {
				$or[] = "(f.pos_source IS NULL OR f.pos_source = '')";
			}
			if (!empty($or)) {
				$sql .= " AND (".implode(' OR ', $or).")";
			}
		}

		// Vendeurs ; VENDEUR_NONE = sans vendeur.
		if (!empty($filters['vendeurs'])) {
			$ids = array();
			$none = false;
			foreach ($filters['vendeurs'] as $v) {
				$v = (int) $v;
				if ($v == self::VENDEUR_NONE) {
					$none = true;
				} elseif ($v > 0) {
					$ids[] = $v;
				}
			}
			$or = array();
			if (!empty($ids)) {
				$or[] = $this->vendeurSql()." IN (".implode(',', $ids).")";
			}
			if ($none) {
				$or[] = $this->vendeurSql()." IS NULL";
			}
			if (!empty($or)) {
				$sql .= " AND (".implode(' OR ', $or).")";
			}
		}
		return $sql;
	}

	/**
	 * Statistiques par vendeur.
	 *
	 * @param  array      $filters   Voir baseFromWhere()
	 * @param  bool       $withMargin Calculer la marge (requete sur les lignes)
	 * @return array|int             array('rows' => [vid => stats], 'total' => stats) ou -1
	 */
	public function compute($filters, $withMargin = true)
	{
		$rows = array();
		$vend = $this->vendeurSql();

		// 1. Niveau facture : tickets, avoirs, montants (hors credits clients utilises en reglement).
		$cnjoin = " LEFT JOIN (SELECT dc.fk_facture, SUM(dc.total_ht) as ht, SUM(dc.total_ttc) as ttc";
		$cnjoin .= " FROM ".MAIN_DB_PREFIX."facturedet as dc WHERE ".$this->creditLineSql('dc')." GROUP BY dc.fk_facture) as cn ON cn.fk_facture = f.rowid";
		$ht = "(f.total_ht - COALESCE(cn.ht, 0))";
		$ttc = "(f.total_ttc - COALESCE(cn.ttc, 0))";
		$sql = "SELECT ".$vend." as vid,";
		$sql .= " SUM(CASE WHEN f.type <> 2 THEN 1 ELSE 0 END) as nb_tickets,";
		$sql .= " SUM(CASE WHEN f.type = 2 THEN 1 ELSE 0 END) as nb_avoirs,";
		$sql .= " SUM(CASE WHEN f.type <> 2 THEN ".$ht." ELSE 0 END) as ventes_ht,";
		$sql .= " SUM(CASE WHEN f.type <> 2 THEN ".$ttc." ELSE 0 END) as ventes_ttc,";
		$sql .= " SUM(CASE WHEN f.type = 2 THEN ".$ht." ELSE 0 END) as avoirs_ht,";
		$sql .= " SUM(CASE WHEN f.type = 2 THEN ".$ttc." ELSE 0 END) as avoirs_ttc";
		$sql .= $this->baseFromWhere($filters, $cnjoin);
		$sql .= " GROUP BY ".$vend;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$vid = (int) $obj->vid;
			$rows[$vid] = $this->emptyStats();
			$rows[$vid]['nb_tickets'] = (int) $obj->nb_tickets;
			$rows[$vid]['nb_avoirs'] = (int) $obj->nb_avoirs;
			$rows[$vid]['ventes_ht'] = (float) $obj->ventes_ht;
			$rows[$vid]['ventes_ttc'] = (float) $obj->ventes_ttc;
			$rows[$vid]['avoirs_ht'] = (float) $obj->avoirs_ht;
			$rows[$vid]['avoirs_ttc'] = (float) $obj->avoirs_ttc;
		}
		$this->db->free($resql);

		// 2. Niveau ligne : marge.
		if ($withMargin && !empty($rows)) {
			$joins = '';
			$pcost = $this->productCostSql($joins);
			$ucost = "CASE WHEN d.buy_price_ht > 0 THEN d.buy_price_ht ELSE ".$pcost." END";
			// Sens de la ligne : un retour (montant negatif, ou ligne d'avoir a 0) retire son cout.
			$sens = "CASE WHEN d.total_ht < 0 THEN -1 WHEN d.total_ht > 0 THEN 1 WHEN f.type = 2 THEN -1 ELSE 1 END";

			$sql = "SELECT ".$vend." as vid,";
			$sql .= " SUM(ABS(d.total_ht)) as ht_lignes_abs,";
			$sql .= " SUM(CASE WHEN (".$ucost.") > 0 THEN ABS(d.total_ht) ELSE 0 END) as ht_connu_abs,";
			$sql .= " SUM(CASE WHEN (".$ucost.") > 0 THEN d.total_ht ELSE 0 END) as ht_connu,";
			$sql .= " SUM(CASE WHEN (".$ucost.") > 0 THEN (".$sens.") * ABS(d.qty) * (".$ucost.") ELSE 0 END) as cout";
			$sql .= $this->baseFromWhere($filters, " INNER JOIN ".MAIN_DB_PREFIX."facturedet as d ON d.fk_facture = f.rowid".$joins, " AND NOT ".$this->creditLineSql('d'));
			$sql .= " GROUP BY ".$vend;
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$vid = (int) $obj->vid;
				if (!isset($rows[$vid])) {
					continue;
				}
				$rows[$vid]['ht_lignes_abs'] = (float) $obj->ht_lignes_abs;
				$rows[$vid]['ht_connu_abs'] = (float) $obj->ht_connu_abs;
				$rows[$vid]['ht_connu'] = (float) $obj->ht_connu;
				$rows[$vid]['cout'] = (float) $obj->cout;
			}
			$this->db->free($resql);
		}

		// 3. Indicateurs derives + total.
		$total = $this->emptyStats();
		foreach ($rows as $vid => $r) {
			foreach ($total as $k => $v) {
				$total[$k] += $r[$k];
			}
		}
		foreach ($rows as $vid => $r) {
			$rows[$vid] = $this->derive($r, $total);
		}
		$total = $this->derive($total, $total);

		return array('rows' => $rows, 'total' => $total);
	}

	/**
	 * Ligne vide avec ses indicateurs (vendeur absent d'une periode comparee).
	 *
	 * @return array
	 */
	public function blankRow()
	{
		return $this->derive($this->emptyStats(), $this->emptyStats());
	}

	/**
	 * @return array Compteurs a zero
	 */
	private function emptyStats()
	{
		return array(
			'nb_tickets' => 0, 'nb_avoirs' => 0,
			'ventes_ht' => 0.0, 'ventes_ttc' => 0.0, 'avoirs_ht' => 0.0, 'avoirs_ttc' => 0.0,
			'ht_lignes_abs' => 0.0, 'ht_connu_abs' => 0.0, 'ht_connu' => 0.0, 'cout' => 0.0,
		);
	}

	/**
	 * Ajoute les indicateurs calcules.
	 *
	 * @param  array $r     Compteurs d'une ligne
	 * @param  array $total Compteurs du total (pour la part du CA)
	 * @return array
	 */
	private function derive($r, $total)
	{
		$r['ca_ht'] = $r['ventes_ht'] + $r['avoirs_ht'];
		$r['ca_ttc'] = $r['ventes_ttc'] + $r['avoirs_ttc'];
		$r['panier'] = $r['nb_tickets'] > 0 ? $r['ventes_ttc'] / $r['nb_tickets'] : 0.0;
		$r['marge'] = $r['ht_connu'] - $r['cout'];
		$r['taux'] = ($r['ht_connu'] != 0) ? $r['marge'] / $r['ht_connu'] * 100 : null;
		// Couverture en valeur absolue : un echange (retour + vente) ne doit pas depasser 100 %.
		$r['couverture'] = ($r['ht_lignes_abs'] != 0) ? $r['ht_connu_abs'] / $r['ht_lignes_abs'] * 100 : null;
		$tot_ca = $total['ventes_ttc'] + $total['avoirs_ttc'];
		$r['part'] = ($tot_ca != 0) ? $r['ca_ttc'] / $tot_ca * 100 : 0.0;
		return $r;
	}

	/**
	 * Caisses presentes dans les factures (valeurs de pos_source) avec libelle.
	 *
	 * @return array valeur => libelle (CAISSE_NONE en dernier)
	 */
	public function listCaisses()
	{
		$out = array();
		$sql = "SELECT DISTINCT f.pos_source FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND f.pos_source IS NOT NULL AND f.pos_source <> ''";
		$resql = $this->db->query($sql);
		$vals = array();
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$vals[] = (string) $obj->pos_source;
			}
		}
		// Terminaux TakePOS configures, meme sans vente.
		$nb = getDolGlobalInt('TAKEPOS_NUM_TERMINALS');
		for ($i = 1; $i <= $nb; $i++) {
			if (!in_array((string) $i, $vals, true)) {
				$vals[] = (string) $i;
			}
		}
		usort($vals, function ($a, $b) {
			if (is_numeric($a) && is_numeric($b)) {
				return (int) $a - (int) $b;
			}
			return strcmp($a, $b);
		});
		foreach ($vals as $v) {
			$out[$v] = self::caisseLabel($v);
		}
		$out[self::CAISSE_NONE] = 'TpvOutsidePos';
		return $out;
	}

	/**
	 * Libelle d'une caisse : « 2 - Caisse Droite » si un terminal TakePOS porte ce numero.
	 *
	 * @param  string $v Valeur pos_source
	 * @return string
	 */
	public static function caisseLabel($v)
	{
		global $langs;
		$name = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$v);
		if ($name !== '') {
			return $v.' - '.$name;
		}
		return $langs->trans('TpvPosNumber', $v);
	}

	/**
	 * Vendeurs proposables dans le filtre : utilisateurs coches « Vendeur (caisse) »
	 * ou ayant au moins une vente.
	 *
	 * @return array rowid => objet (rowid, login, firstname, lastname, statut)
	 */
	public function listVendeurs()
	{
		$out = array();
		$sql = "SELECT u.rowid, u.login, u.firstname, u.lastname, u.statut";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " WHERE u.entity IN (".getEntity('user').")";
		$sql .= " AND (u.rowid IN (SELECT ue.fk_object FROM ".MAIN_DB_PREFIX."user_extrafields as ue WHERE ue.vendeur = 1)";
		$sql .= " OR u.rowid IN (SELECT DISTINCT ef.fk_vendeur FROM ".MAIN_DB_PREFIX."facture_extrafields as ef WHERE ef.fk_vendeur IS NOT NULL))";
		$sql .= " ORDER BY u.statut DESC, u.firstname, u.lastname";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[(int) $obj->rowid] = $obj;
			}
		}
		return $out;
	}
}
