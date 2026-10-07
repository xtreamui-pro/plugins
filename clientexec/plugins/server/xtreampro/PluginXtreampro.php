<?php
/**
 * Xtream UI Pro - ClientExec server plugin.
 *
 * Each ClientExec package (service) is one IPTV line, or - when the product's
 * "Service type" is "Sub-reseller account" - one sub-reseller account, created
 * through the panel's Reseller API. Creating a line / account and handing over
 * credits are charged to the reseller's credits in the panel.
 *
 * This file is only the ClientExec glue. Everything that talks to the panel
 * lives in lib/Client.php and lib/Provisioner.php (no ClientExec classes
 * there). Here we read the ClientExec arguments, call the provisioner and store
 * what it returns on the package.
 *
 * Where things are kept on the ClientExec package (standard custom fields):
 *   "User Name" / "Password"   the credentials the panel really used
 *   "Server Acct Properties"   "<panel id>|<generation>"; the panel id of the line
 *                              (a number) or of the sub-reseller (a UUID), the
 *                              generation counts terminations (see Provisioner)
 *
 * @version 1.1.0
 */

require_once 'modules/admin/models/ServerPlugin.php';
require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Provisioner.php';

use XtreamPro\ClientExec\ApiException;
use XtreamPro\ClientExec\Client;
use XtreamPro\ClientExec\Provisioner;

class PluginXtreampro extends ServerPlugin
{
    public $features = [
        'packageName' => false,
        'testConnection' => true,
        'showNameservers' => false,
        'directlink' => true,
        'upgrades' => false
    ];

    // ---------------------------------------------------------------------
    // Plugin definition
    // ---------------------------------------------------------------------

