<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+
 */

/**
 * \file    lib/advancedstockmove.lib.php
 * \ingroup advancedstockmove
 * \brief   Fonctions partagees du module.
 */

/**
 * Onglets de la page d'administration.
 *
 * @return array<int,array{0:string,1:string,2:string}>
 */
function advancedstockmoveAdminPrepareHead()
{
	global $langs;
	$langs->load('advancedstockmove@advancedstockmove');

	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/advancedstockmove/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';

	return $head;
}
