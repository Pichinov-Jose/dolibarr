<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Process the oldest product of the queue (called again and again by the queue page while it is not empty)
 */
require __DIR__.'/_common.inc.php';

$pe = new ProductEnrichment($db);
$r = $pe->processNext($user);
peJsonExit(array('ok' => true) + $r);
