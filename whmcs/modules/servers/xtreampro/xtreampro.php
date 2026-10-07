<?php
/**
 * Xtream UI Pro - WHMCS server provisioning module.
 *
 * Each WHMCS service is one IPTV line (or, with Service type "Sub-reseller
 * account", one sub-reseller account) created through the panel's Reseller
 * API. Create / renew are charged to the reseller's credits in the panel.
 *
 * Server setup in WHMCS: Hostname = API host, Password = the reseller API key.
 *
 * Changes of each release: CHANGELOG.md next to this file (1.0.0 was the first release).
 *
 * @version 1.1.1
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use XtreamPro\Whmcs\ApiException;
use XtreamPro\Whmcs\Client;
use XtreamPro\Whmcs\Webhook;

require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Webhook.php';

// ---------------------------------------------------------------------------
// Module definition
// ---------------------------------------------------------------------------

function xtreampro_MetaData()
{
    return array(
        'DisplayName'      => 'Xtream UI Pro',
        'APIVersion'       => '1.1',
        'RequiresServer'   => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort'   => '443',
    );
}

function xtreampro_ConfigOptions()
{
    return array(
        // configoption1
        'Package' => array(
            'Type'        => 'dropdown',
            'Loader'      => 'xtreampro_packageLoader',
            'SimpleMode'  => true,
            'Description' => 'Package of the line (loaded from the panel). Only applies to IPTV lines',
        ),
        // configoption2
        'Trial line' => array(
            'Type'        => 'yesno',
            'Description' => 'Create the line as a trial. Only applies to IPTV lines',
            'SimpleMode'  => true,
        ),
        // configoption3 (WHMCS stores a ticked yesno as "on"). Products made before 1.1.0
        // keep what they stored: a ticked box still deletes.
        'Delete on terminate' => array(
            'Type'        => 'yesno',
            'Description' => 'On cancellation, delete the line permanently. Off (the default) = only disable it. Deleting is final on the panel: the line and everything recorded about its customer are erased and cannot be restored',
            'SimpleMode'  => true,
        ),
        // configoption4
        'Service type' => array(
            'Type'        => 'dropdown',
            'Options'     => array(
                'line'     => 'IPTV line',
                'reseller' => 'Sub-reseller account',
            ),
            'Default'     => 'line',
            'Description' => 'What one service sells. Package, Trial line and Delete on terminate only apply to IPTV lines; the three options after Credits per renewal only to sub-reseller accounts',
            'SimpleMode'  => true,
        ),
        // configoption5
        'Credits on creation' => array(
            'Type'        => 'text',
            'Size'        => '8',
            'Default'     => '0',
            'Description' => 'Whole number, 0 or more: credits given to the new sub-reseller account. Only applies to sub-reseller accounts',
            'SimpleMode'  => true,
        ),
        // configoption6
        'Credits per renewal' => array(
            'Type'        => 'text',
            'Size'        => '8',
            'Default'     => '0',
            'Description' => 'Whole number, 0 or more: credits given at every WHMCS renewal (0 = renewals do nothing on the panel). Only applies to sub-reseller accounts',
            'SimpleMode'  => true,
        ),
        // configoption7
        'Sub-reseller on cancellation' => array(
            'Type'        => 'dropdown',
            'Options'     => array(
                'disable' => 'Disable the account (default)',
                'delete'  => 'Delete the account permanently',
            ),
            'Default'     => 'disable',
            'Description' => 'What termination does to a sub-reseller account. Deleting is final: the account\'s credits, lines and sub-accounts go to your reseller account, its personal data is erased and nothing can be restored. Your panel group must be allowed to delete accounts. Only applies to sub-reseller accounts',
            'SimpleMode'  => true,
        ),
        // configoption8
        'Panel address' => array(
            'Type'        => 'text',
            'Size'        => '30',
            'Description' => 'Optional, e.g. https://panel.example.com: where a sub-reseller signs in (address/login), shown in the client area. Only applies to sub-reseller accounts',
            'SimpleMode'  => true,
        ),
        // configoption9
        'Sub-reseller group' => array(
            'Type'        => 'dropdown',
            'Loader'      => 'xtreampro_groupLoader',
            'Description' => 'Group of a new sub-reseller account (loaded from the panel). The default is the first group your reseller group allows. Only applies to sub-reseller accounts',
            'SimpleMode'  => true,
        ),
    );
}

/**
 * Loader for the Package dropdown: returns id => label, only packages that can be sold as an IPTV line. WHMCS shows the
 * thrown message in the product setup when the panel cannot be reached.
 */
function xtreampro_packageLoader(array $params)
{
    $client = xtreampro_client($params);
    $options = array();
    foreach ($client->packages() as $pkg) {
        // Packages the panel says are for MAG / Enigma boxes only cannot be sold as an IPTV line.
        if (!is_array($pkg) || !isset($pkg['id']) || !Client::sellsLine($pkg)) {
            continue;
        }
        $name = isset($pkg['name']) ? (string) $pkg['name'] : ('#' . $pkg['id']);
        if (!empty($pkg['is_official'])) {
            $detail = (isset($pkg['official_credits']) ? $pkg['official_credits'] : '?') . ' credits, '
                . (isset($pkg['official_duration']) ? $pkg['official_duration'] : '?') . ' '
                . (isset($pkg['official_duration_in']) ? $pkg['official_duration_in'] : '');
        } else {
            $detail = 'trial only';
        }
        $options[$pkg['id']] = $name . ' (' . trim($detail) . ')';
    }
    return $options;
}

/**
 * Loader for the Sub-reseller group dropdown: the groups the panel lets this
 * reseller put a new account in ("pricing" -> sub_reseller.groups).
 */
