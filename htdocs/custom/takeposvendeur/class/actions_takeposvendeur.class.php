<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_takeposvendeur.class.php
 * \ingroup takeposvendeur
 * \brief   Hooks TakePOS : sélection du vendeur par vente (façon Kezia) +
 *          marge par ligne / marge globale + code couleur des lignes.
 *
 * Contextes de hook : takeposinvoice (entête + lignes), takeposfrontend (ticket).
 * Tout est paramétrable depuis admin/setup.php (constantes TAKEPOSVENDEUR_*).
 */
class ActionsTakeposvendeur
{
	public $resprints = '';

	/** Cache coût produit (fk_product => coût unitaire HT). */
	private static $costcache = array();

	// Valeurs par défaut (si les constantes ne sont pas définies).
	const DEF_MARGE_VERT   = 30;
	const DEF_MARGE_ORANGE = 20;
	const DEF_COL_VERT     = '#c8e6c9';
	const DEF_COL_ORANGE   = '#ffe0b2';
	const DEF_COL_ROUGE    = '#ffcdd2';

	/* ---------- Lecture de la configuration ---------- */

	private function seuilVert()   { return getDolGlobalInt('TAKEPOSVENDEUR_MARGIN_GREEN', self::DEF_MARGE_VERT); }
	private function seuilOrange() { return getDolGlobalInt('TAKEPOSVENDEUR_MARGIN_ORANGE', self::DEF_MARGE_ORANGE); }
	private function colVert()     { return getDolGlobalString('TAKEPOSVENDEUR_COLOR_GREEN', self::DEF_COL_VERT); }
	private function colOrange()   { return getDolGlobalString('TAKEPOSVENDEUR_COLOR_ORANGE', self::DEF_COL_ORANGE); }
	private function colRouge()    { return getDolGlobalString('TAKEPOSVENDEUR_COLOR_RED', self::DEF_COL_ROUGE); }
	/** Marge affichée ? (par défaut oui). */
	private function showMarge()   { return !getDolGlobalString('TAKEPOSVENDEUR_HIDE_MARGIN'); }
	/** Sélection vendeur obligatoire en début de vente ? (par défaut oui). */
	private function vendeurObligatoire() { return !getDolGlobalString('TAKEPOSVENDEUR_VENDOR_OPTIONAL'); }
	/** Imprimer le vendeur sur le ticket ? (par défaut oui). */
	private function vendeurSurTicket()   { return !getDolGlobalString('TAKEPOSVENDEUR_HIDE_ON_RECEIPT'); }
	/** Base de coût : 'pmp' (défaut), 'cost' (cost_price), 'supplier'. */
	private function baseCout()    { return getDolGlobalString('TAKEPOSVENDEUR_COST_BASIS', 'pmp'); }

	/**
	 * Coût unitaire HT d'un produit selon la base configurée.
	 * @param  int   $fk_product
	 * @return float coût unitaire HT (0 si inconnu)
	 */
	private function tpvCost($fk_product)
	{
		global $db;
		$fk_product = (int) $fk_product;
		if ($fk_product <= 0) {
			return 0.0;
		}
		if (isset(self::$costcache[$fk_product])) {
			return self::$costcache[$fk_product];
		}
		$base = $this->baseCout();
		$cost = 0.0;

		if ($base != 'supplier') {
			$sql = "SELECT pmp, cost_price FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".$fk_product;
			$res = $db->query($sql);
			if ($res && ($o = $db->fetch_object($res))) {
				if ($base == 'cost') {
					if (!empty($o->cost_price) && (float) $o->cost_price > 0) $cost = (float) $o->cost_price;
					elseif (!empty($o->pmp) && (float) $o->pmp > 0) $cost = (float) $o->pmp;
				} else { // pmp
					if (!empty($o->pmp) && (float) $o->pmp > 0) $cost = (float) $o->pmp;
					elseif (!empty($o->cost_price) && (float) $o->cost_price > 0) $cost = (float) $o->cost_price;
				}
			}
		}
		if ($cost <= 0) {
			// repli : plus petit prix d'achat fournisseur connu
			$sql = "SELECT MIN(price) AS p FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".$fk_product." AND price > 0";
			$res = $db->query($sql);
			if ($res && ($o = $db->fetch_object($res)) && !empty($o->p)) {
				$cost = (float) $o->p;
			}
		}
		self::$costcache[$fk_product] = $cost;
		return $cost;
	}

	/**
	 * Marge d'une ligne. Retourne array(cout, pv_ht, marge, pct, connu).
	 * pct = taux de marque = (PV_HT - cout) / PV_HT * 100.
	 */
	private function tpvLineMarge($line)
	{
		$qty    = (float) $line->qty;
		$pv_ht  = (float) $line->total_ht;
		$cout_u = $this->tpvCost(!empty($line->fk_product) ? $line->fk_product : 0);
		$connu  = ($cout_u > 0);
		$cout   = $cout_u * $qty;
		$marge  = $pv_ht - $cout;
		$pct    = ($pv_ht != 0) ? ($marge / $pv_ht * 100) : 0;
		return array('cout' => $cout, 'pv_ht' => $pv_ht, 'marge' => $marge, 'pct' => $pct, 'connu' => $connu);
	}

