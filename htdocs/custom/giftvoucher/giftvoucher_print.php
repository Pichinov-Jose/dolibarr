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

// En-tête comme sur le ticket de caisse : logo de la société, coordonnées, identifiant légal.
$logourl = '';
if (!empty($mysoc->logo) && is_readable($conf->mycompany->dir_output.'/logos/'.$mysoc->logo)) {
	$logourl = DOL_URL_ROOT.'/viewimage.php?modulepart=mycompany&file='.urlencode('logos/'.$mysoc->logo);
}
$phone = trim((string) $mysoc->phone);
if ($mysoc->country_code == 'FR') {
	$phone = preg_replace('/^\+33\s*(\(0\))?\s*/', '0', $phone);
}
$web = preg_replace('/^https?:\/\//i', '', rtrim(trim((string) $mysoc->url), '/'));
$legalid = '';
if (!empty($mysoc->idprof2)) {
	$legalid = 'SIRET '.$mysoc->idprof2;
} elseif (!empty($mysoc->idprof1)) {
	$legalid = 'SIREN '.$mysoc->idprof1;
}

// QR code du site : celui du pied de ticket de caisse (réglage du module takeposdirectprint).
$qr = null;
if (isModEnabled('takeposdirectprint') && dol_include_once('/takeposdirectprint/lib/takeposdirectprint.lib.php') && function_exists('takeposdirectprintQr')) {
	$qr = takeposdirectprintQr();
}
$qrsrc = '';
if ($qr) {
	$quiet = 2;
	$size = count($qr['rows']) + 2 * $quiet;
	$path = '';
	foreach ($qr['rows'] as $y => $row) {
		for ($x = 0; $x < strlen($row); $x++) {
			if ($row[$x] === '1') {
				$path .= 'M'.($x + $quiet).' '.($y + $quiet).'h1v1h-1z';
			}
		}
	}
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'" shape-rendering="crispEdges">'
		.'<rect width="'.$size.'" height="'.$size.'" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
	$qrsrc = 'data:image/svg+xml;base64,'.base64_encode($svg);
}

// Page autonome format ticket, pas de thème Dolibarr
top_httphead('text/html');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?php echo dol_escape_htmltag($langs->trans('GiftVoucher').' '.$object->ref); ?></title>
<style>
body { font: 500 13px/1.35 Roboto, Arial, Helvetica, sans-serif; width: 72mm; margin: 0 auto; padding: 3mm 0; color: #000; }
.center { text-align: center; }
img.logo { display: block; width: 82%; margin: 0 auto 2mm; }
.shop { font-size: 12px; line-height: 1.3; }
.name { font-size: 15px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
.rule { border-top: 3px solid #000; margin: 3mm 0; }
.sep { border-top: 2px dashed #000; margin: 3mm 0; }
.type { font-size: 20px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; }
.amount { font-size: 34px; font-weight: 900; margin: 1mm 0 2mm; }
img.bc { width: 60mm; max-width: 100%; image-rendering: pixelated; }
.kv { display: flex; justify-content: space-between; }
.kv b { font-weight: 900; }
.qrbox { display: flex; align-items: center; margin-bottom: 3mm; }
.qrbox img.qr { flex: none; width: 25mm; height: 25mm; margin-right: 3mm; image-rendering: pixelated; }
.qrt .q1 { font-size: 15px; font-weight: 800; }
.qrt .q2 { font-size: 20px; font-weight: 900; }
.thanks { font-size: 17px; font-weight: 900; }
@media print { .noprint { display: none; } }
</style>
</head>
<body onload="window.print();">
<?php if ($logourl) { ?>
<img class="logo" src="<?php echo dol_escape_htmltag($logourl); ?>" alt="">
<?php } ?>
<div class="center shop">
	<div class="name"><?php echo dol_escape_htmltag($mysoc->name); ?></div>
	<?php if ($mysoc->address) { echo dol_escape_htmltag(str_replace(array("\r\n", "\n", "\r"), ', ', $mysoc->address)).'<br>'; } ?>
	<?php echo dol_escape_htmltag(trim($mysoc->zip.' '.$mysoc->town)); ?><br>
	<?php echo dol_escape_htmltag(implode(' · ', array_filter(array($phone, $web)))); ?>
	<?php if ($legalid) { echo '<br>'.dol_escape_htmltag($legalid); } ?>
</div>
<div class="rule"></div>
<div class="center type"><?php echo $langs->trans($object->type_voucher == GiftVoucher::TYPE_CREDIT ? 'GiftVoucherTypeCredit' : 'GiftVoucherTypeGift'); ?></div>
<div class="center amount"><?php echo price($object->amount, 0, $langs, 1, -1, -1, $conf->currency); ?></div>
<div class="center">
	<img class="bc" src="<?php echo DOL_URL_ROOT; ?>/viewimage.php?modulepart=barcode&generator=tcpdfbarcode&encoding=C128&readable=1&code=<?php echo urlencode($object->ref); ?>" alt="<?php echo dol_escape_htmltag($object->ref); ?>">
</div>
<div class="sep"></div>
<div class="kv"><span><?php echo $langs->trans('GiftVoucherDateEmission'); ?></span><span><?php echo dol_print_date($object->date_emission, 'day'); ?></span></div>
<div class="kv"><span><?php echo $langs->trans('GiftVoucherDateValidite'); ?></span><b><?php echo dol_print_date($object->date_validite, 'day'); ?></b></div>
<?php if ($object->note_public) { echo '<div>Nota : '.dol_escape_htmltag($object->note_public).'</div>'; } ?>
<div class="sep"></div>
<?php if ($qrsrc) { ?>
<div class="qrbox">
	<img class="qr" src="<?php echo $qrsrc; ?>" alt="">
	<div class="qrt">
	<?php
	foreach (explode("\n", (string) $qr['text']) as $i => $line) {
		echo '<div'.($i < 2 ? ' class="q'.($i + 1).'"' : '').'>'.dol_escape_htmltag($line).'</div>';
	}
	?>
	</div>
</div>
<?php } ?>
<div class="center thanks">Merci de votre visite !</div>
<div class="center">À bientôt</div>
<div class="center noprint" style="margin-top:6mm;">
	<button onclick="window.print();">Imprimer</button>
	<button onclick="window.close();">Fermer</button>
</div>
</body>
</html>
<?php
$db->close();