function xtreampro_groupLoader(array $params)
{
    $options = array(0 => 'Default (first group the panel allows)');
    $pricing = xtreampro_client($params)->pricing();
    $groups = ($pricing !== null && isset($pricing['sub_reseller']['groups']) && is_array($pricing['sub_reseller']['groups']))
        ? $pricing['sub_reseller']['groups'] : array();
    foreach ($groups as $group) {
        if (is_array($group) && isset($group['id'])) {
            $options[(int) $group['id']] = isset($group['name']) ? (string) $group['name'] : ('#' . $group['id']);
        }
    }
    return $options;
}

function xtreampro_TestConnection(array $params)
{
    $client = null;
    try {
        $client = xtreampro_client($params);
        $info = $client->userInfo();
        $response = xtreampro_loggable($client);
        logModuleCall('xtreampro', __FUNCTION__, $client->lastRequest, $response, $info, xtreampro_secrets($params));
        return array('success' => true, 'error' => '');
    } catch (\Exception $e) {
        logModuleCall(
            'xtreampro',
            __FUNCTION__,
            $client ? $client->lastRequest : null,
            $client ? $client->lastResponse : null,
            $e->getMessage(),
            xtreampro_secrets($params)
        );
        return array('success' => false, 'error' => $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Provisioning actions - each returns "success" or an error string
// ---------------------------------------------------------------------------

function xtreampro_CreateAccount(array $params)
{
    if (xtreampro_isReseller($params)) {
        return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
            xtreampro_createSubReseller($client, $params);
        });
    }
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        $serviceId = (int) $params['serviceid'];
        $packageId = (int) $params['configoption1'];
        if ($packageId <= 0) {
            throw new RuntimeException('No package is selected in the product configuration.');
        }
        $trial = ($params['configoption2'] === 'on');

        // Fail before anything is sold when the credits cannot pay for it. A service
        // that already has its line is a replay: the panel answers it without a charge.
        if (xtreampro_storedLineId($serviceId) === 0) {
            $client->assertCanSellPackage($packageId, $trial);
        }

        // Blank values are generated by the panel. The reseller's group may also
        // ignore custom credentials, so the final ones are read back below.
        $data = $client->createLine(
            $packageId,
            $trial,
            isset($params['username']) ? $params['username'] : '',
            isset($params['password']) ? $params['password'] : '',
            // The generation changes after a termination, so that creating the
            // service again sells a new line instead of replaying the deleted one.
            'whmcs-create-' . $serviceId . '-' . xtreampro_generation($serviceId)
        );

        $line = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : array();
        if (!isset($line['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        $username = isset($line['username']) ? (string) $line['username'] : '';
        $password = isset($line['password']) ? (string) $line['password'] : '';
        if ($password === '' && isset($data['password'])) {
            $password = (string) $data['password'];
        }

        xtreampro_saveMapping($serviceId, (int) $line['id']);

        // Show the real credentials on the service. UpdateClientProduct encrypts
        // the password itself. A failure here must not fail an already paid line.
        $result = localAPI('UpdateClientProduct', array(
            'serviceid'       => $serviceId,
            'serviceusername' => $username,
            'servicepassword' => $password,
        ));
        if (!is_array($result) || (isset($result['result']) && $result['result'] !== 'success')) {
            logActivity('Xtream UI Pro: line ' . (int) $line['id'] . ' created but the service credentials could not be saved (service '
                . $serviceId . ')');
        }
    });
}

function xtreampro_SuspendAccount(array $params)
{
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        if (xtreampro_isReseller($params)) {
            $client->subUserAction('disable_user', xtreampro_requireUserId($client, $params));
            return;
        }
        $client->lineAction('disable_line', xtreampro_requireLineId($client, $params));
    });
}

function xtreampro_UnsuspendAccount(array $params)
{
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        if (xtreampro_isReseller($params)) {
            $client->subUserAction('enable_user', xtreampro_requireUserId($client, $params));
            return;
        }
        $client->lineAction('enable_line', xtreampro_requireLineId($client, $params));
    });
}

function xtreampro_TerminateAccount(array $params)
{
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        $serviceId = (int) $params['serviceid'];

        // A sub-reseller is disabled, or deleted for good when the product says so.
        if (xtreampro_isReseller($params)) {
            $userId = xtreampro_resolveUserId($client, $params);
            if ($userId !== null) {
                try {
                    $delete = isset($params['configoption7']) && $params['configoption7'] === 'delete';
                    $client->subUserAction($delete ? 'delete_user' : 'disable_user', $userId);
                } catch (ApiException $e) {
                    if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                        throw $e;
                    }
                }
            }
            xtreampro_closeMapping($serviceId);
            return;
        }

        $lineId = xtreampro_resolveLineId($client, $params);

        // A line that no longer exists in the panel is already terminated.
        if ($lineId !== null) {
            try {
                // Deleting is final on the panel; the default is to only disable the line.
                $delete = ($params['configoption3'] === 'on');
                $client->lineAction($delete ? 'delete_line' : 'disable_line', $lineId);
            } catch (ApiException $e) {
                if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                    throw $e;
                }
            }
        }
        xtreampro_closeMapping($serviceId);
    });
}

/**
 * Renewal (called by WHMCS when a renewal invoice is paid). Costs reseller
 * credits. The request id contains the next due date (or today) so a retry
 * on the same day returns the first result instead of charging twice.
 */
function xtreampro_Renew(array $params)
{
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        $serviceId = (int) $params['serviceid'];

        // Sub-reseller: a renewal tops up credits (0 = nothing to do).
        if (xtreampro_isReseller($params)) {
            $credits = xtreampro_credits($params['configoption6'], 'Credits per renewal');
            if ($credits > 0) {
                $userId = xtreampro_requireUserId($client, $params);
                $client->assertCanGiveCredits($credits);
                $client->adjustCredits(
                    $userId,
                    $credits,
                    'WHMCS renewal, service #' . $serviceId,
                    'whmcs-subr-' . $serviceId . '-' . xtreampro_dueStamp($serviceId)
                );
            }
            return;
        }

        $lineId = xtreampro_requireLineId($client, $params);
        $stamp = xtreampro_dueStamp($serviceId);

        // Renewing sells one official period of the line's package (not of the product's).
        $line = $client->getLine($lineId);
        if (is_array($line) && isset($line['package_id']) && (int) $line['package_id'] > 0) {
            $client->assertCanSellPackage((int) $line['package_id'], false, false);
        }
        $client->renewLine($lineId, 'whmcs-renew-' . $serviceId . '-' . $stamp);
    });
}

