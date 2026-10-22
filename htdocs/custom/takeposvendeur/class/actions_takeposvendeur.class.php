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
	/**
	 * Cascade ordonnée de sources de coût (priorité = ordre).
	 * Jetons : 'pmp', 'cost_price', 'supplier_min', 'ef:<nom_champ>' (extrafield produit).
	 * Défaut = ordre historique. Rétrocompat avec l'ancienne TAKEPOSVENDEUR_COST_BASIS.
	 * @return string[] liste ordonnée de jetons
	 */
	private function costSources()
	{
		$csv = getDolGlobalString('TAKEPOSVENDEUR_COST_SOURCES', '');
		if ($csv !== '') {
			$list = array();
			foreach (explode(',', $csv) as $s) {
				$s = trim($s);
				if ($s !== '') $list[] = $s;
			}
			if (!empty($list)) return $list;
		}
		// Rétrocompat : ancienne base unique
		$base = getDolGlobalString('TAKEPOSVENDEUR_COST_BASIS', '');
		if ($base == 'cost')     return array('cost_price', 'pmp', 'supplier_min');
		if ($base == 'supplier') return array('supplier_min', 'pmp', 'cost_price');
		return array('pmp', 'cost_price', 'supplier_min'); // ordre historique
	}

	/**
	 * Coût unitaire HT d'un produit : 1re source > 0 dans l'ordre configuré.
	 * @param  int   $fk_product
	 * @return float coût unitaire HT (0 si aucune source connue)
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

		$sources = $this->costSources();

		// Précharge produit (pmp/cost_price) si nécessaire
		$prow = null;
		$needProduct = false;
		$needEf = false;
		foreach ($sources as $s) {
			if ($s === 'pmp' || $s === 'cost_price') $needProduct = true;
			if (strncmp($s, 'ef:', 3) === 0) $needEf = true;
		}
		if ($needProduct) {
			$res = $db->query("SELECT pmp, cost_price FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".$fk_product);
			if ($res) $prow = $db->fetch_object($res);
		}
		// Précharge extrafields produit si une source ef: est demandée
		$efrow = null;
		if ($needEf) {
			$res = $db->query("SELECT * FROM ".MAIN_DB_PREFIX."product_extrafields WHERE fk_object = ".$fk_product);
			if ($res) $efrow = $db->fetch_object($res);
		}

		$cost = 0.0;
		foreach ($sources as $s) {
			$v = 0.0;
			if ($s === 'pmp') {
				$v = ($prow && !empty($prow->pmp)) ? (float) $prow->pmp : 0.0;
			} elseif ($s === 'cost_price') {
				$v = ($prow && !empty($prow->cost_price)) ? (float) $prow->cost_price : 0.0;
			} elseif ($s === 'supplier_min') {
				$r = $db->query("SELECT MIN(price) AS p FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".$fk_product." AND price > 0");
				if ($r && ($o = $db->fetch_object($r)) && !empty($o->p)) $v = (float) $o->p;
			} elseif (strncmp($s, 'ef:', 3) === 0) {
				$field = preg_replace('/[^a-zA-Z0-9_]/', '', substr($s, 3));
				if ($field !== '' && $efrow && isset($efrow->$field)) $v = (float) $efrow->$field;
			}
			if ($v > 0) { $cost = $v; break; }
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
		// Pas d'espace avant le signe : "33,3%" et non "33,3 %", la colonne est etroite.
		return price(price2num($pct, 2), 1, '', 1, -1, 1).'%';
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
	 * Bouton Vendeur (deplace a gauche, dans la cellule de description) + (option) colonne
	 * Marge globale + script de coloration.
	 */
	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $user, $conf;

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

		// Le bloc vendeur est rendu dans une cellule hote, puis deplace par JS dans la cellule de
		// description : la grande zone vide a gauche de l'entete. Cela libere une colonne entiere
		// dans la zone des montants, pour pouvoir y ajouter d'autres colonnes.
		$blk  = '<span id="tpvVendorBlock" class="tpvvendorblock" style="display:inline-block;white-space:nowrap;">';
		$blk .= '<span class="opacitymedium">'.$langs->trans('TpvVendor').'</span> ';
		$blk .= '<button type="button" id="tpvBtn" style="font-weight:bold;padding:5px 12px;border-radius:6px;cursor:pointer;" onclick="document.getElementById(\'tpvModal\').style.display=\'flex\';return false;">'.dol_escape_htmltag($curname).'</button>';
		$blk .= '</span>';

		$h  = '<td class="tpvvendhost" style="text-align:left;white-space:nowrap;">'.$blk;
		// La cellule de description est deja fermee quand ce hook s'execute : on deplace donc le bloc
		// cote navigateur. Si elle est introuvable (version telephone), le bloc reste dans sa cellule.
		$h .= '<script>(function(){var b=document.getElementById("tpvVendorBlock");if(!b)return;';
		$h .= 'var host=b.parentNode;var tr=host?host.parentNode:null;';
		$h .= 'var d=tr?tr.querySelector("td.linecoldescription"):null;';
		$h .= 'if(!d)return;';
		$h .= 'd.appendChild(b);host.style.display="none";})();</script>';
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

			$h .= '<td class="linecolqty center" style="white-space:nowrap;">';
			$h .= '<span class="opacitymedium small">'.$langs->trans('TpvMargin').'</span><br>';
			if ($g_connu) {
				$h .= '<span style="font-weight:bold;color:'.$this->tpvTextColor($g_pct, true).';">'.price($g_marge, 1, $langs, 1, -1, -1, $conf->currency).'</span>';
				$h .= '<br><span class="opacitymedium small">'.$this->fmtPct($g_pct).'</span>';
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
		/* La couleur de marge est posee en inline !important : elle bat toute feuille de style,
		   donc la surbrillance native de la ligne selectionnee (classe "selected", posee par le
		   coeur) devenait invisible. On repeint donc nous-memes la ligne selectionnee, en plus
		   fonce, avec une bordure bleue : reperable meme quand la marge est inconnue. */
		function tpvShade(c){
			var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(c || "");
			if (!m) { return null; }
			var r = Math.round(parseInt(m[1],16)*0.76), g = Math.round(parseInt(m[2],16)*0.76), b = Math.round(parseInt(m[3],16)*0.76);
			return "rgb("+r+","+g+","+b+")";
		}
		function tpvPaintRow(tr, c, sel){
			if (!tr) { return; }
			var tds = tr.getElementsByTagName("td");
			if (c) {
				var bg = sel ? (tpvShade(c) || c) : c;
				tr.style.setProperty("background-color", bg, "important");
				for (var j=0;j<tds.length;j++){ tds[j].style.setProperty("background-color", bg, "important"); }
			}
			for (var k=0;k<tds.length;k++){
				tds[k].style.setProperty("box-shadow", sel ? "inset 0 2px 0 #1565c0, inset 0 -2px 0 #1565c0" : "none", "important");
			}
			if (tds.length) {
				tds[0].style.setProperty("border-left", sel ? "5px solid #1565c0" : "none", "important");
			}
		}
		function tpvIsSel(tr){ return (" "+(tr.className||"")+" ").indexOf(" selected ") >= 0; }
		function tpvColorRows(){
			var done = {};
			var cells = document.querySelectorAll("td.tpvmarge[data-tpvcolor]");
			for (var i=0;i<cells.length;i++){
				var trid = cells[i].getAttribute("data-tpvtr");
				var tr = trid ? document.getElementById(trid) : cells[i].parentNode;
				if (!tr) { continue; }
				done[tr.id || ("_"+i)] = 1;
				tpvPaintRow(tr, cells[i].getAttribute("data-tpvcolor"), tpvIsSel(tr));
			}
			var tpvAl = document.querySelectorAll("td.tpvvendcol[data-tpvalert]");
			for (var a=0;a<tpvAl.length;a++){
				var trida = tpvAl[a].getAttribute("data-tpvtr");
				var tra = trida ? document.getElementById(trida) : tpvAl[a].parentNode;
				if (!tra) { continue; }
				done[tra.id || ("a"+a)] = 1;
				tpvPaintRow(tra, "#f5a9a9", tpvIsSel(tra));
			}
			/* Lignes sans couleur de marge (cout inconnu) : seul le repere de selection */
			var rows = document.querySelectorAll("tr.posinvoiceline");
			for (var n=0;n<rows.length;n++){
				if (!done[rows[n].id]) { tpvPaintRow(rows[n], "", tpvIsSel(rows[n])); }
			}
		}
		/* Le coeur pose la classe "selected" dans son propre gestionnaire de clic : on repasse
		   juste apres. Ecouteur pose une seule fois, il survit aux Refresh du panier. */
		if (!window.tpvSelWatch) {
			window.tpvSelWatch = 1;
			document.addEventListener("click", function(e){
				var t = e.target;
				while (t && t !== document) {
					if (t.className && (" "+t.className+" ").indexOf(" posinvoiceline ") >= 0) {
						setTimeout(function(){ if (typeof tpvColorRows === "function") { tpvColorRows(); } }, 0);
						return;
					}
					t = t.parentNode;
				}
			}, false);
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
	 * Cellule Marge colorée + marquage pour la coloration du fond de la ligne.
	 */
	public function completeTakePosInvoiceLine($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf;

		$langs->load('takeposvendeur@takeposvendeur');

		$line = isset($parameters['line']) ? $parameters['line'] : null;
		if (!is_object($line)) { $this->resprints = ''; return 0; }

		// Le bloc vendeur a quitte la zone des colonnes, mais sa cellule hote reste dans l'en-tete
		// (masquee en display:none : elle ne cree plus de colonne a l'ecran). On garde donc ici une
		// cellule masquee symetrique, SANS QUOI LES INDEX DE COLONNES SE DECALENT : la popup de
		// saisie d'advancedtakepos repere la colonne par son libelle dans l'en-tete, puis lit la
		// cellule de MEME INDEX dans la ligne (cellIdx/currentVal). Une ligne plus courte que
		// l'en-tete lui faisait lire la colonne voisine, d'ou une remise non reprise a l'ouverture.
		// Alerte "sous PA x coef" (TAKEPOSVENDEUR_ALERT_COEF, ex 1.5 ; vide = inactif) : la ligne
		// passe en rouge quand le net HT est sous cout x coef - meme quand la marge est masquee.
		$alertattr = '';
		$alertcoef = (float) price2num(getDolGlobalString('TAKEPOSVENDEUR_ALERT_COEF'), 'MU');
		if ($alertcoef > 0) {
			$ma = $this->tpvLineMarge($line);
			if ($ma['connu'] && $ma['pv_ht'] < $ma['cout'] * $alertcoef - 0.005) {
				$alertattr = ' data-tpvalert="1" data-tpvtr="'.(int) $line->id.'"';
			}
		}
		$out = '<td class="tpvvendcol" style="display:none;"'.$alertattr.'></td>';

		if ($this->showMarge()) {
			$m   = $this->tpvLineMarge($line);
			$col = $this->tpvColor($m['pct'], $m['connu']);
			$trid = (int) $line->id;

			$out .= '<td class="center tpvmarge"';
			if ($col !== '') $out .= ' data-tpvcolor="'.$col.'"';
			$out .= ' data-tpvtr="'.$trid.'">';
			if ($m['connu']) {
				// Meme ordre que le total de l'entete : le montant d'abord, le taux en dessous.
				$out .= '<span style="color:'.$this->tpvTextColor($m['pct'], true).';font-weight:bold;white-space:nowrap;">'.price($m['marge'], 1, $langs, 1, -1, -1, $conf->currency).'</span>';
				$out .= '<br><span class="opacitymedium small" style="white-space:nowrap;">'.$this->fmtPct($m['pct']).'</span>';
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
