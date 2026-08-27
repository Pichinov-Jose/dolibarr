<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/takeposvendeurupdater.class.php
 * \ingroup takeposvendeur
 * \brief   Vérification de version (autonome, sans licence) via l'API GitHub.
 *
 * Compare la version installée (lue dans le descripteur) au dernier tag/release
 * du dépôt GitHub Pichinov. N'installe rien : le module est déployé par git/rsync ;
 * l'updater se contente de signaler qu'une version plus récente existe et de
 * pointer vers le dépôt. Aucune clé, aucun serveur de licence.
 *
 * Dépôt configurable via la constante TAKEPOSVENDEUR_GITHUB_REPO (défaut
 * "Pichinov/takeposvendeur").
 */
class TakeposvendeurUpdater
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	const MODULE = 'takeposvendeur';

	/**
	 * @param DoliDB $db Handler base
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/** Dépôt GitHub "owner/repo". */
	public static function repo()
	{
		return getDolGlobalString('TAKEPOSVENDEUR_GITHUB_REPO', 'Pichinov/takeposvendeur');
	}

	/** URL du dépôt. */
	public static function repoUrl()
	{
		return 'https://github.com/'.self::repo();
	}

	/**
	 * Version installée, lue dans le descripteur (source), pour éviter la valeur
	 * en cache d'une classe déjà chargée.
	 *
	 * @return string version ou ''
	 */
	public function getInstalledVersion()
	{
		$file = DOL_DOCUMENT_ROOT.'/custom/'.self::MODULE.'/core/modules/modTakeposvendeur.class.php';
		$content = @file_get_contents($file);
		if ($content === false) {
			return '';
		}
		if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Interroge l'API GitHub pour la dernière version publiée (release, sinon tag).
	 *
	 * @return array|int  array(latest, installed, has_update, url) ou <0 si KO
	 */
	public function checkForUpdate()
	{
		$this->error = '';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$installed = $this->getInstalledVersion();
		$latest = '';

		// 1) dernière release
		$api = 'https://api.github.com/repos/'.self::repo().'/releases/latest';
		$res = getURLContent($api, 'GET', '', 1, array('Accept: application/vnd.github+json', 'User-Agent: takeposvendeur-updater'), array('https'), 2, -1, 5, 15);
		$code = empty($res['http_code']) ? 0 : (int) $res['http_code'];
		if ($code == 200 && !empty($res['content'])) {
			$j = json_decode($res['content'], true);
			if (is_array($j) && !empty($j['tag_name'])) {
				$latest = ltrim($j['tag_name'], 'vV');
			}
		}

		// 2) repli : dernier tag
		if ($latest === '') {
			$api = 'https://api.github.com/repos/'.self::repo().'/tags?per_page=1';
			$res = getURLContent($api, 'GET', '', 1, array('Accept: application/vnd.github+json', 'User-Agent: takeposvendeur-updater'), array('https'), 2, -1, 5, 15);
			$code = empty($res['http_code']) ? 0 : (int) $res['http_code'];
			if ($code == 200 && !empty($res['content'])) {
				$j = json_decode($res['content'], true);
				if (is_array($j) && !empty($j[0]['name'])) {
					$latest = ltrim($j[0]['name'], 'vV');
				}
			}
		}

		if ($latest === '') {
			$this->error = ($code == 0) ? 'TpvUpdErrUnreachable' : 'TpvUpdErrNoRelease';
			return -1;
		}

		return array(
			'installed'  => $installed,
			'latest'     => $latest,
			'has_update' => ($installed !== '' && version_compare($latest, $installed, '>')),
			'url'        => self::repoUrl(),
		);
	}
}
