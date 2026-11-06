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
