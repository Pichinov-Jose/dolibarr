<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 * GPL v3+ — see modGiftvoucher.class.php
 */

/**
 * \file    lib/giftvoucher.lib.php
 * \ingroup giftvoucher
 * \brief   Fonctions support du module GiftVoucher.
 */

/**
 * Onglets de la page d'administration.
 *
 * @return array<array{0:string,1:string,2:string}>
 */
function giftvoucherAdminPrepareHead()
{
	global $langs;
	$langs->load('giftvoucher@giftvoucher');
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/giftvoucher/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	return $head;
}

/**
 * Le tiers d'un bon désigne-t-il vraiment son titulaire ?
 *
 * Non s'il n'y en a pas, ni s'il s'agit du client par défaut d'une caisse TakePOS
 * (CASHDESK_ID_THIRDPARTY<terminal>) : un bon vendu ou rendu en caisse sans client choisi y est
 * rattaché, il reste un bon au porteur.
 *
 * @param int $socid Tiers du bon (fk_soc)
 * @return bool
 */
function giftvoucherIsNominative($socid)
{
	global $conf;

	$socid = (int) $socid;
	if ($socid <= 0) {
		return false;
	}
	foreach ((array) $conf->global as $name => $value) {
		if (strpos((string) $name, 'CASHDESK_ID_THIRDPARTY') === 0 && (int) $value === $socid) {
			return false;
		}
	}
	return true;
}
