<?php
/**
 * Xtream UI Pro - WISECP product module ("Other / special product").
 *
 * Each WISECP service is one IPTV line (or, with Service type "Sub-reseller
 * account", one sub-reseller account) created through the panel's Reseller
 * API. Create / renew are charged to the reseller's credits in the panel.
 *
 * Module settings (Product Group Modules > Xtream UI Pro): API URL + API key.
 * Product settings (product > Automation > Module Configuration): service
 * type, package, trial, delete on terminate, credits on creation / renewal.
 *
 * Written from the WISECP developer documentation (dev.wisecp.com) and the
 * published sample product module; it has NOT run inside a licensed WISECP.
 *
 * The class is global (no namespace), like the sample module: the loader tries
 * the global name first, so this works on both loader generations. Private
 * helpers are prefixed xp so they can never clash with a base class member.
 *
 * @version 1.1.0
 */
class XtreamPro extends ProductModule
{
    const VERSION = '1.1.0';

    /** @var \XtreamPro\Wisecp\Client|null built lazily, never in the constructor */
    private $xpApi = null;

    public function __construct()
    {
        $this->_name = __CLASS__;
        parent::__construct();
    }

    // -----------------------------------------------------------------------
    // Module settings screen (API URL + API key)
    // -----------------------------------------------------------------------

    public function page_configuration(): string
    {
        $settings = $this->config['settings'] ?? [];
        return $this->get_page('configuration', [
            'm_name'   => $this->_name,
            'area_link' => $this->area_link ?? '',
            'lang'     => $this->lang,
            'api_url'  => (string) ($settings['api_url'] ?? ''),
            // The key itself is never sent back to the browser.
            'has_key'  => trim((string) ($settings['api_key'] ?? '')) !== '',
        ]);
    }

    /** Older loaders call configuration() for the settings screen. */
    public function configuration()
    {
        return $this->page_configuration();
    }

    public function controller_save(): array
    {
        $url = trim((string) Filter::init("POST/api_url", "hclear"));
        $parts = parse_url($url);
        if (
            $url === '' || $parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new Exception($this->xpT('err-api-url'));
        }

        $config = $this->config;
        $config['settings']['api_url'] = rtrim($url, '/');

        // Pass-through filter: any other one strips characters of a strong key.
        // Empty or masked = the operator did not retype it: keep the stored one.
        $posted = trim((string) Filter::init("POST/api_key", "password"));
        if ($posted !== '' && strpos($posted, '*') !== 0) {
            $config['settings']['api_key'] = $this->encode_str($posted);
        } elseif (trim((string) ($config['settings']['api_key'] ?? '')) === '') {
            throw new Exception($this->xpT('err-no-key'));
        }

        $this->save_config($config);
        $this->config = $config;
        $this->xpApi = null;

        return ['status' => 'successful', 'message' => $this->xpT('settings-saved')];
    }

