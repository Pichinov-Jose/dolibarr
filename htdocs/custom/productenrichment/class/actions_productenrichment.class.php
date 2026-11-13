<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

dol_include_once('/productenrichment/class/productenrichment.class.php');

/**
 * Hooks of the module productenrichment:
 * - productcard: panel on the creation form (formObjectOptions) and button Enrich on the card (addMoreActionsButtons)
 * - productservicelist: mass action Enrich (addMoreMassActions, doPreMassActions, doActions)
 */
class ActionsProductEnrichment
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string[] */
	public $warnings = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Can the user enrich products
	 *
	 * @return bool
	 */
	private function canUse()
	{
		global $user;
		return isModEnabled('productenrichment') && $user->hasRight('produit', 'creer');
	}

	/**
	 * Texts of the panel
	 *
	 * @return array<string,string>
	 */
	private function jsTexts()
	{
		global $langs;
		$langs->load('productenrichment@productenrichment');
		$keys = array('NeedEan', 'Searching', 'SearchingAi', 'Error', 'Ai', 'Found', 'NotFound', 'NotFoundTryAi',
			'Step_ok', 'Step_cached', 'Step_notfound', 'Step_quota', 'Step_nokey', 'Step_error', 'Confidence', 'Apply', 'Fill',
			'Label', 'Description', 'ToBrandRef', 'ToSupplierRef', 'Mpn', 'ToBarcode', 'ToSupplierBarcode', 'Ean', 'SellPrice',
			'BuyPrice', 'Estimate', 'MarketPrice', 'ManufacturerPage', 'FillEmpty', 'FieldsFilled', 'ConfirmReplace', 'Field',
			'Current', 'Proposed', 'Why_filled', 'Why_none', 'Why_usedby', 'Why_notean', 'Why_nomodule', 'Photos', 'MaxPhotos',
			'Characteristics', 'NoCharacteristicsModule', 'NoFeatureAttribute', 'NoAttribute', 'Variants', 'VariantsInfo',
			'Sources', 'NothingSelected', 'Applying', 'Applied', 'NothingApplied', 'DialogTitle');
		$out = array();
		foreach ($keys as $k) {
			$out[$k] = $langs->transnoentitiesnoconv('PeJs_'.$k);
		}
		return $out;
	}

	/**
	 * Css, script and configuration of the panel
	 *
	 * @param string              $mode form or card
	 * @param array<string,mixed> $more More configuration
	 * @return string                   Html
	 */
	private function panelAssets($mode, $more = array())
	{
		$sources = ProductEnrichment::sourcesConfigured();
		$cfg = array_merge(array(
			'mode' => $mode,
			'urlEnrich' => dol_buildpath('/productenrichment/ajax/enrich.php', 1),
			'urlApply' => dol_buildpath('/productenrichment/ajax/apply.php', 1),
			'token' => currentToken(),
			'aiEnabled' => !empty($sources['ai']),
			'maxImages' => (int) getDolGlobalString('PRODUCTENRICHMENT_MAX_IMAGES', (string) ProductEnrichment::MAX_IMAGES),
			'i18n' => $this->jsTexts(),
		), $more);
		$out = '<style>
		#pe_panel { margin-top: 8px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; max-height: 70vh; overflow-y: auto; }
		#pe_panel .pe-head { margin-bottom: 6px; }
		#pe_panel .pe-title { font-weight: bold; margin: 10px 0 4px; }
		#pe_panel .pe-chip { display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; border: 1px solid #aaa; border-radius: 12px; cursor: pointer; }
		#pe_panel .pe-chip:hover { background: #eef; }
		#pe_panel .pe-desc { max-height: 6em; overflow: auto; white-space: pre-wrap; }
		#pe_panel .pe-value { word-break: break-word; }
		#pe_panel .pe-photos img { height: 90px; max-width: 140px; object-fit: contain; vertical-align: middle; margin: 2px; border: 1px solid #ddd; }
		#pe_panel .pe-photo { display: inline-block; margin-right: 6px; }
		#pe_panel .pe-current { font-weight: bold; }
		#pe_panel .pe-actions { margin-top: 8px; }
		#pe_panel .pe-done { opacity: .6; }
		#pe_status.pe-ok { color: #2a7a2a; } #pe_status.pe-error { color: #b00; } #pe_status.pe-warning { color: #a66b00; } #pe_status.pe-wait { color: #555; }
		</style>';
		$out .= '<script src="'.dol_buildpath('/productenrichment/js/productenrichment.js', 1).'?v='.urlencode(ProductEnrichment::VERSION).'"></script>';
		$out .= '<script>ProductEnrichment.init('.json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');</script>';
		return $out;
	}

	/**
	 * Panel of the creation form, just under the label
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Product
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                            0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', (string) $parameters['currentcontext']);
		if (!in_array('productcard', $contexts) || $action != 'create' || !$this->canUse()) {
			return 0;
		}
		$langs->load('productenrichment@productenrichment');
		$sources = ProductEnrichment::sourcesConfigured();

		$ean = trim((string) (GETPOST('barcode', 'alphanohtml') ?: GETPOST('fourn_barcode', 'alphanohtml')));
		$out = '<tr id="pe_row" class="pe-row"><td class="titlefieldcreate">'.img_picto('', 'fa-magic', 'class="pictofixedwidth"').$langs->trans('PeEnrichment').'</td><td>';
		$out .= '<input type="text" id="pe_ean" class="minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('PeEanPlaceholder')).'" value="'.dol_escape_htmltag($ean).'" autocomplete="off"> ';
		$out .= '<a href="#" id="pe_go" class="button smallpaddingimp">'.img_picto('', 'fa-search', 'class="pictofixedwidth"').$langs->trans('PeEnrich').'</a> ';
		if (!empty($sources['ai'])) {
			$out .= '<a href="#" id="pe_ai" class="button smallpaddingimp" style="display:none" title="'.dol_escape_htmltag($langs->trans('PeAiTooltip')).'">'.img_picto('', 'fa-robot', 'class="pictofixedwidth"').$langs->trans('PeSearchAi').'</a> ';
		}
		$out .= '<span id="pe_status" class="small"></span>';
		$out .= '<div id="pe_panel" style="display:none"></div><span id="pe_hidden"></span>';
		$out .= $this->panelAssets('form');
		$out .= '</td></tr>';

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Button Enrich on the product card, with the dialog of the panel
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Product
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                            0
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', (string) $parameters['currentcontext']);
		// product/card.php sets the action to 'view' when there is none
		if (!in_array('productcard', $contexts) || !in_array((string) $action, array('', 'view')) || empty($object->id) || !$this->canUse()) {
			return 0;
		}
		$langs->load('productenrichment@productenrichment');
		$pe = new ProductEnrichment($this->db);
		$sources = ProductEnrichment::sourcesConfigured();
		$ean = $pe->productEan($object);

		// The product card does not print the output of this hook: print it here
		print '<a class="butAction" href="#" id="pe_open">'.img_picto('', 'fa-magic', 'class="pictofixedwidth"').$langs->trans('PeEnrich').'</a>';
		print '<div id="pe_dialog" style="display:none">';
		print '<div class="pe-bar"><input type="text" id="pe_ean" class="minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('PeEanPlaceholder')).'" value="'.dol_escape_htmltag($ean).'" autocomplete="off"> ';
		print '<a href="#" id="pe_go" class="button smallpaddingimp">'.img_picto('', 'fa-search', 'class="pictofixedwidth"').$langs->trans('PeEnrich').'</a> ';
		if (!empty($sources['ai'])) {
			print '<a href="#" id="pe_ai" class="button smallpaddingimp" style="display:none" title="'.dol_escape_htmltag($langs->trans('PeAiTooltip')).'">'.img_picto('', 'fa-robot', 'class="pictofixedwidth"').$langs->trans('PeSearchAi').'</a> ';
		}
		print '<span id="pe_status" class="small"></span></div>';
		print '<div class="opacitymedium small">'.$langs->trans('PeCardOnlyEmpty').'</div>';
		print '<div id="pe_panel" style="display:none"></div>';
		print '</div>';
		print $this->panelAssets('card', array('fkProduct' => (int) $object->id));

		return 0;
	}

	/**
	 * Mass action of the product list
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @return int                            0
	 */
	public function addMoreMassActions($parameters)
	{
		global $langs;

		if (($parameters['currentcontext'] ?? '') != 'productservicelist' || !$this->canUse()) {
			return 0;
		}
		$langs->load('productenrichment@productenrichment');
		$label = img_picto('', 'fa-magic', 'class="pictofixedwidth"').$langs->trans('PeMassAction');
		$this->resprints = '<option value="peenrich" data-html="'.dol_escape_htmltag($label).'">'.$langs->trans('PeMassAction').'</option>';
		return 0;
	}

	/**
	 * Confirmation of the mass action, with the option of the AI search
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Object
	 * @param string              $action     Action
	 * @return int                            0
	 */
	public function doPreMassActions($parameters, &$object, &$action)
	{
		global $langs, $form;

		if (($parameters['currentcontext'] ?? '') != 'productservicelist' || ($parameters['massaction'] ?? '') != 'peenrich' || !$this->canUse()) {
			return 0;
		}
		$langs->load('productenrichment@productenrichment');
		$ids = array_filter(array_map('intval', (array) ($parameters['toselect'] ?? array())));
		if (!count($ids)) {
			setEventMessages($langs->trans('PeNothingSelected'), null, 'warnings');
			return 0;
		}
		if (!is_object($form)) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
			$form = new Form($this->db);
		}
		$sources = ProductEnrichment::sourcesConfigured();
		$formquestion = array(
			array('type' => 'hidden', 'name' => 'pe_ids', 'value' => implode(',', $ids)),
			array('type' => 'other', 'name' => 'pe_info', 'label' => '', 'value' => '<span class="opacitymedium">'.$langs->trans('PeMassInfo').'</span>'),
		);
		if (!empty($sources['ai'])) {
			$formquestion[] = array('type' => 'checkbox', 'name' => 'pe_ai', 'label' => $langs->trans('PeMassWithAi'), 'value' => 0);
		}
		$this->resprints = $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('PeMassAction'), $langs->trans('PeMassQuestion', count($ids)), 'peconfirmenrich', $formquestion, 1, 0, 260, 600, 1);
		return 0;
	}

	/**
	 * Mass action confirmed: the products go into the queue
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Object
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                            0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (($parameters['currentcontext'] ?? '') != 'productservicelist' || $action != 'peconfirmenrich' || GETPOST('confirm', 'alpha') != 'yes' || !$this->canUse()) {
			return 0;
		}
		$langs->load('productenrichment@productenrichment');
		$ids = array_filter(array_map('intval', explode(',', GETPOST('pe_ids', 'alphanohtml'))));
		if (!count($ids)) {
			$ids = array_filter(array_map('intval', (array) GETPOST('toselect', 'array:int')));
		}
		if (!count($ids)) {
			setEventMessages($langs->trans('PeNothingSelected'), null, 'warnings');
			return 0;
		}
		$ai = in_array(GETPOST('pe_ai', 'alpha'), array('1', 'on', 'true'), true);
		$pe = new ProductEnrichment($this->db);
		$n = $pe->enqueue($user, $ids, array('ai' => $ai));
		setEventMessages($langs->trans('PeQueued', $n, count($ids) - $n).' <a href="'.dol_buildpath('/productenrichment/queue.php', 1).'">'.$langs->trans('PeQueueLink').'</a>', null, 'mesgs');
		$action = 'list';
		return 0;
	}
}
