<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/takeposvendeur.lib.php
 * \ingroup takeposvendeur
 * \brief   Fonctions communes du module Takeposvendeur (entête des pages admin).
 */

/**
 * Prépare l'entête des pages admin (onglets) : Réglages, À propos, Support.
 *
 * @return array Tableau d'onglets
 */
function takeposvendeurAdminPrepareHead()
{
	global $langs, $conf;

	$langs->loadLangs(array('takeposvendeur@takeposvendeur'));

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/takeposvendeur/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/takeposvendeur/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	$head[$h][0] = dol_buildpath("/takeposvendeur/support.php", 1);
	$head[$h][1] = $langs->trans("TpvSupportTab");
	$head[$h][2] = 'support';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'takeposvendeur@takeposvendeur');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'takeposvendeur@takeposvendeur', 'remove');

	return $head;
}
