<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/takeposvendeursupport.class.php
 * \ingroup takeposvendeur
 * \brief   Support autonome (sans licence) : collecte de diagnostics + envoi d'un
 *          e-mail à l'assistance Pichinov via le mailer natif de Dolibarr.
 *
 * Adresse d'assistance configurable via TAKEPOSVENDEUR_SUPPORT_EMAIL
 * (défaut "support@pichinov.com").
 */
dol_include_once('/takeposvendeur/class/takeposvendeurupdater.class.php');

class TakeposvendeurSupport
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Handler base
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/** Adresse d'assistance. */
	public static function supportEmail()
	{
		return getDolGlobalString('TAKEPOSVENDEUR_SUPPORT_EMAIL', 'support@pichinov.com');
	}

	/**
	 * Collecte des faits d'environnement (aucune donnée métier).
	 *
	 * @return array
	 */
	public function collectDiagnostics()
	{
		global $conf, $mysoc;

		$updater = new TakeposvendeurUpdater($this->db);

		$diag = array();
		$diag['module_version']   = $updater->getInstalledVersion();
		$diag['github_repo']      = TakeposvendeurUpdater::repo();
		$diag['dolibarr_version'] = DOL_VERSION;
		$diag['php_version']      = phpversion();
		$diag['entity']           = $conf->entity;
		$diag['company']          = empty($mysoc->name) ? '' : $mysoc->name;
		$diag['dol_url_root']     = DOL_URL_ROOT;
		$diag['os']               = php_uname('s').' '.php_uname('r');
		return $diag;
	}

	/**
	 * Rend les diagnostics en texte lisible.
	 *
	 * @param  array  $diagnostics
	 * @return string
	 */
	public static function diagnosticsToText($diagnostics)
	{
		$out = '';
		foreach ($diagnostics as $k => $v) {
			$out .= str_pad($k, 20).': '.$v."\n";
		}
		return $out;
	}

	/**
	 * Envoie la demande d'assistance par e-mail.
	 *
	 * @param  string $type        ISSUE|REQUEST
	 * @param  string $severity    LOW|NORMAL|HIGH
	 * @param  string $subject     Sujet
	 * @param  string $message     Message
	 * @param  string $email       E-mail de réponse du demandeur
	 * @param  array  $diagnostics Diagnostics à joindre (peut être vide)
	 * @return int                 1 si OK, <0 si KO
	 */
	public function send($type, $severity, $subject, $message, $email, $diagnostics = array())
	{
		global $langs, $conf;

		$subject = trim($subject);
		$message = trim($message);
		if ($subject === '' || $message === '') {
			$this->error = 'TpvSupportMissing';
			return -1;
		}

		$to = self::supportEmail();
		$from = (!empty($email) ? $email : (getDolGlobalString('MAIN_MAIL_EMAIL_FROM') ?: $email));
		if (empty($from)) {
			$from = $to;
		}

		$fullsubject = '['.self::class.'] ['.$type.'/'.$severity.'] '.$subject;
		$body  = $message."\n\n";
		$body .= "-- Répondre à : ".($email ?: '(non fourni)')."\n";
		if (!empty($diagnostics)) {
			$body .= "\n----- Diagnostics -----\n".self::diagnosticsToText($diagnostics);
		}

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$mailfile = new CMailFile($fullsubject, $to, $from, $body, array(), array(), array(), '', '', 0, 0);
		$res = $mailfile->sendfile();
		if (!$res) {
			$this->error = $mailfile->error ? $mailfile->error : 'TpvSupportSendFailed';
			$this->errors = $mailfile->errors;
			return -1;
		}
		return 1;
	}
}