/**
 * Upgrade / downgrade (WHMCS calls it when an upgrade of the service is
 * processed). The line takes the package now selected in the product
 * configuration and the panel charges that package's official price.
 *
 * Assumption, not checked in a licensed WHMCS: at this point $params already
 * holds the configuration of the NEW product. A sub-reseller account has no
 * package, so there is nothing to change on the panel.
 */
function xtreampro_ChangePackage(array $params)
{
    if (xtreampro_isReseller($params)) {
        return 'success';
    }
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        $serviceId = (int) $params['serviceid'];
        $packageId = (int) $params['configoption1'];
        if ($packageId <= 0) {
            throw new RuntimeException('No package is selected in the product configuration.');
        }
        $lineId = xtreampro_requireLineId($client, $params);
        $client->assertCanSellPackage($packageId, false);
        // Ask the panel, without selling, whether the time left is kept and what the change costs
        // (null on a panel too old to know the question: the change is then decided when it is sold).
        $compat = $client->packageCompatibility($lineId, $packageId);
        if ($compat !== null && array_key_exists('can_afford', $compat) && empty($compat['can_afford'])) {
            throw new ApiException('INSUFFICIENT_CREDITS', 402, 'The change costs ' . (isset($compat['price']) ? (int) $compat['price'] : 0)
                . ' credits and the reseller account cannot pay it.');
        }
        // A retry of the same change repeats its request id and is not charged twice;
        // the counter moves on only after the panel accepted the change.
        $n = xtreampro_changeCount($serviceId);
        $client->changePackage($lineId, $packageId, 'whmcs-chg-' . $serviceId . '-' . xtreampro_generation($serviceId) . '-' . $n);
        Capsule::table('mod_xtreampro_lines')->where('service_id', $serviceId)->update(array('changes' => Capsule::raw('changes + 1')));
        // WHMCS has no screen in the upgrade flow for a module's remarks: the admin finds this in the activity log.
        logActivity('Xtream UI Pro: line ' . $lineId . ' of service ' . $serviceId . ' moved to package ' . $packageId . '. '
            . ($compat !== null ? Client::describeCompatibility($compat) : 'This panel does not say whether the time left was kept.'));
    });
}

// ---------------------------------------------------------------------------
// Webhooks pushed by the panel
// ---------------------------------------------------------------------------

/** Buttons on the admin's service page. */
function xtreampro_AdminCustomButtonArray()
{
    return array(
        'Register panel webhook' => 'registerWebhook',
        'Register panel webhook (with sub-resellers\' lines)' => 'registerWebhookSubs',
        'Send webhook test'      => 'testWebhook',
        'Remove panel webhook'   => 'removeWebhook',
    );
}

/** Public address the panel posts to. SystemURL is the WHMCS address in System Settings. */
function xtreampro_webhookUrl()
{
    $base = '';
    if (class_exists('\\WHMCS\\Config\\Setting')) {
        $base = (string) \WHMCS\Config\Setting::getValue('SystemURL');
    }
    if ($base === '' && isset($GLOBALS['CONFIG']['SystemURL'])) {
        $base = (string) $GLOBALS['CONFIG']['SystemURL'];
    }
    if ($base === '') {
        throw new RuntimeException('The System URL of WHMCS is not set (System Settings > General), so the webhook address cannot be built.');
    }
    return rtrim($base, '/') . '/modules/servers/xtreampro/webhook.php';
}

/**
 * Registers this WHMCS as a webhook endpoint of the server's reseller account and
 * keeps the signing secret, which the panel shows only once.
 */
function xtreampro_registerWebhook(array $params)
{
    return xtreampro_registerWebhookWith(__FUNCTION__, $params, false);
}

/**
 * Same, and the panel also sends the events of lines owned by the sub-reseller accounts below this
 * reseller (include_sub_resellers). Offered because this module provisions sub-reseller accounts;
 * such an event is only noted on the service of the account (see xtreampro_applyWebhookEvent).
 */
function xtreampro_registerWebhookSubs(array $params)
{
    return xtreampro_registerWebhookWith(__FUNCTION__, $params, true);
}