    /** Uses the saved settings: save first, then test. */
    public function controller_test_connection(): array
    {
        $info = $this->xpRun('test_connection', function ($api) {
            return $api->userInfo();
        });
        $info = is_array($info) ? $info : [];
        return [
            'status'  => 'successful',
            'message' => sprintf(
                $this->xpT('connection-ok'),
                htmlspecialchars((string) ($info['username'] ?? '?'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) ($info['credits'] ?? '?'), ENT_QUOTES, 'UTF-8')
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Product settings (what one product of this module sells)
    // -----------------------------------------------------------------------

    public function product_configuration(array $data = []): array
    {
        $type = ($data['service_type'] ?? 'line') === 'reseller' ? 'reseller' : 'line';

        // Package list from the panel; a plain id field when the panel cannot be reached.
        $packages = [];
        $error = '';
        try {
            foreach ($this->xpApiClient()->packages() as $pkg) {
                // A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line.
                if (!is_array($pkg) || !isset($pkg['id']) || !\XtreamPro\Wisecp\Client::sellsLine($pkg)) {
                    continue;
                }
                $name = isset($pkg['name']) ? (string) $pkg['name'] : ('#' . $pkg['id']);
                if (!empty($pkg['is_official'])) {
                    $detail = ($pkg['official_credits'] ?? '?') . ' credits, '
                        . ($pkg['official_duration'] ?? '?') . ' ' . ($pkg['official_duration_in'] ?? '');
                } else {
                    $detail = 'trial only';
                }
                $packages[(string) $pkg['id']] = $name . ' (' . trim($detail) . ')';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $saved = (string) ($data['package'] ?? '');
        if ($packages) {
            if ($saved !== '' && !isset($packages[$saved])) {
                $packages = [$saved => '#' . $saved . ' (not listed any more)'] + $packages;
            }
            $package = [
                'name'        => $this->xpT('package'),
                'description' => $this->xpT('package-desc'),
                'type'        => 'dropdown',
                'options'     => $packages,
                'value'       => $saved,
            ];
        } else {
            $package = [
                'name'        => $this->xpT('package-id'),
                'description' => sprintf($this->xpT('package-id-desc'), $error !== '' ? $error : '-'),
                'type'        => 'text',
                'width'       => '20',
                'value'       => $saved,
            ];
        }

        return [
            'service_type' => [
                'name'        => $this->xpT('service-type'),
                'description' => $this->xpT('service-type-desc'),
                'type'        => 'dropdown',
                'options'     => ['line' => $this->xpT('type-line'), 'reseller' => $this->xpT('type-reseller')],
                'value'       => $type,
            ],
            'package'      => $package,
            'trial'        => [
                'name'        => $this->xpT('trial'),
                'description' => $this->xpT('trial-desc'),
                'type'        => 'approval',
                'checked'     => $this->xpFlag($data, 'trial', false),
            ],
            'delete_on_terminate' => [
                'name'        => $this->xpT('delete-on-terminate'),
                'description' => $this->xpT('delete-on-terminate-desc'),
                'type'        => 'approval',
                'checked'     => $this->xpFlag($data, 'delete_on_terminate', false),
            ],
            'credits_on_creation' => [
                'name'        => $this->xpT('credits-on-creation'),
                'description' => $this->xpT('credits-on-creation-desc'),
                'type'        => 'text',
                'width'       => '20',
                'value'       => (string) ($data['credits_on_creation'] ?? '0'),
            ],
            'credits_per_renewal' => [
                'name'        => $this->xpT('credits-per-renewal'),
                'description' => $this->xpT('credits-per-renewal-desc'),
                'type'        => 'text',
                'width'       => '20',
                'value'       => (string) ($data['credits_per_renewal'] ?? '0'),
            ],
        ];
    }

    /** Older loaders ask for the product fields under this name. */
    public function config_options($data = [])
    {
        return $this->product_configuration(is_array($data) ? $data : []);
    }

    /** Validate and normalise what the product form posted (values by reference). */
    public function save_product_configuration(array &$values): void
    {
        $type = (string) ($values['service_type'] ?? 'line');
        if (!in_array($type, ['line', 'reseller'], true)) {
            throw new Exception($this->xpT('err-service-type'));
        }
        $values['service_type'] = $type;

        $package = trim((string) ($values['package'] ?? ''));
        if ($type === 'line' && (!ctype_digit($package) || (int) $package <= 0)) {
            throw new Exception($this->xpT('err-package'));
        }
        $values['package'] = $package;

        // An unticked box is absent from the post: absence means off.
        $values['trial'] = $this->xpTruthy($values['trial'] ?? 0) ? 1 : 0;
        $values['delete_on_terminate'] = $this->xpTruthy($values['delete_on_terminate'] ?? 0) ? 1 : 0;

        $values['credits_on_creation'] = (string) $this->xpCredits($values['credits_on_creation'] ?? '', 'credits-on-creation');
        $values['credits_per_renewal'] = (string) $this->xpCredits($values['credits_per_renewal'] ?? '', 'credits-per-renewal');
    }

    // -----------------------------------------------------------------------
    // Provisioning. Failure is reported by throwing (the queue records it).
    // -----------------------------------------------------------------------

    public function create($order_options = []): array|bool
    {
        return $this->xpRun('create', function ($api) {
            $sid = $this->xpServiceId();
            $data = $this->xpData();
            if ($this->xpIsReseller($data)) {
                $this->xpCreateSubReseller($api, $sid, $data);
            } else {
                $this->xpCreateLine($api, $sid, $data);
            }
            // The core merges these keys into the service options.
            return ['config' => $this->xpState(), 'login' => $this->options['login'] ?? []];
        });
    }

    public function suspend(): array|bool
    {
        return $this->xpRun('suspend', function ($api) {
            $id = $this->xpRequireId($api);
            if ($this->xpIsReseller()) {
                $api->subUserAction('disable_user', $id);
            } else {
                $api->lineAction('disable_line', $id);
            }
            return true;
        });
    }

    public function unsuspend(): array|bool
    {
        return $this->xpRun('unsuspend', function ($api) {
            $id = $this->xpRequireId($api);
            if ($this->xpIsReseller()) {
                $api->subUserAction('enable_user', $id);
            } else {
                $api->lineAction('enable_line', $id);
            }
            return true;
        });
    }

    /** Terminate / cancel. The core resolves terminate() to cancel(). */
    public function cancel(): array|bool
    {
        return $this->xpRun('cancel', function ($api) {
            $data = $this->xpData();
            $id = $this->xpResolveId($api);
            if ($id !== null) {
                try {
                    if ($this->xpIsReseller($data)) {
                        // Accounts are disabled, never deleted: deleting hands their
                        // lines and credits to the parent and cannot be undone.
                        $api->subUserAction('disable_user', $id);
                    } else {
                        $delete = $this->xpFlag($data, 'delete_on_terminate', false);
                        $api->lineAction($delete ? 'delete_line' : 'disable_line', $id);
                    }
                } catch (\XtreamPro\Wisecp\ApiException $e) {
                    // Already gone from the panel = already terminated.
                    if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                        throw $e;
                    }
                }
            }
            if ($id === null) {
                return true; // nothing was provisioned (or it was closed already)
            }
            // Close the service: the generation is part of the create request ids, so
            // creating the service again sells something new instead of replaying.
            $state = $this->xpState();
            $this->options['config'] = ['id' => '', 'generation' => ((int) ($state['generation'] ?? 0)) + 1, 'credits_done' => 0];
            unset($this->options['login']);
            $this->save_options();
            return true;
        });
    }

    /**
     * Renewal (the renewal invoice was paid). Costs reseller credits. The request
     * id contains the due date: WISECP may run a renewal twice for one period, and
     * the repeat then returns the first result instead of charging again.
     */
    public function renew(): array|bool
    {
        return $this->xpRun('renew', function ($api) {
            $sid = $this->xpServiceId();
            $data = $this->xpData();
            $gen = (int) ($this->xpState()['generation'] ?? 0);
            $stamp = $this->xpDueStamp();

            if ($this->xpIsReseller($data)) {
                $credits = $this->xpCredits($data['credits_per_renewal'] ?? '', 'credits-per-renewal');
                if ($credits > 0) {
                    $api->assertCanGiveCredits($credits);
                    $api->adjustCredits(
                        $this->xpRequireId($api),
                        $credits,
                        'WISECP renewal, service #' . $sid,
                        'wisecp-subr-' . $sid . '-' . $gen . '-' . $stamp
                    );
                }
                return true;
            }
            $renewId = $this->xpRequireId($api);
            if ((int) ($data['package'] ?? 0) > 0) {
                // Fails with the amounts when the balance cannot pay the period; nothing is sold then.
                $api->assertCanSellPackage((int) $data['package'], false, false);
            }
            $api->renewLine($renewId, 'wisecp-renew-' . $sid . '-' . $gen . '-' . $stamp);
            return true;
        });
    }

    // Older loaders (see the published sample module): renewal() / delete() return
    // false and keep the message in $this->error instead of throwing.

    public function renewal($order_options = [])
    {
        return $this->xpLegacy(function () {
            return $this->renew();
        });
    }

    public function delete()
    {
        return $this->xpLegacy(function () {
            return $this->cancel();
        });
    }

    // -----------------------------------------------------------------------
    // Client area / admin service detail
    // -----------------------------------------------------------------------

    /** pages/dashboard.php opens the management tab; it also serves the admin service page. */
    public function has_client_management(): bool
    {
        return true;
    }

    /** Older loaders: client area content. */
    public function clientArea()
    {
        return $this->get_page('dashboard');
    }

    /** Older loaders: admin service detail fields. */
    public function adminArea_service_fields()
    {
        return [
            'xtreampro' => [
                'wrap_width' => 100,
                'name'       => $this->xpT('name'),
                'type'       => 'output',
                'value'      => $this->get_page('dashboard'),
            ],
        ];
    }

    /**
     * Everything the dashboard page shows, already loaded from the panel.
     * Errors become the 'error' text and never break the page.
     */
    public function dashboard_view(): array
    {
        $reseller = $this->xpIsReseller();
        $view = ['reseller' => $reseller, 'error' => ''];
        try {
            $api = $this->xpApiClient();
            $login = $this->options['login'] ?? [];
            if ($reseller) {
                $user = $this->xpLoadSubUser($api);
                if ($user === null) {
                    throw new Exception($this->xpT('err-not-provisioned'));
                }
                $view += [
                    'id'       => (string) ($user['id'] ?? ''),
                    'username' => (string) ($user['username'] ?? ($login['username'] ?? '')),
                    'password' => $this->decode_str((string) ($login['password'] ?? '')),
                    'status'   => (string) ($user['status'] ?? ''),
                    'credits'  => (string) ($user['credits'] ?? ''),
                ];
            } else {
                $id = $this->xpResolveId($api);
                if ($id === null) {
                    throw new Exception($this->xpT('err-not-provisioned'));
                }
                $line = $api->getLine($id);
                $line = is_array($line) ? $line : [];
                $links = isset($line['links']) && is_array($line['links']) ? $line['links'] : [];
                $password = (string) ($line['password'] ?? '');
                if ($password === '') {
                    $password = $this->decode_str((string) ($login['password'] ?? ''));
                }
                $view += [
                    'id'              => (string) $id,
                    'username'        => (string) ($line['username'] ?? ($login['username'] ?? '')),
                    'password'        => $password,
                    'status'          => (string) ($line['status'] ?? ''),
                    'expiry'          => $this->xpFormatExpiry($line['exp_date'] ?? null),
                    'max_connections' => (string) ($line['max_connections'] ?? ''),
                    // Links come from the panel (built on the host the API was called on).
                    'server_url'      => (string) ($links['server'] ?? $api->baseUrl()),
                    'playlist_url'    => (string) ($links['m3u'] ?? ''),
                    'player_url'      => (string) ($links['web_player'] ?? ''),
                ];
            }
            $this->xpLog('dashboard', 'ok');
        } catch (\Throwable $e) {
            $this->xpLog('dashboard', $e->getMessage());
            $view['error'] = $e->getMessage();
        }
        return $view;
    }

    /** Live provider state for the admin dashboard and the client overview. */
    public function fetchRemoteStatus(): array
    {
        $v = $this->dashboard_view();
        if ($v['error'] !== '') {
            return [];
        }
        if ($v['reseller']) {
            return ['status' => $v['status'], 'credits' => $v['credits']];
        }
        return ['status' => $v['status'], 'expires_at' => $v['expiry'], 'max_connections' => $v['max_connections']];
    }

    /** Account rows of the customer's overview tab (the core injects the password row). */
    public function client_overview_data(): array
    {
        $v = $this->dashboard_view();
        if ($v['error'] !== '') {
            return [];
        }
        $account = [
            ['key' => 'username', 'label' => $this->xpT('username'), 'type' => 'text', 'copyable' => true, 'value' => $v['username']],
            [
                'key' => 'status', 'label' => $this->xpT('status'), 'type' => 'badge',
                'badge_color' => $v['status'] === 'active' ? 'success' : 'warning', 'value' => ucfirst($v['status']),
            ],
        ];
        $resources = [];
        if ($v['reseller']) {
            $resources[] = ['key' => 'credits', 'label' => $this->xpT('credit-balance'), 'icon' => 'bi bi-coin', 'value' => $v['credits']];
        } else {
            $account[] = ['key' => 'expires', 'label' => $this->xpT('expires'), 'type' => 'text', 'value' => $v['expiry']];
            if ($v['playlist_url'] !== '') {
                $account[] = ['key' => 'playlist', 'label' => $this->xpT('m3u-playlist'), 'type' => 'text', 'copyable' => true, 'value' => $v['playlist_url']];
            }
        }
        return ['gauges' => [], 'resources' => $resources, 'account' => $account];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function xpT(string $key): string
    {
        return (string) ($this->lang[$key] ?? $key);
    }

    private function xpApiClient()
    {
        if ($this->xpApi !== null) {
            return $this->xpApi;
        }
        require_once __DIR__ . '/src/ApiClient.php';
        $settings = $this->config['settings'] ?? [];
        $this->xpApi = new \XtreamPro\Wisecp\Client(
            trim((string) ($settings['api_url'] ?? '')),
            $this->decode_str((string) ($settings['api_key'] ?? ''))
        );
        return $this->xpApi;
    }

    /** Run an action: build the client, log the call (secrets masked), rethrow failures. */
    private function xpRun(string $action, callable $fn)
    {
        try {
            $result = $fn($this->xpApiClient());
            $this->xpLog($action, 'success');
            return $result;
        } catch (\Throwable $e) {
            $this->xpLog($action, $e->getMessage());
            throw $e;
        }
    }

    private function xpLegacy(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * One log row per call in the panel's action history. The request carries no API
     * key and no password; the response copy is masked by the client. Never pass
     * $this->options / $this->service here: they hold the login.
     */
    private function xpLog(string $action, string $processed): void
    {
        try {
            $api = $this->xpApi;
            $request = $api !== null ? $api->lastRequest : null;
            if (is_array($request)) {
                $request['api_url'] = $request['url'] ?? '';
            }
            $response = $api !== null ? $api->lastResponse : null;
            // Older loaders: save_log(type, module, action, request, response, trace).
            if ((new \ReflectionMethod($this, 'save_log'))->getNumberOfParameters() >= 6) {
                $this->save_log('Product', $this->_name, $action, $request, $response, $processed);
            } else {
                $this->save_log($action, $request, $response, $processed);
            }
        } catch (\Throwable $e) {
            // A failing log must never fail a provisioning action.
        }
    }

    /** Product module data: the order-time copy first, then the product's own. */
    private function xpData(): array
    {
        $data = $this->options['creation_info'] ?? [];
        if (!is_array($data) || !$data) {
            $data = $this->product['module_data'] ?? [];
        }
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        return is_array($data) ? $data : [];
    }

    private function xpIsReseller(?array $data = null): bool
    {
        $data = $data ?? $this->xpData();
        return ($data['service_type'] ?? '') === 'reseller';
    }

    private function xpTruthy($v): bool
    {
        return $v === true || $v === 1 || in_array(strtolower((string) $v), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * A checkbox of the product form. Absent in a product that was saved through the
     * form means unticked (an unticked box is not posted); absent in an untouched
     * product means the default.
     */
    private function xpFlag(array $data, string $key, bool $default): bool
    {
        if (array_key_exists($key, $data)) {
            return $this->xpTruthy($data[$key]);
        }
        return isset($data['service_type']) ? false : $default;
    }

    private function xpCredits($value, string $langKey): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        if (!ctype_digit($value) || strlen($value) > 9) {
            throw new Exception(sprintf($this->xpT('err-credits'), $this->xpT($langKey)));
        }
        return (int) $value;
    }

    /** Identity of the service in request ids: the service row, the order as fallback. */
    private function xpServiceId(): int
    {
        $sid = (int) ($this->service['id'] ?? ($this->order['id'] ?? 0));
        if ($sid <= 0) {
            throw new Exception($this->xpT('err-no-service'));
        }
        return $sid;
    }

    /** Per-service state kept in the service options: id, generation, credits_done. */
    private function xpState(): array
    {
        $state = $this->options['config'] ?? [];
        return is_array($state) ? $state : [];
    }

    private function xpSetState(array $changes): void
    {
        $this->options['config'] = array_merge($this->xpState(), $changes);
        $this->save_options();
    }

    /** Due date of the service (or today, UTC) as Ymd, for renewal request ids. */
    private function xpDueStamp(): string
    {
        foreach (['duedate', 'renewaldate'] as $key) {
            $v = $this->service[$key] ?? null;
            if ($v === null || $v === '' || (is_string($v) && strpos($v, '0000') === 0)) {
                continue;
            }
            $ts = is_numeric($v) ? (int) $v : strtotime((string) $v);
            if ($ts !== false && $ts > 0) {
                return gmdate('Ymd', $ts);
            }
        }
        return gmdate('Ymd');
    }

    private function xpFormatExpiry($exp): string
    {
        if ($exp === null || $exp === '' || (int) $exp <= 0) {
            return $this->xpT('never-expires');
        }
        return gmdate('Y-m-d H:i', (int) $exp) . ' UTC';
    }

    private function xpRandom(int $length, string $alphabet): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    // ---- panel ids ----------------------------------------------------------

    /** Panel id of the service's line or sub-reseller: from the state, else by exact username. */
    private function xpResolveId($api)
    {
        $state = $this->xpState();
        if (!empty($state['id'])) {
            return $state['id'];
        }
        $username = trim((string) ($this->options['login']['username'] ?? ''));
        if ($username === '') {
            return null;
        }
        if ($this->xpIsReseller()) {
            $found = $api->findSubUser(null, $username);
        } else {
            $found = $api->findLineByUsername($username);
        }
        if ($found === null || !isset($found['id'])) {
            return null;
        }
        $this->xpSetState(['id' => $found['id']]);
        return $found['id'];
    }

    private function xpRequireId($api)
    {
        $id = $this->xpResolveId($api);
        if ($id === null) {
            throw new \XtreamPro\Wisecp\ApiException('RESOURCE_NOT_FOUND');
        }
        return $id;
    }

    private function xpLoadSubUser($api): ?array
    {
        $id = $this->xpResolveId($api);
        if ($id === null) {
            return null;
        }
        return $api->findSubUser((string) $id, trim((string) ($this->options['login']['username'] ?? '')));
    }

    // ---- create -------------------------------------------------------------

    private function xpCreateLine($api, int $sid, array $data): void
    {
        $state = $this->xpState();
        if (!empty($state['id'])) {
            return; // retry safety: the line exists
        }
        $package = (int) ($data['package'] ?? 0);
        if ($package <= 0) {
            throw new Exception($this->xpT('err-package'));
        }
        $gen = (int) ($state['generation'] ?? 0);

        // Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
        // with the amounts and nothing is created.
        $api->assertCanSellPackage($package, $this->xpFlag($data, 'trial', false), true);

        // Blank values are generated by the panel. The reseller's group may also ignore
        // custom credentials, so the final ones are read back below. A customer can only
        // choose them through product requirements named "username" / "password".
        $result = $api->createLine(
            $package,
            $this->xpFlag($data, 'trial', false),
            trim((string) ($this->requirement_params['username'] ?? '')),
            (string) ($this->requirement_params['password'] ?? ''),
            'wisecp-create-' . $sid . '-' . $gen
        );
        $line = (is_array($result) && isset($result['line']) && is_array($result['line'])) ? $result['line'] : [];
        if (!isset($line['id'])) {
            throw new \XtreamPro\Wisecp\ApiException('BAD_RESPONSE');
        }
        $password = (string) ($line['password'] ?? '');
        if ($password === '' && isset($result['password'])) {
            $password = (string) $result['password'];
        }
        $this->options['login'] = [
            'username' => (string) ($line['username'] ?? ''),
            'password' => $this->encode_str($password),
        ];
        $this->xpSetState(['id' => (int) $line['id'], 'generation' => $gen]);
    }

    private function xpCreateSubReseller($api, int $sid, array $data): void
    {
        $state = $this->xpState();
        $gen = (int) ($state['generation'] ?? 0);
        // Validate the amounts before anything is charged.
        $credits = $this->xpCredits($data['credits_on_creation'] ?? '', 'credits-on-creation');

        if (empty($state['id'])) {
            // The price of the account plus the credits to hand over: checked before the account exists.
            $api->assertCanCreateSubUser($credits);
            $login = $this->options['login'] ?? [];
            // A repeated call (timeout, failed credit transfer) must send the very same
            // credentials: the panel stores only a hash, so new ones could not be read back.
            $username = trim((string) ($login['username'] ?? ''));
            $password = $this->decode_str((string) ($login['password'] ?? ''));
            if ($username === '') {
                $username = trim((string) ($this->requirement_params['username'] ?? ''));
            }
            if ($username === '') {
                $username = 'r' . $sid . $this->xpRandom(6, 'abcdefghijklmnopqrstuvwxyz');
            }
            $username = substr($username, 0, 32);
            if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
                throw new Exception(sprintf($this->xpT('err-bad-username'), $username));
            }
            if ($password === '') {
                $password = (string) ($this->requirement_params['password'] ?? '');
            }
            if ($password === '') {
                // The panel needs both username and password when a request id is sent.
                $password = $this->xpRandom(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
            }
            $this->options['login'] = ['username' => $username, 'password' => $this->encode_str($password)];
            if ($this->save_options() === false) {
                throw new Exception($this->xpT('err-save-credentials'));
            }

            $email = trim((string) ($this->user['email'] ?? ''));
            if ($gen > 0 && ($at = strrpos($email, '@')) !== false) {
                // The panel keeps e-mail addresses unique even for disabled accounts, so a
                // service created again gets a tagged address: name+g1@example.com.
                $email = substr($email, 0, $at) . '+g' . $gen . substr($email, $at);
            }
            $fullname = trim((string) ($this->user['full_name'] ?? ''));
            if ($fullname === '') {
                $fullname = trim((string) ($this->user['name'] ?? '') . ' ' . (string) ($this->user['surname'] ?? ''));
            }

            $result = $api->createSubUser($username, $password, $email, $fullname, 'wisecp-sub-' . $sid . '-' . $gen);
            $user = (is_array($result) && isset($result['user']) && is_array($result['user'])) ? $result['user'] : [];
            if (!isset($user['id'])) {
                throw new \XtreamPro\Wisecp\ApiException('BAD_RESPONSE');
            }
            // The panel may answer with other credentials (a replayed request).
            if (!empty($user['username'])) {
                $username = (string) $user['username'];
            }
            if (!empty($result['password'])) {
                $password = (string) $result['password'];
            }
            $this->options['login'] = ['username' => $username, 'password' => $this->encode_str($password)];
            $this->xpSetState(['id' => (string) $user['id'], 'generation' => $gen, 'credits_done' => 0]);
        }

        // Separate step with its own request id: when it fails the account stays
        // mapped and running Create again retries only the transfer.
        if ($credits > 0 && empty($this->xpState()['credits_done'])) {
            $api->adjustCredits(
                (string) $this->xpState()['id'],
                $credits,
                'WISECP service #' . $sid,
                'wisecp-subc-' . $sid . '-' . $gen
            );
            $this->xpSetState(['credits_done' => 1]);
        }
    }
}
