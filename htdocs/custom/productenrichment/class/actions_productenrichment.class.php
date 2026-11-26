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
			'Step_ok', 'Step_cached', 'Step_notfound', 'Step_quota', 'Step_nokey', 'Step_error', 'Step_mismatch', 'Confidence', 'Apply', 'Fill',
			'Label', 'Description', 'ToBrandRef', 'ToSupplierRef', 'Mpn', 'ToBarcode', 'ToSupplierBarcode', 'Ean', 'SellPrice',
			'BuyPrice', 'Estimate', 'MarketPrice', 'ManufacturerPage', 'FillEmpty', 'FieldsFilled', 'ConfirmReplace', 'Field',
			'Current', 'Proposed', 'Why_filled', 'Why_none', 'Why_usedby', 'Why_notean', 'Why_nomodule', 'Why_mismatch', 'Photos', 'MaxPhotos',
			'Characteristics', 'NoCharacteristicsModule', 'NoFeatureAttribute', 'NoAttribute', 'Variants', 'VariantsInfo',
			'Sources', 'NothingSelected', 'Applying', 'Applied', 'NothingApplied', 'DialogTitle', 'EbayKeyword');
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
		#pe_panel a.button.pe-done { background: #2a7a2a !important; border-color: #2a7a2a !important; color: #fff !important; }
		#pe_panel .pe-chip.pe-chosen { border-color: #2a7a2a; background: #e8f5e8; }
		#pe_panel .pe-chip.pe-chosen::after, #pe_panel a.pe-done::after { content: "\2713"; margin-left: 6px; }
		#pe_status.pe-ok { color: #2a7a2a; } #pe_status.pe-error { color: #b00; } #pe_status.pe-warning { color: #a66b00; } #pe_status.pe-wait { color: #555; }
		#pe_mbar { display: none; }
		@media only screen and (max-width: 600px) {
			#pe_mbar { display: flex; position: fixed; left: 0; right: 0; bottom: 0; z-index: 99; justify-content: space-around; background: #263238; padding: 4px 0 max(4px, env(safe-area-inset-bottom)); box-shadow: 0 -2px 8px rgba(0, 0, 0, .25); }
			#pe_mbar .pe-mbar-btn { flex: 1 1 0; min-width: 0; padding: 4px 2px; color: #b0bec5; font-size: .72em; line-height: 1.2; text-align: center; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; -webkit-tap-highlight-color: transparent; }
			#pe_mbar .pe-mbar-btn[hidden] { display: none; }
			#pe_mbar .pe-mbar-btn .fa { display: block; margin-bottom: 2px; font-size: 1.9em; }
			#pe_mbar .pe-mbar-btn.pe-mbar-primary { color: #fff; }
			#pe_mbar .pe-mbar-btn.pe-mbar-primary .fa { color: #4fc3f7; }
			#pe_mbar .pe-mbar-btn:active { color: #fff; }
			#pe_mbar .pe-mbar-btn.pe-mbar-off { opacity: .45; }
			body.pe-has-mbar { padding-bottom: 76px !important; }
			#pe_buttons { flex-basis: 100%; display: flex; flex-wrap: wrap; gap: 6px; white-space: normal; margin: 4px 0; }
			#pe_buttons > a.button { flex: 1 1 auto; display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin: 0 !important; font-size: 16px; box-sizing: border-box; }
			#pe_panel { max-height: none; overflow: visible; padding: 6px; }
			#pe_panel table.pe-table, #pe_panel table.pe-table tbody, #pe_panel table.pe-table tr, #pe_panel table.pe-table td { display: block; width: auto !important; height: auto !important; min-width: 0 !important; max-width: none !important; box-sizing: border-box; }
			#pe_panel table.pe-table tr { padding: 6px 2px; border-bottom: 1px solid #e0e0e0; }
			#pe_panel table.pe-table tr.liste_titre { display: none; }
			#pe_panel table.pe-table td { padding: 2px 0 !important; border: none !important; text-align: left !important; white-space: normal !important; }
			#pe_panel table.pe-table td:empty { display: none; }
			#pe_panel table.pe-table td:first-child { font-weight: bold; }
			#pe_panel table.pe-table td[data-label]::before { content: attr(data-label) " : "; font-weight: normal; opacity: .7; }
			#pe_panel .pe-value { word-break: normal; overflow-wrap: anywhere; }
			#pe_panel .pe-chip { display: block; max-width: 100%; box-sizing: border-box; margin: 6px 0; padding: 10px 12px; border-radius: 8px; font-size: 15px; white-space: normal; word-break: normal; overflow-wrap: anywhere; text-align: left; }
			#pe_panel .pe-chip.pe-chosen { font-weight: bold; }
			#pe_panel a.button, #pe_panel a.butAction { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin: 4px 6px 4px 0 !important; font-size: 16px; white-space: normal; box-sizing: border-box; }
			#pe_panel .pe-actions a.button, #pe_panel .pe-actions a.butAction { display: flex; width: 100%; margin: 4px 0 !important; }
			#pe_panel .pe-desc { max-height: 10em; }
			#pe_panel select.pe-spec-attr { width: 100%; min-height: 44px; font-size: 16px; }
			#pe_panel table.pe-table tr.pe-spec td:nth-child(3) { display: inline-block; width: calc(100% - 50px) !important; vertical-align: middle; }
			#pe_panel table.pe-table tr.pe-spec td:nth-child(4) { display: inline-block; width: 44px !important; text-align: center !important; vertical-align: middle; }
			#pe_panel input[type=checkbox] { width: 22px; height: 22px; vertical-align: middle; }
			#pe_panel .pe-photos img { height: 80px; max-width: 120px; }
			#pe_panel .pe-photo { margin: 4px 6px 4px 0; }
		}
		</style>';
		// Version and date of the file: a file changed without a new version is not kept by the cache of the terminals
		$jsfile = dol_buildpath('/productenrichment/js/productenrichment.js', 0);
		$out .= '<script src="'.dol_buildpath('/productenrichment/js/productenrichment.js', 1).'?v='.urlencode(ProductEnrichment::VERSION.'.'.(int) @filemtime($jsfile)).'"></script>';
		$out .= '<script>ProductEnrichment.init('.json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');</script>';
		return $out;
	}

	/**
	 * Panel of the creation form. The EAN comes first: the buttons go on the right of the barcode field of the
	 * product and the panel just under it; without a barcode field (module Barcode disabled) a row with its own
	 * EAN field goes under the label. An EAN given in the url (product created from a reception) is looked up
	 * as soon as the form opens.
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
		$buttons = '<span id="pe_buttons" class="nowraponall">';
		$buttons .= ' <a href="#" id="pe_go" class="button smallpaddingimp" title="'.dol_escape_htmltag($langs->trans('PeEnrichTooltip')).'">'.img_picto('', 'fa-magic', 'class="pictofixedwidth"').$langs->trans('PeEnrich').'</a>';
		if (!empty($sources['ai'])) {
			$buttons .= ' <a href="#" id="pe_ai" class="button smallpaddingimp" style="display:none" title="'.dol_escape_htmltag($langs->trans('PeAiTooltip')).'">'.img_picto('', 'fa-robot', 'class="pictofixedwidth"').$langs->trans('PeSearchAi').'</a>';
		}
		$buttons .= '</span>';

		$out = '<tr id="pe_row" class="pe-row"><td colspan="2">';
		// Used only when the form has no barcode field
		$out .= '<div id="pe_eanbox" style="display:none">'.img_picto('', 'fa-magic', 'class="pictofixedwidth"').$langs->trans('PeEnrichment').' : ';
		$out .= '<input type="text" id="pe_ean" class="minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('PeEanPlaceholder')).'" value="'.dol_escape_htmltag($ean).'" autocomplete="off"></div>';
		$out .= $buttons.' <span id="pe_status" class="small"></span>';
		$out .= '<div id="pe_panel" style="display:none"></div><span id="pe_hidden"></span>';
		// Bottom bar under 600 px (same look as receptionassist and scancapture): the actions stay at hand while
		// scrolling the form. Moved to the end of the body by the js, hidden on large screens by the css.
		$bar = array(
			array('ean', 'fa-barcode', $langs->trans('PeBarEan'), ''),
			array('go', 'fa-magic', $langs->trans('PeEnrich'), 'pe-mbar-primary'),
			array('ai', 'fa-robot', $langs->trans('PeBarAi'), ''),
			array('panel', 'fa-list', $langs->trans('PeBarPanel'), ''),
			array('fill', 'fa-edit', $langs->trans('PeBarFill'), ''),
			array('create', 'fa-check', $langs->trans('Create'), 'pe-mbar-primary'),
		);
		$out .= '<nav id="pe_mbar" class="pe-mbar" aria-label="'.dol_escape_htmltag($langs->trans('PeEnrichment')).'">';
		foreach ($bar as $b) {
			if ($b[0] == 'ai' && empty($sources['ai'])) {
				continue;
			}
			$out .= '<a href="#" class="pe-mbar-btn'.($b[3] ? ' '.$b[3] : '').'" data-pe="'.$b[0].'"'.(in_array($b[0], array('ai', 'panel', 'fill')) ? ' hidden' : '').'>';
			$out .= '<span class="fa '.$b[1].'" aria-hidden="true"></span>'.dol_escape_htmltag($b[2]).'</a>';
		}
		$out .= '</nav>';
		// Look up at once an EAN given in the url (product created from a reception), not when the form comes back after an error
		$autorun = ($ean !== '' && (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] == 'GET'));
		$out .= $this->panelAssets('form', array('autoRun' => $autorun));
		$out .= '</td></tr>';

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Reception card: when the EAN scanned in the product search of the line is unknown, offer to create the
	 * product with this EAN (creation popup with the barcode and the supplier EAN filled, the lookup starts
	 * by itself). The formConfirm hook is used because the reception card always prints its output.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Reception
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                            0
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', (string) $parameters['currentcontext']);
		if (!in_array('receptioncard', $contexts) || !empty($action) || empty($object->id) || (int) $object->status != 0 || !$this->canUse()) {
			return 0;
		}
		$langs->load('productenrichment@productenrichment');
		// Same url as the "New product" button of the line (the creation then comes back on the reception with the product)
		$url = DOL_URL_ROOT.'/product/card.php?action=create&type=0&fourn_socid='.((int) $object->socid);
		$url .= '&backtopage='.urlencode($_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&idprodfournprice=idprod___ID__');
		$url .= '&dol_hide_topmenu=1&dol_hide_leftmenu=1&dol_openinpopup=addproduct';
		$cfg = array(
			'url' => $url,
			'texts' => array(
				'create' => $langs->transnoentitiesnoconv('PeJs_CreateWithEan'),
				'title' => $langs->transnoentitiesnoconv('PeJs_NewProductFromEan'),
			),
		);
		$this->resprints = '<script src="'.dol_buildpath('/productenrichment/js/productenrichment.js', 1).'?v='.urlencode(ProductEnrichment::VERSION).'"></script>';
		$this->resprints .= '<script>ProductEnrichment.initReception('.json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');</script>';
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
		print $this->panelAssets('card', array('fkProduct' => (int) $object->id, 'autoOpen' => (GETPOSTINT('peopen') ? 1 : 0)));

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
