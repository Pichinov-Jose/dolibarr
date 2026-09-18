<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
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