function xtreampro_registerWebhookWith($function, array $params, $includeSub)
{
    return xtreampro_run($function, $params, function (Client $client) use ($params, $includeSub) {
        $serverId = (int) $params['serverid'];
        xtreampro_ensureWebhookTable();
        if (Capsule::table('mod_xtreampro_webhooks')->where('server_id', $serverId)->exists()) {
            throw new RuntimeException('A webhook is already registered for this server. Remove it first to register again.');
        }
        $url = xtreampro_webhookUrl();
        try {
            $data = $client->createWebhook($url, 'line.renewed,line.enabled,line.disabled,line.deleted,line.expired', $includeSub);
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'INVALID_REQUEST') {
                throw new ApiException('INVALID_REQUEST', $e->getHttpStatus(), 'The address ' . $url
                    . ' was refused: the panel delivers to https addresses only (http only when the panel runs with WEBHOOK_ALLOW_PRIVATE).');
            }
            throw $e;
        }
        if (!is_array($data) || empty($data['id']) || empty($data['secret'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        Capsule::table('mod_xtreampro_webhooks')->insert(array(
            'server_id'  => $serverId,
            'webhook_id' => (string) $data['id'],
            'secret'     => xtreampro_seal((string) $data['secret']),
            'url'        => $url,
            'include_sub' => $includeSub ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    });
}

function xtreampro_testWebhook(array $params)
{
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        $id = xtreampro_webhookId($params);
        $client->testWebhook($id);
    });
}

function xtreampro_removeWebhook(array $params)
{
    return xtreampro_run(__FUNCTION__, $params, function (Client $client) use ($params) {
        $id = xtreampro_webhookId($params);
        try {
            $client->deleteWebhook($id);
        } catch (ApiException $e) {
            if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                throw $e;
            }
        }
        Capsule::table('mod_xtreampro_webhooks')->where('server_id', (int) $params['serverid'])->delete();
    });
}

function xtreampro_webhookId(array $params)
{
    xtreampro_ensureWebhookTable();
    $id = Capsule::table('mod_xtreampro_webhooks')->where('server_id', (int) $params['serverid'])->value('webhook_id');
    if (!$id) {
        throw new RuntimeException('No webhook is registered for this server yet.');
    }
    return (string) $id;
}

function xtreampro_ensureWebhookTable()
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_xtreampro_webhooks')) {
        $schema->create('mod_xtreampro_webhooks', function ($table) {
            $table->unsignedInteger('server_id')->primary();
            $table->string('webhook_id', 36);
            // Encrypted with WHMCS' own encryption (never plain text).
            $table->text('secret');
            $table->string('url', 500);
            // 1 when registered with include_sub_resellers.
            $table->boolean('include_sub')->default(false);
            $table->dateTime('created_at')->nullable();
        });
    } elseif (!$schema->hasColumn('mod_xtreampro_webhooks', 'include_sub')) {
        // Table of 1.1.0: add the column it lacks.
        $schema->table('mod_xtreampro_webhooks', function ($table) {
            $table->boolean('include_sub')->default(false);
        });
    }
}

/** How long a webhook event id is remembered: a retry comes within minutes, a replay within the 5-minute window. */
const XTREAMPRO_EVENT_RETENTION = 86400;

function xtreampro_ensureEventTable()
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_xtreampro_webhook_events')) {
        $schema->create('mod_xtreampro_webhook_events', function ($table) {
            // The id of the event ("evt_...") is the same on every retry of it.
            $table->string('event_id', 64)->primary();
            $table->dateTime('seen_at')->index();
        });
    }
}

/** Whether this event was applied before (the panel delivers at least once, so retries happen). */
function xtreampro_eventSeen($eventId)
{
    xtreampro_ensureEventTable();
    return Capsule::table('mod_xtreampro_webhook_events')->where('event_id', (string) $eventId)->exists();
}

/** Remember an applied event and drop the ones older than the retention, so the table stays small. */
function xtreampro_rememberEvent($eventId)
{
    xtreampro_ensureEventTable();
    Capsule::table('mod_xtreampro_webhook_events')->where('seen_at', '<', date('Y-m-d H:i:s', time() - XTREAMPRO_EVENT_RETENTION))->delete();
    try {
        Capsule::table('mod_xtreampro_webhook_events')->insert(array('event_id' => (string) $eventId, 'seen_at' => date('Y-m-d H:i:s')));
    } catch (\Exception $e) {
        // a delivery of the same event raced this one and stored the id first
    }
}

/** Encrypts / decrypts with WHMCS' key (the same call WHMCS uses for server passwords). */
function xtreampro_seal($plain)
{
    $r = localAPI('EncryptPassword', array('password2' => $plain));
    if (!is_array($r) || empty($r['password'])) {
        throw new RuntimeException('WHMCS could not encrypt the webhook secret, so the webhook was not saved.');
    }
    return (string) $r['password'];
}

function xtreampro_unseal($sealed)
{
    $r = localAPI('DecryptPassword', array('password2' => $sealed));
    return (is_array($r) && isset($r['password'])) ? (string) $r['password'] : '';
}

/** Secrets of every registered webhook (one per server record). */
function xtreampro_webhookSecrets()
{
    xtreampro_ensureWebhookTable();
    $secrets = array();
    foreach (Capsule::table('mod_xtreampro_webhooks')->get() as $row) {
        $plain = xtreampro_unseal((string) $row->secret);
        if ($plain !== '') {
            $secrets[] = $plain;
        }
    }
    return $secrets;
}

/**
 * Entry point of modules/servers/xtreampro/webhook.php. Nothing is read from
 * the body before its signature checked out against a stored secret.
 */
function xtreampro_handleWebhook()
{
    header('Content-Type: application/json');
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array('ok' => false, 'message' => 'POST only'));
        return;
    }
    $body = (string) file_get_contents('php://input', false, null, 0, Webhook::MAX_BODY + 1);
    $result = xtreampro_processWebhook(
        $body,
        isset($_SERVER['HTTP_X_XTREAM_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_XTREAM_TIMESTAMP'] : '',
        isset($_SERVER['HTTP_X_XTREAM_SIGNATURE']) ? (string) $_SERVER['HTTP_X_XTREAM_SIGNATURE'] : ''
    );
    http_response_code($result['status']);
    echo json_encode(array('ok' => $result['status'] === 200, 'message' => $result['message']));
}

/**
 * Verify one delivery, then apply it unless its event id was applied before.
 *
 * @return array {status:int, message:string}
 */
function xtreampro_processWebhook($body, $timestamp, $signature)
{
    $result = Webhook::receive($body, $timestamp, $signature, xtreampro_webhookSecrets());
    if ($result['status'] === 200) {
        try {
            // The same event id means the same event again (a retry after a lost answer): acknowledge, do nothing.
            // The id is stored only after the event was applied, so a failed one is applied on the retry.
            $eventId = Webhook::eventId($result['event']);
            if ($eventId !== '' && xtreampro_eventSeen($eventId)) {
                $result['message'] = 'duplicate';
            } else {
                $result['message'] = xtreampro_applyWebhookEvent($result['event']);
                if ($eventId !== '') {
                    xtreampro_rememberEvent($eventId);
                }
            }
        } catch (\Exception $e) {
            logActivity('Xtream UI Pro: webhook ' . $result['event']['type'] . ' could not be applied: ' . $e->getMessage());
            $result = array('status' => 500, 'message' => 'could not apply the event');
        }
    }
    return array('status' => $result['status'], 'message' => $result['message']);
}

