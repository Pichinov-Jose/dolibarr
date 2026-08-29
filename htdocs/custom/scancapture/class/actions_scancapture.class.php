<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */

/**
 * Hooks for scancapture (context inventorycard)
 */
class ActionsScanCapture
{
	/**
	 * @var string Hook output
	 */
	public $resprints = '';

	/**
	 * Add a "Scan" button on the inventory card linking to the capture screen.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Inventory
	 * @param string $action Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', (string) $parameters['currentcontext']);
		if (!in_array('inventorycard', $contexts)) {
			return 0;
		}
		if (!$user->admin && !$user->hasRight('stock', 'creer')) {
			return 0;
		}
		$langs->load('scancapture@scancapture');
		if (!empty($object->id) && $object->status == 1) {
			$this->resprints = '<a class="butAction" href="'.dol_buildpath('/scancapture/capture.php', 1).'?fk_inventory='.((int) $object->id).'">'.$langs->trans('ScanCaptureMenu').'</a>';
		}
		return 0;
	}
}
