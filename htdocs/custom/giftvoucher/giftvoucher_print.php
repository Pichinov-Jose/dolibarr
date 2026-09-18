<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+ — see modGiftvoucher.class.php
 */

/**
 * \file    giftvoucher_print.php
 * \ingroup giftvoucher
 * \brief   Impression du bon au format ticket 80 mm (imprimante caisse ou navigateur).
 */
$res = 0;
if (file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
dol_include_once('/giftvoucher/class/giftvoucher.class.php');

$langs->loadLangs(array('giftvoucher@giftvoucher', 'companies'));

if (!isModEnabled('giftvoucher') || !$user->hasRight('giftvoucher', 'read')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$object = new GiftVoucher($db);
if ($object->fetch($id) <= 0) {
	recordNotFound('', 0);
}

// Page autonome format ticket, pas de thème Dolibarr
top_httphead('text/html');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?php echo dol_escape_htmltag($langs->trans('GiftVoucher').' '.$object->ref); ?></title>
<style>
body { font-family: "Courier New", monospace; width: 72mm; margin: 0 auto; padding: 4mm 0; color: #000; }
.center { text-align: center; }
.big { font-size: 1.5em; font-weight: bold; }
.huge { font-size: 2em; font-weight: bold; }
.sep { border-top: 1px dashed #000; margin: 3mm 0; }
img.bc { max-width: 100%; }
@media print { .noprint { display: none; } }
</style>
</head>
<body onload="window.print();">
<div class="center">
	<div class="big"><?php echo dol_escape_htmltag($mysoc->name); ?></div>
	<?php if ($mysoc->address) { echo dol_escape_htmltag($mysoc->address).'<br>'; } ?>
	<?php echo dol_escape_htmltag(trim($mysoc->zip.' '.$mysoc->town)); ?><br>
	<?php if ($mysoc->phone) { echo 'Tel: '.dol_escape_htmltag($mysoc->phone); } ?>
</div>
<div class="sep"></div>
<div class="center big"><?php echo $langs->trans($object->type_voucher == GiftVoucher::TYPE_CREDIT ? 'GiftVoucherTypeCredit' : 'GiftVoucherTypeGift'); ?></div>
<div class="center huge"><?php echo price($object->amount, 0, $langs, 1, -1, -1, $conf->currency); ?></div>
<div class="center">
	<img class="bc" src="<?php echo DOL_URL_ROOT; ?>/viewimage.php?modulepart=barcode&generator=tcpdfbarcode&encoding=C128&readable=1&code=<?php echo urlencode($object->ref); ?>" alt="<?php echo dol_escape_htmltag($object->ref); ?>">
</div>
<div class="sep"></div>
<div>
<?php echo $langs->trans('GiftVoucherDateEmission'); ?> : <?php echo dol_print_date($object->date_emission, 'day'); ?><br>
<?php echo $langs->trans('GiftVoucherDateValidite'); ?> : <b><?php echo dol_print_date($object->date_validite, 'day'); ?></b><br>
<?php if ($object->note_public) { echo 'Nota : '.dol_escape_htmltag($object->note_public).'<br>'; } ?>
</div>
<div class="sep"></div>
<div class="center">Merci de votre visite<br>A bientôt<?php if ($mysoc->idprof2) { echo '<br>SIRET : '.dol_escape_htmltag($mysoc->idprof2); } ?></div>
<div class="center noprint" style="margin-top:6mm;">
	<button onclick="window.print();">Imprimer</button>
	<button onclick="window.close();">Fermer</button>
</div>
</body>
</html>
<?php
$db->close();
