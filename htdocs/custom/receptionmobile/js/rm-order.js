/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * Pichinov — Mobile : script propre à l'objet « commande client ». Chargé après js/receptionmobile.js (script commun) ;
 * utiliser window.PichinovMobile (small, text, titleText, page, labelLines, labelList, markCreateRow).
 * Un seul auteur par fichier : la session de cet objet.
 */
(function () {
	'use strict';

	function init() {
		var pm = window.PichinovMobile;
		var page = pm ? pm.page() : null;
		if (!page || page.key !== 'order') {
			return;
		}
	}

	// After the common script, which registers its own DOMContentLoaded listener first
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
