<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Compatibility shim for a Dolibarr 24.0.x core running the develop AI module
 * (htdocs/ai overlay). It provides the core functions the module needs that
 * only exist on develop. Each one is a verbatim copy, guarded, so a core upgrade
 * that brings the real function wins and this file becomes inert.
 *
 * Loaded by filefunc.inc.php right after functions.lib.php.
 * Keep in sync with the overlay recipe (pechecenter-erpdev-deploy).
 */

// Since develop 4ba43a4e780 (#42552): one way to read the request headers under every SAPI.
if (!function_exists('dolGetRequestHeaders')) {
	/**
	 * Return the HTTP headers of the current request, whatever the SAPI.
	 *
	 * getallheaders() is only defined by some SAPI (Apache module, and FPM since PHP 7.3): it does
	 * not exist under CLI, nor under every FPM build, and calling it there is a fatal error. This
	 * rebuilds the same array from $_SERVER when it is missing, so callers can read a header without
	 * guarding every call, and so that reading one keeps working from a command line script.
	 *
	 * The keys are the header names as the client sent them, capitalised the way getallheaders()
	 * returns them: Authorization, Content-Type, X-Requested-With... Compare them without case
	 * (array_change_key_case) if the exact case matters to you.
	 *
	 * @return	array<string,string>	Header name => value
	 */
	function dolGetRequestHeaders()
	{
		if (function_exists('getallheaders')) {
			$headers = getallheaders();
			return is_array($headers) ? $headers : array();
		}

		$headers = array();
		foreach ($_SERVER as $key => $value) {
			if (!is_string($value) || strncmp($key, 'HTTP_', 5) !== 0) {
				continue;
			}
			// HTTP_X_REQUESTED_WITH becomes X-Requested-With
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
			$headers[$name] = $value;
		}
		// Those two are the only request headers $_SERVER does not prefix with HTTP_
		foreach (array('CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length') as $key => $name) {
			if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
				$headers[$name] = $_SERVER[$key];
			}
		}

		return $headers;
	}
}

// Since develop (#39876): the sales representative filter shared by the REST classes; the reception REST
// class backported from develop 594f06400cb calls it. Defined in company.lib.php from version 25, a file that
// is loaded on demand and does not guard its functions: hence the version test, so that this copy steps
// aside by itself on a 25 core instead of colliding with the real one.
if (!function_exists('getSalesRepresentativeSqlFilter') && (int) DOL_VERSION < 25) {
	/**
	 * Build the "EXISTS(...)" sub-query on llx_societe_commerciaux used to restrict a
	 * thirdparty-related list to the thirdparties handled by one or more sales representatives.
	 *
	 * The returned fragment has no leading " AND " / " OR " so the caller composes it, e.g.:
	 *   $sql .= " AND ".getSalesRepresentativeSqlFilter('s.rowid', $db->sanitize($search_sale));
	 *   $sql .= " AND ".getSalesRepresentativeSqlFilter('c.fk_soc', 0, 1);  // thirdparty assigned to nobody
	 *
	 * @param	string				$socidfield	SQL expression of the thirdparty rowid to test (e.g. 's.rowid', 'c.fk_soc'); passed through $db->sanitize()
	 * @param	int|string|int[]	$userids	Sales representative user id, an array of ids, or a comma-separated list of
	 *                                          ids. 0 / '' / empty array => only test that the thirdparty is assigned to
	 *                                          at least one sales representative.
	 * @param	int<0,1>			$not		1 to return "NOT EXISTS(...)" instead of "EXISTS(...)"
	 * @param	int<0,1>			$allownull	1 to also accept a null $socidfield (i.e. the record is not linked to any thirdparty)
	 * @return	string							SQL "EXISTS(...)" / "NOT EXISTS(...)" fragment
	 */
	function getSalesRepresentativeSqlFilter($socidfield, $userids = 0, $not = 0, $allownull = 0)
	{
		global $db;

		if (!is_array($userids)) {
			$userids = ($userids === '' || $userids === null) ? array() : explode(',', (string) $userids);
		}
		$userids = array_values(array_filter(array_map('intval', $userids), static function (int $v): bool {
			return $v > 0;
		}));

		$sql = "";
		if ($allownull) {
			$sql .= "(".$db->sanitize($socidfield)." IS NULL OR ";
		}
		$sql .= ($not ? 'NOT EXISTS' : 'EXISTS');
		// $socidfield is a column expression of the outer query (e.g. 's.rowid'); it is compared to sc.fk_soc
		$sql .= ' (SELECT sc.fk_soc FROM '.$db->prefix().'societe_commerciaux as sc WHERE '.$db->sanitize($socidfield).' = sc.fk_soc';
		if (!empty($userids)) {
			$sql .= ' AND sc.fk_user IN ('.$db->sanitize(implode(',', $userids)).')';
		}
		$sql .= ')';
		if ($allownull) {
			$sql .= ')';
		}

		return $sql;
	}
}
