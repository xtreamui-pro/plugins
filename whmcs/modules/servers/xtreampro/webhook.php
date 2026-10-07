<?php
/**
 * Xtream UI Pro - address the panel posts its webhooks to:
 * https://<your WHMCS>/modules/servers/xtreampro/webhook.php
 *
 * Register it with the button "Register panel webhook" on a service of this module.
 * Every call must carry a valid signature; an unsigned or wrongly signed one is refused.
 */

require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/xtreampro.php';

xtreampro_handleWebhook();