	/** Couleur de fond selon le % de marge. */
	private function tpvColor($pct, $connu)
	{
		if (!$connu) return '';
		if ($pct >= $this->seuilVert())   return $this->colVert();
		if ($pct >= $this->seuilOrange()) return $this->colOrange();
		return $this->colRouge();
	}

	/** Formate un pourcentage de marge avec 1 décimale, quelle que soit la config décimales. */
	private function fmtPct($pct)
	{
		return price(price2num($pct, 2), 1, '', 1, -1, 1);
	}

	/** Couleur de texte (foncée) assortie au niveau de marge. */
	private function tpvTextColor($pct, $connu)
	{
		if (!$connu) return '#888';
		if ($pct >= $this->seuilVert())   return '#2e7d32';
		if ($pct >= $this->seuilOrange()) return '#e65100';
		return '#c62828';
	}

	/**
	 * Entête de la facture TakePOS (hook completeTakePosInvoiceHeader).
	 * Bouton Vendeur + (option) colonne Marge globale + script de coloration.
	 */
	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $user;

		$langs->load('takeposvendeur@takeposvendeur');

		if (!is_object($object) || empty($object->id)) {
			return 0;
		}

		$showmarge = $this->showMarge();

		// ---- Vendeur ----
		$object->fetch_optionals();
		$cur = !empty($object->array_options['options_fk_vendeur']) ? (int) $object->array_options['options_fk_vendeur'] : 0;

		$vendors = array();
		$sql = "SELECT u.rowid, u.firstname, u.lastname, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."user u";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."user_extrafields ef ON ef.fk_object = u.rowid";
		$sql .= " WHERE u.statut = 1 AND ef.vendeur = 1";
		$sql .= " ORDER BY u.lastname, u.firstname";
		$res = $db->query($sql);
		if ($res) {
			while ($o = $db->fetch_object($res)) {
				$nm = trim(($o->firstname ? $o->firstname.' ' : '').$o->lastname);
				$vendors[(int) $o->rowid] = ($nm !== '' ? $nm : $o->login);
			}
		}

		$curname = isset($vendors[$cur]) ? $vendors[$cur] : $langs->trans('TpvChoose');
		$ajaxurl = DOL_URL_ROOT.'/custom/takeposvendeur/ajax/savevendeur.php';

		$btns = '';
		foreach ($vendors as $id => $name) {
			$sel = ($id === $cur) ? 'background:#4caf50;color:#fff;' : 'background:#eee;';
			$btns .= '<button type="button" style="margin:5px;padding:14px 18px;font-size:1.15em;border:1px solid #ccc;border-radius:6px;cursor:pointer;'.$sel.'" onclick="tpvSet('.$id.')">'.dol_escape_htmltag($name).'</button>';
		}

		$h  = '<td class="linecoltotal" style="text-align:right;white-space:nowrap;">';
		$h .= '<span class="opacitymedium">'.$langs->trans('TpvVendor').'</span> ';
		$h .= '<button type="button" id="tpvBtn" style="font-weight:bold;padding:5px 12px;border-radius:6px;cursor:pointer;" onclick="document.getElementById(\'tpvModal\').style.display=\'flex\';return false;">'.dol_escape_htmltag($curname).'</button>';
		$h .= '</td>';

		// ---- Marge globale (option) ----
		if ($showmarge) {
			$g_pv = 0.0; $g_cout = 0.0; $g_connu = false;
			if (is_array($object->lines)) {
				foreach ($object->lines as $l) {
					if (!empty($l->fk_parent_line)) continue;
					$m = $this->tpvLineMarge($l);
					// N'agréger QUE les lignes dont le coût est connu : sinon une ligne
					// sans coût compterait comme 100 % de marge et gonflerait le global.
					if ($m['connu']) {
						$g_pv += $m['pv_ht'];
						$g_cout += $m['cout'];
						$g_connu = true;
					}
				}
			}
			$g_marge = $g_pv - $g_cout;
			$g_pct   = ($g_pv != 0) ? ($g_marge / $g_pv * 100) : 0;

			$h .= '<td class="linecolqty right" style="white-space:nowrap;">';
			$h .= '<span class="opacitymedium small">'.$langs->trans('TpvMargin').'</span><br>';
			if ($g_connu) {
				$h .= '<span style="font-weight:bold;color:'.$this->tpvTextColor($g_pct, true).';">'.price($g_marge).'<br>'.$this->fmtPct($g_pct).' %</span>';
			} else {
				$h .= '<span class="opacitymedium">-</span>';
			}
			$h .= '</td>';
		}