/**
 * Applies one verified event to the services that hold the line. Idempotent:
 * the panel retries, and a replay inside the signature's time window repeats it.
 *
 * - line.deleted: the service is marked Terminated and forgets its line.
 * - line.expired: only recorded (admin tab and activity log). WHMCS has no "expired"
 *   status and its own due dates decide when a service is suspended, so the service
 *   is not suspended from here.
 * - line.renewed / enabled / disabled: the recorded panel status follows.
 * Events of lines this WHMCS does not know are acknowledged and ignored. The exception: an event
 * of a webhook registered with the sub-resellers' lines carries the panel id of the line's owner
 * (owner_id); when that is a sub-reseller account provisioned here, only the time of the event is
 * noted on its service (nothing about the line or its customer is kept).
 */
function xtreampro_applyWebhookEvent(array $event)
{
    $type = (string) $event['type'];
    $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
    if ($type === 'ping') {
        return 'pong';
    }
    if (strpos($type, 'line.') !== 0 || empty($data['line_id'])) {
        return 'ignored';
    }
    xtreampro_ensureTable();
    $lineId = (int) $data['line_id'];
    $serviceId = (int) Capsule::table('mod_xtreampro_lines')->where('line_id', $lineId)->value('service_id');
    if ($serviceId <= 0) {
        $ownerId = isset($data['owner_id']) && is_string($data['owner_id']) ? $data['owner_id'] : '';
        $accountService = $ownerId === '' ? 0 : (int) Capsule::table('mod_xtreampro_lines')->where('user_id', $ownerId)->value('service_id');
        if ($accountService > 0) {
            Capsule::table('mod_xtreampro_lines')->where('service_id', $accountService)->update(array('panel_event_at' => date('Y-m-d H:i:s')));
            return 'recorded (line of a sub-reseller account)';
        }
        return 'unknown line';
    }
    $stamp = array('panel_event_at' => date('Y-m-d H:i:s'));
    switch ($type) {
        case 'line.deleted':
            xtreampro_closeMapping($serviceId);
            $r = localAPI('UpdateClientProduct', array('serviceid' => $serviceId, 'status' => 'Terminated'));
            if (!is_array($r) || (isset($r['result']) && $r['result'] !== 'success')) {
                logActivity('Xtream UI Pro: line ' . $lineId . ' was deleted on the panel but service ' . $serviceId . ' could not be marked Terminated');
            } else {
                logActivity('Xtream UI Pro: line ' . $lineId . ' was deleted on the panel; service ' . $serviceId . ' marked Terminated');
            }
            return 'terminated';
        case 'line.expired':
            Capsule::table('mod_xtreampro_lines')->where('service_id', $serviceId)->update($stamp + array('panel_status' => 'expired'));
            logActivity('Xtream UI Pro: line ' . $lineId . ' expired on the panel (service ' . $serviceId . ')');
            return 'recorded';
        case 'line.renewed':
        case 'line.enabled':
            Capsule::table('mod_xtreampro_lines')->where('service_id', $serviceId)->update($stamp + array('panel_status' => 'active'));
            return 'recorded';
        case 'line.disabled':
            Capsule::table('mod_xtreampro_lines')->where('service_id', $serviceId)->update($stamp + array('panel_status' => 'disabled'));
            return 'recorded';
    }
    return 'ignored';
}

// ---------------------------------------------------------------------------
// Admin + client area
// ---------------------------------------------------------------------------

/**
 * Extra fields on the admin's service page. Failures are shown as text and
 * never break the page.
 */
function xtreampro_AdminServicesTabFields(array $params)
{
    $esc = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    try {
        $client = xtreampro_client($params);
        if (xtreampro_isReseller($params)) {
            $user = xtreampro_loadSubUser($client, $params);
            if ($user === null) {
                return array('Xtream UI Pro sub-reseller' => 'No sub-reseller account found for this service yet.');
            }
            $fields = array(
                'Account ID'     => $esc(isset($user['id']) ? $user['id'] : '-'),
                'Username'       => $esc(isset($user['username']) ? $user['username'] : '-'),
                'Account status' => $esc(isset($user['status']) ? $user['status'] : '-'),
                'Credit balance' => $esc(isset($user['credits']) ? $user['credits'] : '-'),
            );
            $event = Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $params['serviceid'])->value('panel_event_at');
            if ($event) {
                $fields['Last panel activity of the account\'s lines'] = $esc($event);
            }
            return $fields;
        }
        $lineId = xtreampro_resolveLineId($client, $params);
        if ($lineId === null) {
            return array('Xtream UI Pro line' => 'No line found for this service yet.');
        }
        $line = $client->getLine($lineId);
        $fields = array(
            'Line ID'         => $esc($lineId),
            'Line status'     => $esc(isset($line['status']) ? $line['status'] : '-'),
            'Line expiry'     => $esc(xtreampro_formatExpiry(isset($line['exp_date']) ? $line['exp_date'] : null)),
            'Max connections' => $esc(isset($line['max_connections']) ? $line['max_connections'] : '-'),
        );
        $event = Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $params['serviceid'])->value('panel_event_at');
        if ($event) {
            $fields['Last panel webhook'] = $esc($event . ' (' . Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $params['serviceid'])->value('panel_status') . ')');
        }
        return $fields;
    } catch (\Exception $e) {
        return array(xtreampro_isReseller($params) ? 'Xtream UI Pro sub-reseller' : 'Xtream UI Pro line'
            => $esc('Could not load the ' . (xtreampro_isReseller($params) ? 'account' : 'line') . ': ' . $e->getMessage()));
    }
}

