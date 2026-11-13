<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    cardfields/class/actions_cardfields.class.php
 * \brief   Hooks of the module Champs par fiche (cardfields)
 *
 * - doActions: refuses to save a creation / modification form (or a field modified alone on the consultation
 *   page) when a field set as mandatory is empty, and displays the form again
 * - printCommonFooter: prints the script that hides and marks the fields of the card (printed by every page,
 *   whatever the hooks the card prints itself)
 */
class ActionsCardFields
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var array<string,mixed> Hook results
	 */
	public $results = array();

	/**
	 * @var string Hook output
	 */
	public $resprints;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Object key and definition of the card of the current hook context, or null when the page is not the card
	 * (some contexts are shared by the tabs of an object)
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @return ?array{0:string,1:array<string,mixed>} Object key and definition
	 */
	private function currentCard($parameters)
	{
		dol_include_once('/cardfields/lib/cardfields.lib.php');
		$objkey = cardfieldsObjectOfContext((string) (isset($parameters['currentcontext']) ? $parameters['currentcontext'] : ''));
		if ($objkey === '') {
			return null;
		}
		$objects = cardfieldsObjects();
		$def = $objects[$objkey];
		if (empty($def['enabled'])) {
			return null;
		}
		$self = (string) (isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');
		if (substr($self, -strlen($def['page'])) !== $def['page']) {
			return null;
		}
		return array($objkey, $def);
	}

	/**
	 * Check of the mandatory fields when a form is saved
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Current object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0 to go on, <0 to refuse
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$card = $this->currentCard($parameters);
		if ($card === null || empty($action)) {
			return 0;
		}
		list($objkey, $def) = $card;

		if ($objkey == 'reception' && $action == 'add') {
			$this->bridgeReceptionDimensions();
		}

		// Cancel buttons and the buttons that reload the form (customer code...) do not save
		if (GETPOST('cancel', 'alpha') || GETPOST('getcustomercode') || GETPOST('getsuppliercode')) {
			return 0;
		}

		$mode = '';
		if (in_array($action, $def['createsave'])) {
			$mode = 'create';
		} elseif (in_array($action, $def['editsave'])) {
			$mode = 'edit';
		}

		if ($mode !== '' && !cardfieldsModeDelegated($objkey, $mode)) {
			$catalog = cardfieldsFieldCatalog($objkey, (int) (is_object($object) ? $object->id : 0));
			$required = cardfieldsConfigured($objkey, $mode, 'required', $catalog);
			if (!count($required)) {
				return 0;
			}
			$present = (GETPOSTISSET('cardfields_present') ? array_filter(explode(',', GETPOST('cardfields_present', 'aZ09comma'))) : null);
			$missing = array();
			foreach ($required as $key) {
				$field = $catalog[$key];
				if (is_array($present) && !in_array($key, $present)) {
					continue;	// Not in this form (variant of the form, option of Dolibarr)
				}
				$names = null;
				if ($field['source'] == 'survey' && !empty($field['postbymode'][$mode])) {
					$names = $field['postbymode'][$mode];
				}
				if (!cardfieldsFieldFilled($field, $names)) {
					$missing[] = $field['label'];
				}
			}
			if (count($missing)) {
				$langs->load('cardfields@cardfields');
				$this->errors[] = $langs->trans('CfRequiredFieldsMissing', implode(', ', $missing));
				$action = ($mode == 'create' ? 'create' : 'edit');	// Display the form again, with the typed values
				return -1;
			}
			return 0;
		}

		// A field modified alone on the consultation page (pencil), or an extrafield (update_extras)
		if (strpos($action, 'set') !== 0 && $action != 'update_extras') {
			return 0;
		}
		$catalog = cardfieldsFieldCatalog($objkey, (int) (is_object($object) ? $object->id : 0));
		$required = cardfieldsConfigured($objkey, 'edit', 'required', $catalog);
		foreach ($required as $key) {
			$field = $catalog[$key];
			if (empty($field['inline'][$action])) {
				continue;
			}
			$inline = $field['inline'][$action];
			if (!empty($inline['attribute']) && GETPOST('attribute', 'aZ09') !== $inline['attribute']) {
				continue;
			}
			// Only when the form really posts the field
			$posted = false;
			foreach ($inline['names'] as $name) {
				if (GETPOSTISSET(rtrim($name, '[]'))) {
					$posted = true;
				}
			}
			if (!$posted) {
				continue;
			}
			if (!cardfieldsFieldFilled($field, $inline['names'])) {
				$langs->load('cardfields@cardfields');
				$this->errors[] = $langs->trans('CfRequiredFieldEmpty', $field['label']);
				$action = $inline['back'];	// Display the field in modification again
				return -1;
			}
		}

		return 0;
	}

	/**
	 * The creation form of a reception without order names its dimensions sizeW / sizeH / sizeS while the
	 * creation reads trueWidth / trueHeight / trueDepth (Dolibarr 24): the typed dimensions were lost.
	 * Give them under the names read by the creation.
	 *
	 * @return void
	 */
	private function bridgeReceptionDimensions()
	{
		$map = array('sizeW' => 'trueWidth', 'sizeH' => 'trueHeight', 'sizeS' => 'trueDepth');
		foreach ($map as $from => $to) {
			if (isset($_POST[$from]) && !isset($_POST[$to]) && trim((string) $_POST[$from]) !== '') {
				$_POST[$to] = $_POST[$from];
			}
		}
	}

	/**
	 * Script that hides and marks the fields of the card, and survey of the card
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param ?CommonObject $object Null (footer of the page): the object of the card is the global $object
	 * @param string $action Current action of the page
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs, $conf;

		if (empty($conf->use_javascript_ajax)) {
			return 0;
		}
		$card = $this->currentCard($parameters);
		if ($card === null) {
			return 0;
		}
		list($objkey, $def) = $card;
		$cardobject = (isset($GLOBALS['object']) && is_object($GLOBALS['object'])) ? $GLOBALS['object'] : null;
		$objectid = (int) ($cardobject && !empty($cardobject->id) ? $cardobject->id : 0);

		if ($action == 'create') {
			$mode = 'create';
		} elseif ($action == 'edit' && !empty($def['editform']) && $objectid > 0) {
			$mode = 'edit';
		} elseif ($objectid > 0) {
			$mode = 'view';
		} else {
			return 0;
		}
		if (cardfieldsModeDelegated($objkey, $mode)) {
			return 0;
		}

		$catalog = cardfieldsFieldCatalog($objkey, $objectid);
		$required = ($mode == 'view' ? array() : cardfieldsConfigured($objkey, $mode, 'required', $catalog));
		$hidden = cardfieldsConfigured($objkey, $mode, 'hidden', $catalog);
		// On a card with modification field by field, "mandatory in modification" is checked when saving only
		if ($mode == 'edit' && empty($def['editform'])) {
			$required = array();
		}
		$survey = ($user->admin && (GETPOST('cardfields_survey', 'int') || getDolGlobalString('CARDFIELDS_SURVEY')));
		if (!count($required) && !count($hidden) && !$survey) {
			return 0;
		}

		$fields = array();
		foreach (array_merge($required, $hidden) as $key) {
			$loc = (isset($catalog[$key]['modes'][$mode]) ? $catalog[$key]['modes'][$mode] : array());
			$fields[$key] = array(
				'names' => (isset($loc['names']) ? array_values($loc['names']) : array()),
				'sel' => (isset($loc['sel']) ? array_values($loc['sel']) : array()),
				'labels' => (isset($loc['labels']) ? array_values($loc['labels']) : array()),
			);
		}
		$cfg = array(
			'object' => $objkey,
			'mode' => $mode,
			'fields' => $fields,
			'required' => array_values($required),
			'hidden' => array_values($hidden),
			'ignoreCore' => array(),
			'hideEmptyBlocks' => (bool) getDolGlobalInt('CARDFIELDS_HIDE_EMPTY_BLOCKS', 1),
		);
		if ($survey) {
			$langs->load('cardfields@cardfields');
			$cfg['survey'] = array(
				'url' => dol_buildpath('/cardfields/ajax/survey.php', 1),
				'token' => currentToken(),
				'objkey' => $objkey,
				'done' => $langs->transnoentitiesnoconv('CfSurveyDone'),
				'failed' => $langs->transnoentitiesnoconv('CfSurveyFailed'),
			);
		}

		include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
		dol_include_once('/cardfields/core/modules/modCardFields.class.php');
		$version = class_exists('modCardFields') ? (new modCardFields($this->db))->version : '1';
		$version .= '-'.((int) @filemtime(dol_buildpath('/cardfields/js/cardfields.js')));	// A new script is loaded at once after an update

		$out = "\n<!-- cardfields: ".dol_escape_htmltag($objkey.' '.$mode)." -->\n";
		$out .= '<script src="'.dol_buildpath('/cardfields/js/cardfields.js', 1).'?v='.urlencode($version).'"></script>'."\n";
		$out .= '<script>jQuery(function() { if (window.CardFields) { window.CardFields.apply('.json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP).'); } });</script>'."\n";
		$this->resprints = $out;

		return 0;
	}
}
