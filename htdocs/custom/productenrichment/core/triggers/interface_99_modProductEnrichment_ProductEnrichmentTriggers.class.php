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

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * PRODUCT_CREATE: apply what the creation form panel could not put in a field of the form,
 * the photos and the characteristics chosen (hidden fields pe_ean_used, pe_images[], pe_specs).
 * Only the photos found for this EAN (cache) are accepted. A failure never blocks the creation.
 */
class InterfaceProductEnrichmentTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'products';
		$this->description = 'Photos and characteristics chosen in the enrichment panel of the product creation form';
		$this->version = '1.2.5';
		$this->picto = 'product';
	}

	/**
	 * @param string       $action Trigger name
	 * @param CommonObject $object Object concerned
	 * @param User         $user   User
	 * @param Translate    $langs  Lang object
	 * @param Conf         $conf   Conf object
	 * @return int                 Greater than 0 on success, 0 if nothing done
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action != 'PRODUCT_CREATE' || !isModEnabled('productenrichment')) {
			return 0;
		}
		dol_include_once('/productenrichment/class/productenrichment.class.php');
		$ean = ProductEnrichment::normalizeEan((string) GETPOST('pe_ean_used', 'alphanohtml'));
		if ($ean === '') {
			return 0;
		}
		$pe = new ProductEnrichment($this->db);
		$info = $pe->getCached($ean);
		if (!$info) {
			return 0;
		}

		$applied = array();
		$errors = array();

		// Photos: only urls of the result of this EAN
		$posted = array_filter((array) GETPOST('pe_images', 'array:restricthtml'), 'is_string');
		$urls = array_values(array_intersect($posted, ProductEnrichment::infoImages($info)));
		if (count($urls)) {
			$n = $pe->downloadPhotos($object, $urls);
			if ($n) {
				$applied['images'] = $n;
			}
		}

		// Characteristics chosen in the panel
		$specs = json_decode((string) GETPOST('pe_specs', 'restricthtml'), true);
		if (is_array($specs) && count($specs) && ProductEnrichment::characteristicsEnabled()) {
			$n = 0;
			$done = array();
			foreach ($specs as $s) {
				$fk = (int) ($s['fk_attribute'] ?? 0);
				$value = trim(dol_string_nohtmltag((string) ($s['value'] ?? '')));
				if ($fk <= 0 || $value === '' || in_array($fk, $done)) {
					continue;
				}
				$r = $pe->addCharacteristic($user, $object->id, $fk, $value);
				if ($r > 0) {
					$n++;
					$done[] = $fk;
				} elseif ($r < 0) {
					$errors[] = $pe->error;
				}
			}
			if ($n) {
				$applied['characteristics'] = $n;
			}
		}
		if (count($errors)) {
			dol_syslog(__METHOD__.' '.implode(' ; ', $errors), LOG_WARNING);
		}
		$pe->journal($user, $object->id, $ean, 'form', (count($applied) ? 'done' : 'nothing'), array(), $applied, implode(' ; ', $errors));

		return 1;
	}
}