function xtreampro_ClientArea(array $params)
{
    $vars = array('error' => '');
    $client = null;
    $reseller = xtreampro_isReseller($params);
    try {
        $client = xtreampro_client($params);
        if ($reseller) {
            $user = xtreampro_loadSubUser($client, $params);
            if ($user === null) {
                throw new RuntimeException('Your account is not available yet.');
            }
            $vars += array(
                'username' => isset($user['username']) ? (string) $user['username'] : (string) $params['username'],
                'password' => isset($params['password']) ? (string) $params['password'] : '',
                'status'   => isset($user['status']) ? (string) $user['status'] : '',
                'credits'  => isset($user['credits']) ? (string) $user['credits'] : '',
                'loginUrl' => xtreampro_panelLoginUrl($params),
            );
            logModuleCall('xtreampro', __FUNCTION__, $client->lastRequest, xtreampro_loggable($client), 'ok', xtreampro_secrets($params));
            return array(
                'templatefile' => 'templates/clientarea_reseller',
                'vars'         => $vars,
            );
        }
        $lineId = xtreampro_resolveLineId($client, $params);
        if ($lineId === null) {
            throw new RuntimeException('Your line is not available yet.');
        }
        $line = $client->getLine($lineId);

        $username = isset($line['username']) ? (string) $line['username'] : '';
        $password = isset($line['password']) ? (string) $line['password'] : '';

        $vars += array(
            'username'       => $username,
            'password'       => $password,
            'status'         => isset($line['status']) ? (string) $line['status'] : '',
            'expiry'         => xtreampro_formatExpiry(isset($line['exp_date']) ? $line['exp_date'] : null),
            'maxConnections' => isset($line['max_connections']) ? (string) $line['max_connections'] : '',
        ) + xtreampro_playLinks($client, $line, $username, $password);
        logModuleCall('xtreampro', __FUNCTION__, $client->lastRequest, xtreampro_loggable($client), 'ok', xtreampro_secrets($params));
    } catch (\Exception $e) {
        logModuleCall(
            'xtreampro',
            __FUNCTION__,
            $client ? $client->lastRequest : null,
            $client ? $client->lastResponse : null,
            $e->getMessage(),
            xtreampro_secrets($params)
        );
        $vars['error'] = $e->getMessage();
    }

    return array(
        // WHMCS appends .tpl
        'templatefile' => $reseller ? 'templates/clientarea_reseller' : 'templates/clientarea',
        'vars'         => $vars,
    );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Run a provisioning action: build the client, catch everything, log the
 * call (API key redacted) and return "success" or the error text.
 */
function xtreampro_run($function, array $params, callable $action)
{
    $client = null;
    try {
        $client = xtreampro_client($params);
        $action($client);
        logModuleCall('xtreampro', $function, $client->lastRequest, xtreampro_loggable($client), 'success', xtreampro_secrets($params));
        return 'success';
    } catch (\Exception $e) {
        logModuleCall(
            'xtreampro',
            $function,
            $client ? $client->lastRequest : null,
            $client ? $client->lastResponse : null,
            $e->getMessage(),
            xtreampro_secrets($params)
        );
        return $e->getMessage();
    }
}

/** Last response body, already stripped of line passwords by the client. */
function xtreampro_loggable(Client $client)
{
    return $client->lastResponse;
}

/** Strings logModuleCall must replace with asterisks. */
function xtreampro_secrets(array $params)
{
    $secrets = array();
    foreach (array('serverpassword', 'serveraccesshash') as $key) {
        if (!empty($params[$key])) {
            $secrets[] = $params[$key];
        }
    }
    return $secrets;
}

/**
 * Panel base URL from the WHMCS server record. "Secure" ticked = https.
 * serverhttpprefix is the fallback for versions that only fill that.
 */
function xtreampro_baseUrl(array $params)
{
    $host = trim(isset($params['serverhostname']) ? (string) $params['serverhostname'] : '');
    if ($host === '' && !empty($params['serverip'])) {
        $host = trim((string) $params['serverip']);
    }
    if ($host === '') {
        throw new ApiException('CONFIG');
    }

    $secure = !empty($params['serversecure'])
        || (isset($params['serverhttpprefix']) && strtolower($params['serverhttpprefix']) === 'https');
    $scheme = $secure ? 'https' : 'http';

    $url = $scheme . '://' . $host;
    $port = isset($params['serverport']) ? (int) $params['serverport'] : 0;
    $default = $secure ? 443 : 80;
    if ($port > 0 && $port !== $default) {
        $url .= ':' . $port;
    }
    return $url;
}

/** API key: the server's Password field, Access Hash as fallback. */
function xtreampro_apiKey(array $params)
{
    if (!empty($params['serverpassword'])) {
        return $params['serverpassword'];
    }
    if (!empty($params['serveraccesshash'])) {
        return $params['serveraccesshash'];
    }
    return '';
}

function xtreampro_client(array $params)
{
    return new Client(xtreampro_baseUrl($params), xtreampro_apiKey($params));
}

function xtreampro_formatExpiry($exp)
{
    if ($exp === null || $exp === '' || (int) $exp <= 0) {
        return 'Never expires';
    }
    return gmdate('Y-m-d H:i', (int) $exp) . ' UTC';
}

/**
 * Play links to show: the ones the panel returned with the line (built on the
 * host the API was called on, or on the reseller's own play address), else, for
 * a panel that sends none, the same links assembled here from the line's credentials.
 */
function xtreampro_playLinks(Client $client, $line, $username, $password)
{
    $links = Client::linksOf($line);
    if ($links !== null) {
        $pick = function ($key) use ($links) {
            return isset($links[$key]) && is_string($links[$key]) ? $links[$key] : '';
        };
        return array(
            'serverUrl'   => $pick('server'),
            'playlistUrl' => $pick('m3u'),
            'hlsUrl'      => $pick('m3u_hls'),
            'epgUrl'      => $pick('xmltv'),
            'playerUrl'   => $pick('web_player'),
        );
    }
    $base = $client->baseUrl();
    return array(
        'serverUrl'   => $base,
        'playlistUrl' => $password === '' ? '' : $base . '/get.php?' . http_build_query(array(
            'username' => $username,
            'password' => $password,
            'type'     => 'm3u_plus',
            'output'   => 'ts',
        ), '', '&', PHP_QUERY_RFC3986),
        'hlsUrl'      => '',
        'epgUrl'      => '',
        'playerUrl'   => $base . '/player/',
    );
}

/** Sign-in address of the panel for a sub-reseller ("Panel address" option + /login), or "". */
function xtreampro_panelLoginUrl(array $params)
{
    $url = isset($params['configoption8']) ? trim((string) $params['configoption8']) : '';
    $parts = $url === '' ? false : parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
        return '';
    }
    return rtrim($url, '/') . '/login';
}

