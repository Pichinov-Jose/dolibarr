<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Tabs of the setup pages of productenrichment
 *
 * @return array<array{0:string,1:string,2:string}> Tabs
 */
function productenrichmentAdminPrepareHead()
{
	global $langs;

	$langs->load('productenrichment@productenrichment');
	$langs->load('admin');

	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/productenrichment/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;
	$head[$h][0] = dol_buildpath('/productenrichment/admin/support.php', 1);
	$head[$h][1] = $langs->trans('Support');
	$head[$h][2] = 'support';
	$h++;
	$head[$h][0] = dol_buildpath('/productenrichment/admin/changelog.php', 1);
	$head[$h][1] = $langs->trans('PeChangelog');
	$head[$h][2] = 'changelog';
	$h++;

	return $head;
}