		// ---- Modale Vendeur ----
		$obligatoire = $this->vendeurObligatoire();
		$h .= '<div id="tpvModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;align-items:center;justify-content:center;">';
		$h .= '<div style="background:#fff;padding:24px;border-radius:10px;max-width:640px;max-height:80%;overflow:auto;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.4);">';
		$h .= '<h3 style="margin-top:0;">'.$langs->trans('TpvVendor').'</h3>';
		$h .= '<div>'.($btns !== '' ? $btns : '<i>'.$langs->trans('TpvNoVendor').'</i>').'</div>';
		if ($cur > 0 || !$obligatoire) {
			$h .= '<br><button type="button" style="padding:10px 20px;" onclick="document.getElementById(\'tpvModal\').style.display=\'none\';return false;">'.$langs->trans('Cancel').'</button>';
		} else {
			$h .= '<br><span class="opacitymedium">'.$langs->trans('TpvVendorMandatory').'</span>';
		}
		$h .= '</div></div>';

		$autoopen = ($obligatoire && $cur === 0 && !empty($vendors)) ? 'document.getElementById("tpvModal").style.display="flex";' : '';

		$invid = (int) $object->id;
		$h .= '<script>
		function tpvSet(id){
			var iid = '.$invid.';
			if (typeof $ === "function" && $("#invoiceid").length && parseInt($("#invoiceid").val())>0) { iid = $("#invoiceid").val(); }
			$.get("'.$ajaxurl.'", { invoiceid: iid, vendeur: id }, function(){
				document.getElementById("tpvModal").style.display="none";
				if (typeof Refresh === "function") { Refresh(); }
			});
		}
		function tpvColorRows(){
			var cells = document.querySelectorAll("td.tpvmarge[data-tpvcolor]");
			for (var i=0;i<cells.length;i++){
				var c = cells[i].getAttribute("data-tpvcolor");
				var trid = cells[i].getAttribute("data-tpvtr");
				var tr = trid ? document.getElementById(trid) : cells[i].parentNode;
				if (tr && c) {
					tr.style.setProperty("background-color", c, "important");
					var tds = tr.getElementsByTagName("td");
					for (var j=0;j<tds.length;j++){ tds[j].style.setProperty("background-color", c, "important"); }
				}
			}
		}
		tpvColorRows();
		setTimeout(tpvColorRows, 60);
		'.$autoopen.'
		</script>';

		$this->resprints = $h;
		return 0;
	}

	/**
	 * Chaque ligne (hook completeTakePosInvoiceLine, contexte takeposinvoice).
	 * Cellule vide (sous colonne Vendeur) + cellule Marge colorée, marquage pour coloration du fond.
	 */
	public function completeTakePosInvoiceLine($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$langs->load('takeposvendeur@takeposvendeur');

		$line = isset($parameters['line']) ? $parameters['line'] : null;
		if (!is_object($line)) { $this->resprints = ''; return 0; }

		// Toujours la cellule placeholder (aligne la colonne Vendeur de l'entête).
		$out = '<td class="tpvvendcol"></td>';

		if ($this->showMarge()) {
			$m   = $this->tpvLineMarge($line);
			$col = $this->tpvColor($m['pct'], $m['connu']);
			$trid = (int) $line->id;

			$out .= '<td class="right tpvmarge"';
			if ($col !== '') $out .= ' data-tpvcolor="'.$col.'"';
			$out .= ' data-tpvtr="'.$trid.'">';
			if ($m['connu']) {
				$out .= '<span style="color:'.$this->tpvTextColor($m['pct'], true).';font-weight:bold;white-space:nowrap;">'.$this->fmtPct($m['pct']).' %</span>';
				$out .= '<br><span class="opacitymedium small" style="white-space:nowrap;">'.price($m['marge']).'</span>';
			} else {
				$out .= '<span class="opacitymedium">-</span>';
			}
			$out .= '</td>';
		}

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Ticket (hook TakeposReceipt, contexte takeposfrontend) : imprime le vendeur.
	 */
	public function TakeposReceipt($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs;

		$langs->load('takeposvendeur@takeposvendeur');

		if (!$this->vendeurSurTicket()) return 0;
		if (!is_object($object) || empty($object->id)) return 0;

		$object->fetch_optionals();
		$v = !empty($object->array_options['options_fk_vendeur']) ? (int) $object->array_options['options_fk_vendeur'] : 0;
		if ($v > 0) {
			$res = $db->query("SELECT firstname, lastname, login FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".((int) $v));
			if ($res && ($o = $db->fetch_object($res))) {
				$nm = trim(($o->firstname ? $o->firstname.' ' : '').$o->lastname);
				if ($nm === '') $nm = $o->login;
				$this->resprints = '<div style="text-align:center;">'.$langs->trans('TpvVendor').' : '.dol_escape_htmltag($nm).'</div>';
			}
		}
		return 0;
	}
}