/** Line id kept for a service (0 = none yet). */
function xtreampro_storedLineId($serviceId)
{
    xtreampro_ensureTable();
    return (int) Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $serviceId)->value('line_id');
}

/** How many package changes of this service the panel accepted. */
function xtreampro_changeCount($serviceId)
{
    xtreampro_ensureTable();
    return (int) Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $serviceId)->value('changes');
}

// ---- service <-> line mapping table ---------------------------------------

function xtreampro_ensureTable()
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_xtreampro_lines')) {
        $schema->create('mod_xtreampro_lines', function ($table) {
            $table->unsignedInteger('service_id')->primary();
            $table->bigInteger('line_id');
            // Panel UUID of a sub-reseller account (line_id stays 0 for those).
            $table->string('user_id', 36)->nullable();
            // Counts terminations: part of the create request id.
            $table->unsignedInteger('generation')->default(0);
            // Counts accepted package changes: part of the change request id.
            $table->unsignedInteger('changes')->default(0);
            // Last status the panel pushed by webhook, and when.
            $table->string('panel_status', 16)->nullable();
            $table->dateTime('panel_event_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        return;
    }
    // Table of an earlier install: add the columns it lacks, on demand.
    $added = array(
        'user_id'        => function ($table) { $table->string('user_id', 36)->nullable(); },
        'changes'        => function ($table) { $table->unsignedInteger('changes')->default(0); },
        'panel_status'   => function ($table) { $table->string('panel_status', 16)->nullable(); },
        'panel_event_at' => function ($table) { $table->dateTime('panel_event_at')->nullable(); },
    );
    foreach ($added as $column => $define) {
        if (!$schema->hasColumn('mod_xtreampro_lines', $column)) {
            $schema->table('mod_xtreampro_lines', $define);
        }
    }
}

/** How many times the line of this service was terminated. */
function xtreampro_generation($serviceId)
{
    xtreampro_ensureTable();
    return (int) Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $serviceId)->value('generation');
}