    public function getVariables()
    {
        $variables = [
            lang('Name') => [
                'type' => 'hidden',
                'description' => 'Used by CE to show plugin - must match how you call the action function names',
                'value' => 'Xtreampro'
            ],
            lang('Description') => [
                'type' => 'hidden',
                'description' => lang('Description viewable by admin in server settings'),
                'value' => lang('Xtream UI Pro IPTV panel (Reseller API)')
            ],
            lang('API Key') => [
                'type' => 'password',
                'description' => lang('API key of the reseller account in the panel (dashboard page /api-key). The server hostname above is the host of the panel API, e.g. api.example.com.'),
                'value' => '',
                'encryptable' => true
            ],
            lang('Use SSL') => [
                'type' => 'yesno',
                'description' => lang('Talk to the panel API over https (strongly recommended; the certificate is always verified).'),
                'value' => '1'
            ],
            lang('Port') => [
                'type' => 'text',
                'description' => lang('Only when the API is not on the standard port (443 with SSL, 80 without). Leave empty otherwise.'),
                'value' => ''
            ],
            lang('Playlist URL Custom Field') => [
                'type' => 'text',
                'description' => lang('Optional. Name of a package custom field that receives the M3U playlist link of an IPTV line, so the customer can see it.'),
                'value' => ''
            ],
            lang('Web Player URL Custom Field') => [
                'type' => 'text',
                'description' => lang('Optional. Name of a package custom field that receives the web player link of an IPTV line.'),
                'value' => ''
            ],
            lang('Server URL Custom Field') => [
                'type' => 'text',
                'description' => lang('Optional. Name of a package custom field that receives the server (portal) URL of an IPTV line.'),
                'value' => ''
            ],
            lang('Credit Balance Custom Field') => [
                'type' => 'text',
                'description' => lang('Optional. Name of a package custom field that receives the credit balance of a sub-reseller account.'),
                'value' => ''
            ],
            lang('reseller') => [
                'type' => 'hidden',
                'description' => lang('Whether this server plugin can set reseller accounts'),
                'value' => '0',
            ],
            lang('Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin per server'),
                'value' => 'Create,Delete,Suspend,UnSuspend,Renew'
            ],
            lang('Registered Actions For Customer') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin per server for customers'),
                'value' => ''
            ],
            lang('package_addons') => [
                'type' => 'hidden',
                'description' => lang('Supported signup addons variables'),
                'value' => '',
            ],
            lang('package_vars') => [
                'type' => 'hidden',
                'description' => lang('Whether package settings are set'),
                'value' => '1',
            ],
            lang('package_vars_values') => [
                'type' => 'hidden',
                'description' => lang('Xtream UI Pro package settings'),
                'value' => [
                    'service_type' => [
                        'type' => 'dropdown',
                        'multiple' => false,
                        'getValues' => 'getServiceTypes',
                        'label' => 'Service type',
                        'description' => lang('What one package sells. The settings below marked "line" only apply to IPTV lines, those marked "sub-reseller" only to sub-reseller accounts.'),
                        'value' => 'line',
                    ],
                    'panel_package_id' => [
                        'type' => 'text',
                        'label' => 'Panel package id (line)',
                        'description' => lang('Id of the panel package the line is created with (a number; it is the "id" of the packages list in the Reseller API).'),
                        'value' => '',
                    ],
                    'trial' => [
                        'type' => 'yesno',
                        'label' => 'Trial line (line)',
                        'description' => lang('Create the line as a trial.'),
                        'value' => '0',
                    ],
                    'delete_on_terminate' => [
                        'type' => 'yesno',
                        'label' => 'Delete permanently on terminate (line)',
                        'description' => lang('No (default): the line is only disabled when the package is deleted and can be enabled again on the panel. Yes: the line is deleted on the panel - deleting is final, nothing can be brought back.'),
                        'value' => '0',
                    ],
                    'credits_on_creation' => [
                        'type' => 'text',
                        'label' => 'Credits on creation (sub-reseller)',
                        'description' => lang('Whole number, 0 or more: credits given to the new sub-reseller account.'),
                        'value' => '0',
                    ],
                    'credits_per_renewal' => [
                        'type' => 'text',
                        'label' => 'Credits per renewal (sub-reseller)',
                        'description' => lang('Whole number, 0 or more: credits given each time the Renew action is run (0 = Renew does nothing for sub-reseller accounts).'),
                        'value' => '0',
                    ],
                ]
            ],
        ];
        return $variables;
    }

    /** Options of the "Service type" dropdown (called by ClientExec through 'getValues'). */
    public function getServiceTypes()
    {
        return [
            'line' => 'IPTV line',
            'reseller' => 'Sub-reseller account',
        ];
    }

    /**
     * Nothing to normalise: the panel decides what usernames it accepts and
     * the answer (readable error) comes back when the account is created.
     */
    public function validateCredentials($args)
    {
    }

    // ---------------------------------------------------------------------
    // Actions called by ClientExec (do* = button / event, then the real work)
    // ---------------------------------------------------------------------

    public function doCreate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->create($this->buildParams($userPackage));
        return $this->label($userPackage) . ' has been created.';
    }

    public function doSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->suspend($this->buildParams($userPackage));
        return $this->label($userPackage) . ' has been suspended.';
    }

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->unsuspend($this->buildParams($userPackage));
        return $this->label($userPackage) . ' has been unsuspended.';
    }

    public function doDelete($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $params = $this->buildParams($userPackage);
        $deleted = $this->delete($params);
        return $this->label($userPackage) . ($deleted ? ' has been deleted.' : ' has been disabled.');
    }

    public function doUpdate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->update($this->buildParams($userPackage, $args));
        return $this->label($userPackage) . ' has been updated.';
    }

    /** Custom action "Renew": run by the admin (ClientExec does not signal renewals to server plugins). */
    public function doRenew($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $done = $this->renew($this->buildParams($userPackage));
        if (!$done) {
            return $this->label($userPackage) . ': nothing to renew (Credits per renewal is 0).';
        }
        return $this->label($userPackage) . ' has been renewed.';
    }

    // ---------------------------------------------------------------------
    // The work: each method reads the ClientExec arguments, calls the core
    // and stores the result. Panel failures become a CE_Exception.
    // ---------------------------------------------------------------------

    public function create($args)
    {
        $this->run($args, function (Provisioner $core) use ($args) {
            $kind = $this->kind($args);
            $userPackage = new UserPackage($args['package']['id']);
            $service = $this->service($args);
            $v = $args['package']['variables'];

            // Saves what the panel (or the core) decided, as soon as it is known.
            $persist = function (array $r) use ($userPackage, $service) {
                $this->storeResult($userPackage, $r, $service['generation']);
            };

            if ($kind === Provisioner::KIND_RESELLER) {
                $result = $core->createSubReseller(
                    $service,
                    isset($args['customer']['email']) ? $args['customer']['email'] : '',
                    $this->customerName($args),
                    isset($v['credits_on_creation']) ? $v['credits_on_creation'] : '0',
                    $persist
                );
            } else {
                $result = $core->createLine(
                    $service,
                    isset($v['panel_package_id']) ? $v['panel_package_id'] : '',
                    $this->yes(isset($v['trial']) ? $v['trial'] : '0'),
                    $persist
                );
            }
            $this->refreshInfo($core, $userPackage, $args, $kind, array('remote_id' => $result['remote_id']) + $service);
        });
    }

    public function suspend($args)
    {
        $this->run($args, function (Provisioner $core) use ($args) {
            $kind = $this->kind($args);
            $core->suspend($kind, $this->service($args));
        });
    }

    public function unsuspend($args)
    {
        $this->run($args, function (Provisioner $core) use ($args) {
            $kind = $this->kind($args);
            $service = $this->service($args);
            $core->unsuspend($kind, $service);
            $this->refreshInfo($core, new UserPackage($args['package']['id']), $args, $kind, $service);
        });
    }

    /**
     * Terminate. Returns true when a line was deleted, false when it was only
     * disabled (sub-reseller accounts are always only disabled).
     */
    public function delete($args)
    {
        $deleted = false;
        $this->run($args, function (Provisioner $core) use ($args, &$deleted) {
            $kind = $this->kind($args);
            $v = $args['package']['variables'];
            $deleteLine = $this->yes(isset($v['delete_on_terminate']) ? $v['delete_on_terminate'] : '0');
            $deleted = ($kind === Provisioner::KIND_LINE) && $deleteLine;

            $generation = $core->terminate($kind, $this->service($args), $deleteLine);

            // Forget the panel id, keep the raised generation: creating the
            // package again then sells a new line.
            $userPackage = new UserPackage($args['package']['id']);
            $userPackage->setCustomField('Server Acct Properties', '|' . $generation);
        });
        return $deleted;
    }

    public function renew($args)
    {
        $done = false;
        $this->run($args, function (Provisioner $core) use ($args, &$done) {
            $kind = $this->kind($args);
            $v = $args['package']['variables'];
            $service = $this->service($args);
            $done = $core->renew($kind, $service, isset($v['credits_per_renewal']) ? $v['credits_per_renewal'] : '0', date('Ymd'), isset($v['panel_package_id']) ? $v['panel_package_id'] : 0);
            if ($done) {
                $this->refreshInfo($core, new UserPackage($args['package']['id']), $args, $kind, $service);
            }
        });
        return $done;
    }

    /**
     * ClientExec calls this on package or password changes. A package change sells the new package on the
     * line (`change_package`, charged to the reseller); a password change cannot be done through the Reseller
     * API, so say so instead of silently diverging.
     *
     * Assumed (not run in ClientExec): $args['package'] already describes the NEW package, so its
     * "Panel package id" is the one to sell, as in ClientExec's own server plugins.
     */
    public function update($args)
    {
        if (!isset($args['changes']) || !is_array($args['changes'])) {
            return;
        }
        foreach ($args['changes'] as $key => $value) {
            if ($key === 'package') {
                if ($this->kind($args) === Provisioner::KIND_RESELLER) {
                    // A sub-reseller account has no package on the panel: only the ClientExec package changes.
                    continue;
                }
                $this->run($args, function (Provisioner $core) use ($args) {
                    $v = $args['package']['variables'];
                    $done = $core->changePackage($this->service($args), isset($v['panel_package_id']) ? $v['panel_package_id'] : 0);
                    if ($done !== null && $done['sentence'] !== '') {
                        CE_Lib::log(4, 'Xtream UI Pro: package changed. ' . $done['sentence']);
                    }
                    $this->refreshInfo($core, new UserPackage($args['package']['id']), $args, Provisioner::KIND_LINE, $this->service($args));
                });
                continue;
            }
            if ($key === 'password') {
                throw new CE_Exception('Changing the password is not available through the panel\'s Reseller API. Change it in the panel; the password stored in ClientExec is not changed by this.');
            }
        }
    }

    public function testConnection($args)
    {
        CE_Lib::log(4, 'Testing connection to Xtream UI Pro');
        try {
            $this->client($args)->userInfo();
        } catch (ApiException $e) {
            throw new CE_Exception($e->getMessage());
        }
    }

    /** Buttons ClientExec offers for this package, from the real state in the panel. */
    public function getAvailableActions($userPackage)
    {
        $args = $this->buildParams($userPackage);
        $service = $this->service($args);
        if ($service['remote_id'] === '') {
            return ['Create'];
        }
        $kind = $this->kind($args);
        $v = $args['package']['variables'];
        $canRenew = ($kind === Provisioner::KIND_LINE)
            || (isset($v['credits_per_renewal']) && (int) $v['credits_per_renewal'] > 0);

        try {
            $info = (new Provisioner($this->client($args)))->describe($kind, $service);
        } catch (\Exception $e) {
            // Panel unreachable: offer everything rather than hide the buttons.
            CE_Lib::log(4, 'Xtream UI Pro: could not read the state: ' . $e->getMessage());
            $actions = ['Suspend', 'UnSuspend', 'Delete'];
            if ($canRenew) {
                $actions[] = 'Renew';
            }
            return $actions;
        }
        if ($info === null) {
            return ['Create'];
        }
        $actions = [($info['status'] === 'disabled') ? 'UnSuspend' : 'Suspend', 'Delete'];
        if ($canRenew) {
            $actions[] = 'Renew';
        }
        return $actions;
    }

    /**
     * "Direct link" button: opens the web player of an IPTV line. Sub-reseller
     * accounts have no link (they sign in at the panel dashboard).
     */
    public function getDirectLink($userPackage, $getRealLink = true, $fromAdmin = false, $isReseller = false)
    {
        $linkText = $this->user->lang('Open web player');
        if ($fromAdmin) {
            return [
                'cmd' => 'panellogin',
                'label' => $linkText
            ];
        }
        if ($getRealLink) {
            $args = $this->buildParams($userPackage);
            if ($this->kind($args) === Provisioner::KIND_RESELLER) {
                throw new CE_Exception('Sub-reseller accounts sign in at the panel dashboard; there is no direct link.');
            }
            $link = '';
            try {
                $info = (new Provisioner($this->client($args)))->describe(Provisioner::KIND_LINE, $this->service($args));
                $link = ($info !== null && !empty($info['links']['web_player'])) ? (string) $info['links']['web_player'] : '';
            } catch (ApiException $e) {
                throw new CE_Exception($e->getMessage());
            }
            if ($link === '') {
                throw new CE_Exception('The panel returned no web player link for this line.');
            }
            return [
                'fa' => 'fa fa-play fa-fw',
                'link' => $link,
                'text' => $linkText,
                'form' => ''
            ];
        }
        $link = 'index.php?fuse=clients&controller=products&action=openpackagedirectlink&packageId=' . $userPackage->getId() . '&sessionHash=' . CE_Lib::getSessionHash();
        return [
            'fa' => 'fa fa-play fa-fw',
            'link' => $link,
            'text' => $linkText,
            'form' => ''
        ];
    }

    public function dopanellogin($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $response = $this->getDirectLink($userPackage);
        return $response['link'];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Build the client and the core, run the action and turn every failure into
     * a CE_Exception with a readable message. The log never gets the API key or
     * a password (the client masks them).
     */
    private function run(array $args, callable $action)
    {
        try {
            $core = new Provisioner($this->client($args));
            $action($core);
        } catch (ApiException $e) {
            CE_Lib::log(4, 'Xtream UI Pro: ' . $e->getErrorCode());
            throw new CE_Exception($e->getMessage());
        } catch (\RuntimeException $e) {
            CE_Lib::log(4, 'Xtream UI Pro: ' . $e->getMessage());
            throw new CE_Exception($e->getMessage());
        }
    }

    /** Panel client from the ClientExec server record. */
    private function client(array $args)
    {
        $host = trim((string) $this->serverVar($args, 'ServerHostName'));
        if (substr($host, 0, 6) === 'ssl://') {
            $host = substr($host, 6);
        }
        if ($host === '') {
            throw new ApiException('CONFIG');
        }
        $ssl = $this->yes($this->serverVar($args, 'Use SSL', '1'));
        $port = (int) $this->serverVar($args, 'Port', '');
        $base = ($ssl ? 'https://' : 'http://') . $host;
        if ($port > 0 && $port !== ($ssl ? 443 : 80)) {
            $base .= ':' . $port;
        }
        return new Client($base, (string) $this->serverVar($args, 'API Key'), function ($line) {
            CE_Lib::log(4, 'Xtream UI Pro: ' . $line);
        });
    }

    /** Server variable: ClientExec names them plugin_<plugin>_<Label with underscores>. */
    private function serverVar(array $args, $label, $default = '')
    {
        $vars = isset($args['server']['variables']) ? $args['server']['variables'] : [];
        if ($label === 'ServerHostName') {
            return isset($vars['ServerHostName']) ? $vars['ServerHostName'] : $default;
        }
        $key = 'plugin_xtreampro_' . str_replace(' ', '_', $label);
        return isset($vars[$key]) ? $vars[$key] : $default;
    }

    private function kind(array $args)
    {
        return Provisioner::kindOf(isset($args['package']['variables']['service_type']) ? $args['package']['variables']['service_type'] : '');
    }

    /** The service as the core wants it, from the ClientExec package. */
    private function service(array $args)
    {
        $state = html_entity_decode((string) (isset($args['package']['ServerAcctProperties']) ? $args['package']['ServerAcctProperties'] : ''));
        $parts = explode('|', $state, 2);
        return [
            'id' => (string) $args['package']['id'],
            'generation' => isset($parts[1]) ? max(0, (int) $parts[1]) : 0,
            'remote_id' => trim($parts[0]),
            'username' => isset($args['package']['username']) ? html_entity_decode((string) $args['package']['username']) : '',
            'password' => isset($args['package']['password']) ? html_entity_decode((string) $args['package']['password']) : '',
        ];
    }

    /** Store the credentials and the panel id on the package. */
    private function storeResult(UserPackage $userPackage, array $result, $generation)
    {
        $userPackage->setCustomField('User Name', $result['username']);
        $userPackage->setCustomField('Password', $result['password']);
        if ($result['remote_id'] !== '') {
            $userPackage->setCustomField('Server Acct Properties', $result['remote_id'] . '|' . (int) $generation);
        }
    }

    /**
     * Write link / balance values into the custom fields the admin named in the
     * server settings, so the customer sees them. Never fails the action: the
     * line or account already exists in the panel.
     */
    private function refreshInfo(Provisioner $core, UserPackage $userPackage, array $args, $kind, array $service)
    {
        try {
            $info = $core->describe($kind, $service);
            if ($info === null) {
                return;
            }
            if ($kind === Provisioner::KIND_RESELLER) {
                $this->setField($userPackage, $args, 'Credit Balance Custom Field', isset($info['credits']) ? $info['credits'] : '');
                return;
            }
            $links = isset($info['links']) ? $info['links'] : [];
            $this->setField($userPackage, $args, 'Playlist URL Custom Field', isset($links['m3u']) ? $links['m3u'] : '');
            $this->setField($userPackage, $args, 'Web Player URL Custom Field', isset($links['web_player']) ? $links['web_player'] : '');
            $this->setField($userPackage, $args, 'Server URL Custom Field', isset($links['server']) ? $links['server'] : '');
        } catch (\Exception $e) {
            CE_Lib::log(4, 'Xtream UI Pro: could not update the info fields: ' . $e->getMessage());
        }
    }

    private function setField(UserPackage $userPackage, array $args, $serverVarLabel, $value)
    {
        $name = trim((string) $this->serverVar($args, $serverVarLabel, ''));
        if ($name !== '' && $value !== '' && $value !== null) {
            $userPackage->setCustomField($name, (string) $value, CUSTOM_FIELDS_FOR_PACKAGE);
        }
    }

    private function customerName(array $args)
    {
        try {
            $user = new User($args['customer']['id']);
            return trim($user->getFirstName() . ' ' . $user->getLastname());
        } catch (\Exception $e) {
            return '';
        }
    }

    /** ClientExec yes/no values are '1' / '0' (also true / 'on'). */
    private function yes($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'yes';
    }

    /** Short name for the confirmation messages. */
    private function label(UserPackage $userPackage)
    {
        $name = trim((string) $userPackage->getCustomField('User Name'));
        return $name !== '' ? 'Xtream UI Pro service ' . $name : 'Xtream UI Pro service #' . $userPackage->getId();
    }
}
