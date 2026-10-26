<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com> - GPL v3+ */
/** Rendu des lignes de la liste du scanner - partage par capture.php et ajax/listrows.php */

function scEcartCell($qty, $sb)
{
	if ($sb === null) { return '<span class="opacitymedium">—</span>'; }
	$e = (float) $qty - (float) $sb;
	$c = ($e == 0 ? '#2e7d32' : ($e > 0 ? '#b26a00' : '#b71c1c'));
	return '<span style="color:'.$c.';font-weight:bold" class="sc_ecart">'.($e > 0 ? '+' : '').price2num($e).'</span>';
}

/**
 * Rend les <tr> d'un resultset de scans (memes colonnes que la requete de capture.php)
 */
function scRenderRows($db, $user, $resql)
{
	$out = '';
	if (!$resql) { return $out; }

	while ($o = $db->fetch_object($resql)) {
		$lblcell = dol_escape_htmltag((string) $o->product_label);
		if ($o->fk_product) {
			$lblcell = '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $o->fk_product).'" target="_blank" class="classforajaxtooltip" data-params=\'{"id":'.((int) $o->fk_product).',"objecttype":"product"}\'><span class="fa fa-cube paddingright" style="color:#4a6785"></span>'.$lblcell.'</a>';
		}
		$out .= '<tr class="oddeven" data-id="'.$o->rowid.'" data-st="'.dol_escape_htmltag($o->status).'" data-sent="'.($o->sent_to_inv ? 1 : 0).'" data-sb="'.($o->stock_before !== null ? price2num($o->stock_before) : '').'"><td class="sc_hidemobile">'.$o->rowid.'</td><td>'.dol_escape_htmltag((string) $o->code_kezia).'</td><td>'.dol_escape_htmltag((string) $o->ean).(($scEanPb = scEanProblem($o->ean)) !== '' ? ' <a href="#" class="sc_edit" data-row="'.$o->rowid.'" data-qty="'.price2num($o->qty).'" title="EAN '.dol_escape_htmltag($scEanPb).' : toucher pour corriger"><span class="fa fa-exclamation-triangle" style="color:#e65100"></span></a>' : '').'</td><td>'.$lblcell.($user->admin && !empty($o->scuser) ? ' <span class="sc_chip" style="font-size:0.78em;padding:1px 7px;">'.dol_escape_htmltag($o->scuser).'</span>' : '').'</td><td>'.dol_escape_htmltag($o->status).($o->sent_to_inv ? ' <span class="sc_pill sc_pill_sent" title="Envoy&eacute; dans l\'inventaire le '.dol_print_date($db->jdate($o->sent_to_inv), 'dayhour').'"><span class="fa fa-check-circle"></span> envoy&eacute;</span>' : ($o->fk_product ? ' <span class="sc_pill sc_pill_pend" title="Pas encore envoy&eacute; dans l\'inventaire"><span class="fa fa-clock-o"></span> &agrave; envoyer</span>' : '')).'</td><td class="right sc_hidemobile">'.($o->stock_before !== null ? price2num($o->stock_before) : '<span class="opacitymedium">—</span>').'</td><td class="right">'.scEcartCell($o->qty, $o->stock_before).'</td><td class="right">'.price2num($o->qty).'</td><td class="right nowrap">'.($o->sent_to_inv ? ((isset($o->inv_status) && (int) $o->inv_status != 1) ? '<span class="fa fa-edit sc_actdis" title="Inventaire clôturé"></span>&nbsp;<span class="fa fa-trash sc_actdis" title="Inventaire clôturé"></span>' : '<a href="#" class="sc_edit" title="Modifier la ligne" data-row="'.$o->rowid.'" data-qty="'.price2num($o->qty).'"><span class="fa fa-edit"></span></a>&nbsp;<a href="#" class="sc_del" title="Annuler la ligne" data-row="'.$o->rowid.'"><span class="fa fa-trash" style="color:#b71c1c"></span></a>') : ($o->status == 'unknown' ? '<a href="#" class="sc_create" data-row="'.$o->rowid.'" data-ean="'.dol_escape_htmltag((string) $o->ean).'" data-label="'.dol_escape_htmltag((string) $o->product_label).'"><span class="fa fa-plus-circle" style="color:#2e7d32"></span></a>&nbsp;' : '').(in_array($o->status, array('matched', 'created')) && $o->fk_product ? '<a href="#" class="sc_enrich" title="Enrichir le produit" data-row="'.$o->rowid.'" data-ean="'.dol_escape_htmltag((string) $o->ean).'" data-label="'.dol_escape_htmltag((string) $o->product_label).'"><span class="fa fa-magic" style="color:#7b1fa2"></span></a>&nbsp;' : '').(in_array($o->status, array('mismatch', 'ambiguous')) ? '<a href="#" class="sc_resolve" title="Résoudre : choisir le bon produit" data-row="'.$o->rowid.'" data-ck="'.dol_escape_htmltag((string) $o->code_kezia).'" data-ean="'.dol_escape_htmltag((string) $o->ean).'" data-qty="'.price2num($o->qty).'"><span class="fa fa-question-circle" style="color:#b71c1c"></span></a>&nbsp;' : '').'<a href="#" class="sc_edit" data-row="'.$o->rowid.'" data-qty="'.price2num($o->qty).'"><span class="fa fa-edit"></span></a>&nbsp;<a href="#" class="sc_del" data-row="'.$o->rowid.'"><span class="fa fa-trash" style="color:#b71c1c"></span></a>').'</td></tr>';
	}

	return $out;
}