function xtreampro_saveMapping($serviceId, $lineId)
{
    xtreampro_ensureTable();
    $query = Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $serviceId);
    if ($query->exists()) {
        $query->update(array('line_id' => (int) $lineId));
    } else {
        Capsule::table('mod_xtreampro_lines')->insert(array(
            'service_id' => (int) $serviceId,
            'line_id'    => (int) $lineId,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}

/**
 * Forget the line of a terminated service. The row stays (line_id 0) with a
 * higher generation, so a later Create is a new request for the panel.
 */
function xtreampro_closeMapping($serviceId)
{
    xtreampro_ensureTable();
    $query = Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $serviceId);
    if ($query->exists()) {
        $query->update(array('line_id' => 0, 'user_id' => null, 'generation' => Capsule::raw('generation + 1')));
    } else {
        Capsule::table('mod_xtreampro_lines')->insert(array(
            'service_id' => (int) $serviceId,
            'line_id'    => 0,
            'generation' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}

/**
 * Panel line id of a service: from the mapping table, otherwise looked up by
 * exact username (and then remembered). Null when the line cannot be found.
 */
function xtreampro_resolveLineId(Client $client, array $params)
{
    $serviceId = (int) $params['serviceid'];

    xtreampro_ensureTable();
    $id = Capsule::table('mod_xtreampro_lines')->where('service_id', $serviceId)->value('line_id');
    if ($id) {
        return (int) $id;
    }

    $username = isset($params['username']) ? trim((string) $params['username']) : '';
    if ($username === '') {
        return null;
    }
    $line = $client->findLineByUsername($username);
    if ($line === null || !isset($line['id'])) {
        return null;
    }
    xtreampro_saveMapping($serviceId, (int) $line['id']);
    return (int) $line['id'];
}

function xtreampro_requireLineId(Client $client, array $params)
{
    $id = xtreampro_resolveLineId($client, $params);
    if ($id === null) {
        throw new ApiException('RESOURCE_NOT_FOUND');
    }
    return $id;
}

// ---- sub-reseller accounts ---------------------------------------------------

/** Unknown or empty service type is an IPTV line, so existing products are unchanged. */
function xtreampro_isReseller(array $params)
{
    return isset($params['configoption4']) && $params['configoption4'] === 'reseller';
}

/** Whole number >= 0 from a text option. */
function xtreampro_credits($value, $label)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    if (!ctype_digit($value) || strlen($value) > 9) {
        throw new RuntimeException($label . ' in the product configuration must be a whole number of 0 or more.');
    }
    return (int) $value;
}

/** Due date of the service (or today) as Ymd, for renewal request ids. */
function xtreampro_dueStamp($serviceId)
{
    $stamp = date('Ymd');
    try {
        $due = Capsule::table('tblhosting')->where('id', (int) $serviceId)->value('nextduedate');
        if ($due && strtotime((string) $due) !== false && substr((string) $due, 0, 4) !== '0000') {
            $stamp = date('Ymd', strtotime((string) $due));
        }
    } catch (\Exception $e) {
        // fall back to today
    }
    return $stamp;
}

/** Random string from a fixed alphabet, using random_int. */
function xtreampro_random($length, $alphabet)
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function xtreampro_createSubReseller(Client $client, array $params)
{
    $serviceId = (int) $params['serviceid'];
    $generation = xtreampro_generation($serviceId);
    $creditsOnCreate = xtreampro_credits(isset($params['configoption5']) ? $params['configoption5'] : '', 'Credits on creation');

    $groupId = isset($params['configoption9']) ? (int) $params['configoption9'] : 0;
    // Before anything is created: group allowed, price plus credits covered. A service whose
    // account already exists is a replay (or a retry of the credit transfer) and is not checked.
    if (xtreampro_resolveUserId($client, $params) === null) {
        $client->assertCanCreateSubUser($groupId, $creditsOnCreate);
    }

    $username = isset($params['username']) ? trim((string) $params['username']) : '';
    if ($username === '') {
        $username = 'r' . $serviceId . xtreampro_random(6, 'abcdefghijklmnopqrstuvwxyz');
    }
    // Panel rule: letters, digits, "_ . -", 3 to 32 characters. A longer name is cut.
    $username = substr($username, 0, 32);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        throw new RuntimeException('The username "' . $username . '" is not valid for a sub-reseller: use 3 to 32 letters, digits, "_", "." or "-".');
    }
    // The panel needs both username and password when a request id is sent.
    $password = isset($params['password']) ? (string) $params['password'] : '';
    if ($password === '') {
        $password = xtreampro_random(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
    }
    // Keep the credentials on the service BEFORE the panel is called: the panel
    // stores only a hash, so if this call is repeated (timeout, failed credit
    // transfer) it must send the very same values, not newly generated ones.
    xtreampro_saveServiceCredentials($serviceId, $username, $password, true);

    $details = isset($params['clientsdetails']) && is_array($params['clientsdetails']) ? $params['clientsdetails'] : array();
    $email = isset($details['email']) ? (string) $details['email'] : '';
    $fullname = trim((isset($details['firstname']) ? $details['firstname'] : '') . ' ' . (isset($details['lastname']) ? $details['lastname'] : ''));

    $data = $client->createSubUser(
        $username,
        $password,
        $email,
        $fullname,
        'whmcs-sub-' . $serviceId . '-' . $generation,
        $groupId
    );
    $user = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : array();
    if (!isset($user['id'])) {
        throw new ApiException('BAD_RESPONSE');
    }
    $userId = (string) $user['id'];
    if (isset($user['username']) && $user['username'] !== '') {
        $username = (string) $user['username'];
    }
    if (isset($data['password']) && $data['password'] !== '') {
        $password = (string) $data['password'];
    }

    xtreampro_saveUserMapping($serviceId, $userId);
    // The panel may answer with other credentials (a replayed request).
    xtreampro_saveServiceCredentials($serviceId, $username, $password, false);

    if ($creditsOnCreate > 0) {
        $client->adjustCredits(
            $userId,
            $creditsOnCreate,
            'WHMCS service #' . $serviceId,
            'whmcs-subc-' . $serviceId . '-' . $generation
        );
    }
}

/**
 * Write username and password to the WHMCS service (UpdateClientProduct
 * encrypts the password). With $required a failure stops the action; without,
 * it is only logged, because the account already exists and was paid for.
 */
function xtreampro_saveServiceCredentials($serviceId, $username, $password, $required)
{
    $result = localAPI('UpdateClientProduct', array(
        'serviceid'       => (int) $serviceId,
        'serviceusername' => $username,
        'servicepassword' => $password,
    ));
    if (is_array($result) && (!isset($result['result']) || $result['result'] === 'success')) {
        return;
    }
    if ($required) {
        throw new RuntimeException('The username and password could not be saved on the WHMCS service, so no account was created.');
    }
    logActivity('Xtream UI Pro: the credentials of service ' . (int) $serviceId . ' could not be saved on the service');
}

function xtreampro_saveUserMapping($serviceId, $userId)
{
    xtreampro_ensureTable();
    $query = Capsule::table('mod_xtreampro_lines')->where('service_id', (int) $serviceId);
    if ($query->exists()) {
        $query->update(array('user_id' => (string) $userId));
    } else {
        Capsule::table('mod_xtreampro_lines')->insert(array(
            'service_id' => (int) $serviceId,
            'line_id'    => 0,
            'user_id'    => (string) $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}

/**
 * Panel UUID of a service's sub-reseller: from the mapping table, otherwise
 * looked up by exact username (and then remembered). Null when not found.
 */
function xtreampro_resolveUserId(Client $client, array $params)
{
    $serviceId = (int) $params['serviceid'];

    xtreampro_ensureTable();
    $id = Capsule::table('mod_xtreampro_lines')->where('service_id', $serviceId)->value('user_id');
    if ($id) {
        return (string) $id;
    }

    $username = isset($params['username']) ? trim((string) $params['username']) : '';
    if ($username === '') {
        return null;
    }
    $user = $client->findSubUser(null, $username);
    if ($user === null || !isset($user['id'])) {
        return null;
    }
    xtreampro_saveUserMapping($serviceId, (string) $user['id']);
    return (string) $user['id'];
}

function xtreampro_requireUserId(Client $client, array $params)
{
    $id = xtreampro_resolveUserId($client, $params);
    if ($id === null) {
        throw new ApiException('RESOURCE_NOT_FOUND');
    }
    return $id;
}

/** Current panel data of the service's sub-reseller, or null. */
function xtreampro_loadSubUser(Client $client, array $params)
{
    $userId = xtreampro_resolveUserId($client, $params);
    if ($userId === null) {
        return null;
    }
    $username = isset($params['username']) ? trim((string) $params['username']) : '';
    return $client->findSubUser($userId, $username);
}
