<?php

/**
 * Xtream UI Pro - Blesta module.
 *
 * Each Blesta service is one IPTV line (or, with Service type "Sub-reseller
 * account", one sub-reseller account) created through the panel's Reseller API.
 * Creating and renewing are charged to the reseller's credits in the panel.
 *
 * Module row (server) setup in Blesta: host name, port, "Use SSL" and the
 * reseller API key (stored encrypted).
 *
 * @package blesta
 * @subpackage blesta.components.modules.xtreampro
 * @version 1.1.0
 */
class Xtreampro extends Module
{
    const VERSION = '1.1.0';

    /** Package meta keys, in the order they are stored. */
    private static $package_meta = [
        'service_type', 'package_id', 'trial', 'delete_on_cancel', 'credits_on_creation', 'credits_per_renewal'
    ];

    /**
     * Initializes the module.
     */
    public function __construct()
    {
        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this module
        Language::loadLang('xtreampro', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load module config
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    /**
     * Returns the current version of the module.
     *
     * @return string The version
     */
    public function getVersion()
    {
        return self::VERSION;
    }

    /**
     * Returns all tabs to display to a client when managing a service whose
     * package uses this module.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title
     */
    public function getClientTabs($package)
    {
        return [
            'tabClientAccount' => Language::_('Xtreampro.tab_client_account', true)
        ];
    }

    /**
     * Returns the available service delegation order methods.
     *
     * @return array method => name
     */
    public function getGroupOrderOptions()
    {
        return ['first' => Language::_('Xtreampro.order_options.first', true)];
    }

    /**
     * Chooses the module row of a group of rows.
     *
     * @param int $module_group_id The module group
     * @return int The module row id, 0 when the group has no row
     */
    public function selectModuleRow($module_group_id)
    {
        if (!isset($this->ModuleManager)) {
            Loader::loadModels($this, ['ModuleManager']);
        }

        $group = $this->ModuleManager->getGroup($module_group_id);
        if ($group) {
            foreach ($group->rows as $row) {
                return $row->id;
            }
        }

        return 0;
    }

    /**
     * Tags usable in emails.
     *
     * @return array module, package and service tag names
     */
    public function getEmailTags()
    {
        return [
            'module' => ['host_name', 'port'],
            'package' => ['service_type', 'package_id'],
            'service' => ['xtreampro_username', 'xtreampro_password']
        ];
    }

    // ------------------------------------------------------------------
    // Package (product) fields
    // ------------------------------------------------------------------

    /**
     * Returns all fields used when adding/editing a package.
     *
     * @param stdClass $vars A stdClass object representing a set of post fields
     * @return ModuleFields The fields to render
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $meta = (isset($vars->meta) && is_array($vars->meta)) ? $vars->meta : [];
        $fields = new ModuleFields();

        // Show the options that apply to the chosen service type only.
        $fields->setHtml("
            <script type=\"text/javascript\">
                (function() {
                    var type = document.getElementById('xtreampro_service_type');
                    if (!type) { return; }
                    function wrap(id) {
                        var el = document.getElementById(id);
                        if (!el) { return null; }
                        return el.closest('.mb-3') || el.closest('li') || el.parentNode;
                    }
                    function update() {
                        var line = (type.value !== 'reseller');
                        ['xtreampro_package_id', 'xtreampro_trial', 'xtreampro_delete_on_cancel'].forEach(function(id) {
                            var w = wrap(id);
                            if (w) { w.style.display = line ? '' : 'none'; }
                        });
                        ['xtreampro_credits_on_creation', 'xtreampro_credits_per_renewal'].forEach(function(id) {
                            var w = wrap(id);
                            if (w) { w.style.display = line ? 'none' : ''; }
                        });
                    }
                    update();
                    type.addEventListener('change', update);
                })();
            </script>
        ");

        // Service type
        $type = $fields->label(Language::_('Xtreampro.package_fields.service_type', true), 'xtreampro_service_type');
        $type->attach(
            $fields->fieldSelect(
                'meta[service_type]',
                [
                    'line' => Language::_('Xtreampro.service_type.line', true),
                    'reseller' => Language::_('Xtreampro.service_type.reseller', true)
                ],
                ($meta['service_type'] ?? 'line'),
                ['id' => 'xtreampro_service_type']
            )
        );
        $type->attach($fields->tooltip(Language::_('Xtreampro.package_fields.tooltip.service_type', true)));
        $fields->setField($type);

        // Panel package, loaded from the panel through the first module row
        $package = $fields->label(Language::_('Xtreampro.package_fields.package_id', true), 'xtreampro_package_id');
        $package->attach(
            $fields->fieldSelect(
                'meta[package_id]',
                $this->getPanelPackageOptions($vars),
                ($meta['package_id'] ?? null),
                ['id' => 'xtreampro_package_id']
            )
        );
        $package->attach($fields->tooltip(Language::_('Xtreampro.package_fields.tooltip.package_id', true)));
        $fields->setField($package);

        // Trial
        $trial = $fields->label(Language::_('Xtreampro.package_fields.trial', true), 'xtreampro_trial');
        $trial->attach(
            $fields->fieldCheckbox(
                'meta[trial]',
                'true',
                ($meta['trial'] ?? 'false') == 'true',
                ['id' => 'xtreampro_trial']
            )
        );
        $trial->attach($fields->tooltip(Language::_('Xtreampro.package_fields.tooltip.trial', true)));
        $fields->setField($trial);

        // Delete or only disable on cancel
        $delete = $fields->label(
            Language::_('Xtreampro.package_fields.delete_on_cancel', true),
            'xtreampro_delete_on_cancel'
        );
        $delete->attach(
            $fields->fieldCheckbox(
                'meta[delete_on_cancel]',
                'true',
                ($meta['delete_on_cancel'] ?? 'false') == 'true',
                ['id' => 'xtreampro_delete_on_cancel']
            )
        );
        $delete->attach($fields->tooltip(Language::_('Xtreampro.package_fields.tooltip.delete_on_cancel', true)));
        $fields->setField($delete);

        // Credits on creation
        $creation = $fields->label(
            Language::_('Xtreampro.package_fields.credits_on_creation', true),
            'xtreampro_credits_on_creation'
        );
        $creation->attach(
            $fields->fieldText(
                'meta[credits_on_creation]',
                ($meta['credits_on_creation'] ?? '0'),
                ['id' => 'xtreampro_credits_on_creation']
            )
        );
        $creation->attach($fields->tooltip(Language::_('Xtreampro.package_fields.tooltip.credits_on_creation', true)));
        $fields->setField($creation);

        // Credits per renewal
        $renewal = $fields->label(
            Language::_('Xtreampro.package_fields.credits_per_renewal', true),
            'xtreampro_credits_per_renewal'
        );
        $renewal->attach(
            $fields->fieldText(
                'meta[credits_per_renewal]',
                ($meta['credits_per_renewal'] ?? '0'),
                ['id' => 'xtreampro_credits_per_renewal']
            )
        );
        $renewal->attach($fields->tooltip(Language::_('Xtreampro.package_fields.tooltip.credits_per_renewal', true)));
        $fields->setField($renewal);

        return $fields;
    }

    /**
     * Validates input data when attempting to add a package and returns the
     * meta data to store.
     *
     * @param array $vars An array of key/value pairs used to add the package
     * @return array Meta fields (key, value, encrypted)
     */
    public function addPackage(?array $vars = null)
    {
        return $this->packageMeta((array) $vars);
    }

    /**
     * Validates input data when attempting to edit a package and returns the
     * meta data to store.
     *
     * @param stdClass $package The package being edited
     * @param array $vars An array of key/value pairs used to edit the package
     * @return array Meta fields (key, value, encrypted)
     */
    public function editPackage($package, ?array $vars = null)
    {
        return $this->packageMeta((array) $vars);
    }

    private function packageMeta(array $vars)
    {
        $this->Input->setRules($this->getPackageRules($vars));

        $meta = [];
        if ($this->Input->validates($vars)) {
            $input = (isset($vars['meta']) && is_array($vars['meta'])) ? $vars['meta'] : [];
            // Unchecked checkboxes are not posted.
            foreach (['trial', 'delete_on_cancel'] as $box) {
                $input[$box] = (isset($input[$box]) && $input[$box] == 'true') ? 'true' : 'false';
            }
            foreach (self::$package_meta as $key) {
                $value = isset($input[$key]) ? trim((string) $input[$key]) : '';
                if (in_array($key, ['credits_on_creation', 'credits_per_renewal']) && $value === '') {
                    $value = '0';
                }
                $meta[] = ['key' => $key, 'value' => $value, 'encrypted' => 0];
            }
        }

        return $meta;
    }

    private function getPackageRules(array $vars)
    {
        $type = $vars['meta']['service_type'] ?? 'line';
        $rules = [
            'meta[service_type]' => [
                'valid' => [
                    'rule' => [[$this, 'validateServiceType']],
                    'message' => Language::_('Xtreampro.!error.meta[service_type].valid', true)
                ]
            ]
        ];

        if ($type === 'reseller') {
            foreach (['credits_on_creation', 'credits_per_renewal'] as $key) {
                $rules['meta[' . $key . ']'] = [
                    'valid' => [
                        'rule' => [[$this, 'validateCredits']],
                        'message' => Language::_('Xtreampro.!error.meta[' . $key . '].valid', true)
                    ]
                ];
            }
        } else {
            $rules['meta[package_id]'] = [
                'valid' => [
                    'rule' => ['matches', '/^[1-9][0-9]{0,9}$/'],
                    'message' => Language::_('Xtreampro.!error.meta[package_id].valid', true)
                ]
            ];
        }

        return $rules;
    }

    /**
     * Validates the service type of a package.
     *
     * @param string $type line or reseller
     * @return bool True when valid
     */
    public function validateServiceType($type)
    {
        return in_array($type, ['line', 'reseller'], true);
    }

    /**
     * Validates a credit amount: empty or a whole number of 0 to 999999999.
     *
     * @param string $value The amount
     * @return bool True when valid
     */
    public function validateCredits($value)
    {
        $value = trim((string) $value);

        return $value === '' || (ctype_digit($value) && strlen($value) <= 9);
    }

    /** id => label list of the panel's packages, empty when the panel cannot be reached. */
    private function getPanelPackageOptions($vars)
    {
        $options = ['' => Language::_('Xtreampro.package_fields.package_id_none', true)];

        $row = null;
        if (isset($vars->module_group) && $vars->module_group !== '' && $vars->module_group !== 'select') {
            $rows = $this->getModuleRows($vars->module_group);
            $row = $rows[0] ?? null;
        } elseif (isset($vars->module_row) && $vars->module_row > 0) {
            $row = $this->getModuleRow($vars->module_row);
        } else {
            $rows = $this->getModuleRows();
            $row = $rows[0] ?? null;
        }
        if (!$row) {
            return $options;
        }

        $api = $this->getApiFromRow($row);
        if (!$api) {
            return $options;
        }
        $packages = $this->run($api, 'packages', function ($api) {
            return $api->packages();
        }, [], true);

        foreach ((array) $packages as $pkg) {
            // Packages for MAG / Enigma boxes only cannot be sold as a line (a panel without `sells` lists all).
            if (!is_array($pkg) || !isset($pkg['id']) || !XtreamproApi::sellsLine($pkg)) {
                continue;
            }
            $name = isset($pkg['name']) ? (string) $pkg['name'] : ('#' . $pkg['id']);
            if (!empty($pkg['is_official'])) {
                $detail = ($pkg['official_credits'] ?? '?') . ' credits, ' . ($pkg['official_duration'] ?? '?') . ' '
                    . ($pkg['official_duration_in'] ?? '');
            } else {
                $detail = Language::_('Xtreampro.package_fields.trial_only', true);
            }
            $options[(string) $pkg['id']] = $name . ' (' . trim($detail) . ')';
        }

        return $options;
    }

    // ------------------------------------------------------------------
    // Module management pages (rows = panel servers)
    // ------------------------------------------------------------------

    /**
     * Returns the rendered view of the manage module page.
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars Post data submitted to the page
     * @return string HTML content
     */
    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'xtreampro' . DS);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page.
     *
     * @param array $vars Post data submitted to the page
     * @return string HTML content
     */
    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'xtreampro' . DS);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        // Set unspecified checkboxes
        if (!empty($vars) && empty($vars['use_ssl'])) {
            $vars['use_ssl'] = 'false';
        }

        $this->view->set('module', $this->getModuleRecord());
        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the edit module row page.
     *
     * @param stdClass $module_row The existing module row
     * @param array $vars Post data submitted to the page
     * @return string HTML content
     */
    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'xtreampro' . DS);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = (array) $module_row->meta;
            // The key is never sent back to the browser.
            unset($vars['api_key']);
        } elseif (empty($vars['use_ssl'])) {
            $vars['use_ssl'] = 'false';
        }

        $this->view->set('module', $this->getModuleRecord());
        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    private function getModuleRecord()
    {
        Loader::loadModels($this, ['ModuleManager']);
        $module = $this->ModuleManager->getByClass('xtreampro', Configure::get('Blesta.company_id'));

        return (object) ($module[0] ?? []);
    }

    /**
     * Adds the module row. Sets Input errors on failure.
     *
     * @param array $vars An array of module info to add
     * @return array Meta fields for the module row (key, value, encrypted)
     */
    public function addModuleRow(array &$vars)
    {
        return $this->rowMeta($vars);
    }

    /**
     * Edits the module row. An empty API key keeps the stored one.
     *
     * @param stdClass $module_row The existing module row
     * @param array $vars An array of module info to update
     * @return array Meta fields for the module row (key, value, encrypted)
     */
    public function editModuleRow($module_row, array &$vars)
    {
        if (!isset($vars['api_key']) || trim((string) $vars['api_key']) === '') {
            $vars['api_key'] = $module_row->meta->api_key ?? '';
        }

        return $this->rowMeta($vars);
    }

    private function rowMeta(array &$vars)
    {
        $meta_fields = ['server_name', 'host_name', 'port', 'use_ssl', 'api_key'];
        $encrypted_fields = ['api_key'];

        // Set unspecified checkboxes
        if (empty($vars['use_ssl'])) {
            $vars['use_ssl'] = 'false';
        }
        if (!isset($vars['port']) || trim((string) $vars['port']) === '') {
            $vars['port'] = '';
        }

        $this->Input->setRules($this->getRowRules($vars));

        if ($this->Input->validates($vars)) {
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => trim((string) $value),
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    private function getRowRules(array &$vars)
    {
        return [
            'server_name' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Xtreampro.!error.server_name_valid', true)
                ]
            ],
            'host_name' => [
                'valid' => [
                    'rule' => [[$this, 'validateHostName']],
                    'message' => Language::_('Xtreampro.!error.host_name_valid', true)
                ]
            ],
            'port' => [
                'valid' => [
                    'rule' => [[$this, 'validatePort']],
                    'message' => Language::_('Xtreampro.!error.port_valid', true)
                ]
            ],
            'api_key' => [
                'valid' => [
                    'last' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Xtreampro.!error.api_key_valid', true)
                ],
                'valid_connection' => [
                    'rule' => [
                        [$this, 'validateConnection'],
                        $vars['host_name'] ?? '',
                        $vars['port'] ?? '',
                        $vars['use_ssl'] ?? 'false'
                    ],
                    'message' => Language::_('Xtreampro.!error.api_key_valid_connection', true)
                ]
            ]
        ];
    }

    /**
     * Validates a host name or IP address (no scheme, no path).
     *
     * @param string $host_name The host
     * @return bool True when valid
     */
    public function validateHostName($host_name)
    {
        $host_name = (string) $host_name;

        return $host_name !== '' && strlen($host_name) <= 253
            && (bool) preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$|^\[[0-9A-Fa-f:.]+\]$/', $host_name);
    }

    /**
     * Validates the port: empty (default of the scheme) or 1 to 65535.
     *
     * @param string $port The port
     * @return bool True when valid
     */
    public function validatePort($port)
    {
        $port = trim((string) $port);

        return $port === '' || (ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535);
    }

    /**
     * Validates the connection details by asking the panel for the reseller's
     * info (the same check as "test connection").
     *
     * @param string $api_key The API key
     * @param string $host_name The panel host
     * @param string $port The panel port
     * @param string $use_ssl 'true' for https
     * @return bool True when the panel accepted the key
     */
    public function validateConnection($api_key, $host_name, $port, $use_ssl)
    {
        try {
            $api = $this->getApi($host_name, $port, $use_ssl, $api_key);
        } catch (XtreamproApiException $e) {
            return false;
        }

        $info = $this->run($api, 'user_info', function ($api) {
            return $api->userInfo();
        }, [], true);

        return $info !== null;
    }

    // ------------------------------------------------------------------
    // Service fields
    // ------------------------------------------------------------------

    /**
     * Returns the value used to identify a particular service.
     *
     * @param stdClass $service A stdClass object representing the service
     * @return string The panel username of the service
     */
    public function getServiceName($service)
    {
        foreach ($service->fields as $field) {
            if ($field->key == 'xtreampro_username') {
                return $field->value;
            }
        }

        return null;
    }

    /**
     * Returns the value used to identify a service that does not exist yet.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars User supplied info
     * @return string The requested username, if any
     */
    public function getPackageServiceName($package, ?array $vars = null)
    {
        return isset($vars['xtreampro_username']) && $vars['xtreampro_username'] !== ''
            ? $vars['xtreampro_username']
            : null;
    }

    /**
     * Fields an admin sees when adding a service.
     *
     * @param stdClass $package The selected package
     * @param stdClass $vars Post fields
     * @return ModuleFields The fields to render
     */
    public function getAdminAddFields($package, $vars = null)
    {
        $fields = new ModuleFields();
        $this->addCredentialFields($fields, $vars, true);

        return $fields;
    }

    /**
     * Fields a client sees when ordering a service.
     *
     * @param stdClass $package The selected package
     * @param stdClass $vars Post fields
     * @return ModuleFields The fields to render
     */
    public function getClientAddFields($package, $vars = null)
    {
        $fields = new ModuleFields();
        $this->addCredentialFields($fields, $vars, true);

        return $fields;
    }

    /**
     * Fields an admin sees when editing a service.
     *
     * @param stdClass $package The selected package
     * @param stdClass $vars Post fields
     * @return ModuleFields The fields to render
     */
    public function getAdminEditFields($package, $vars = null)
    {
        $fields = new ModuleFields();
        $this->addCredentialFields($fields, $vars, false);

        return $fields;
    }

    private function addCredentialFields(ModuleFields $fields, $vars, $with_username)
    {
        if ($with_username) {
            $username = $fields->label(Language::_('Xtreampro.service_field.username', true), 'xtreampro_username');
            $username->attach(
                $fields->fieldText(
                    'xtreampro_username',
                    ($vars->xtreampro_username ?? null),
                    ['id' => 'xtreampro_username']
                )
            );
            $username->attach($fields->tooltip(Language::_('Xtreampro.service_field.tooltip.username', true)));
            $fields->setField($username);
        }

        $password = $fields->label(Language::_('Xtreampro.service_field.password', true), 'xtreampro_password');
        $password->attach(
            $fields->fieldPassword(
                'xtreampro_password',
                ['id' => 'xtreampro_password', 'value' => null, 'autocomplete' => 'new-password']
            )
        );
        $password->attach($fields->tooltip(Language::_(
            $with_username ? 'Xtreampro.service_field.tooltip.password' : 'Xtreampro.service_field.tooltip.new_password',
            true
        )));
        $fields->setField($password);
    }

    /**
     * Validates service input. Sets Input errors on failure.
     *
     * @param stdClass $package The selected package
     * @param array $vars User supplied info
     * @return bool True when valid
     */
    public function validateService($package, ?array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules((array) $vars, $package));

        return $this->Input->validates((array) $vars);
    }

    /**
     * Validates an edit of an existing service. Sets Input errors on failure.
     *
     * @param stdClass $service The service
     * @param array $vars User supplied info
     * @return bool True when valid
     */
    public function validateServiceEdit($service, ?array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules((array) $vars, null, true));

        return $this->Input->validates((array) $vars);
    }

    private function getServiceRules(array $vars, $package = null, $edit = false)
    {
        $rules = [];
        $reseller = ($package !== null && $this->serviceType($package) === 'reseller');

        if (!empty($vars['xtreampro_username']) && $reseller) {
            $rules['xtreampro_username'] = [
                'format' => [
                    'rule' => ['matches', '/^[A-Za-z0-9._-]{3,32}$/'],
                    'message' => Language::_('Xtreampro.!error.xtreampro_username.reseller', true)
                ]
            ];
        } elseif (!empty($vars['xtreampro_username'])) {
            $rules['xtreampro_username'] = [
                'format' => [
                    'rule' => ['betweenLength', 1, 64],
                    'message' => Language::_('Xtreampro.!error.xtreampro_username.length', true)
                ]
            ];
        }
        if (!empty($vars['xtreampro_password'])) {
            $rules['xtreampro_password'] = [
                'format' => [
                    'rule' => ['betweenLength', 4, 128],
                    'message' => Language::_('Xtreampro.!error.xtreampro_password.length', true)
                ]
            ];
        }

        return $rules;
    }

    // ------------------------------------------------------------------
    // Provisioning
    // ------------------------------------------------------------------

    /**
     * Adds the service on the panel. Sets Input errors on failure, preventing
     * the service from being added.
     *
     * The request ids contain a per-service key and a generation counter: a
     * retry of the same service returns the first result instead of charging
     * twice, while a service created after a cancellation sells a new line.
     *
     * @param stdClass $package The package
     * @param array $vars User supplied info
     * @param stdClass $parent_package Parent package (addon services)
     * @param stdClass $parent_service Parent service (addon services)
     * @param string $status Status of the service being added
     * @return array Service fields (key, value, encrypted)
     */
    public function addService(
        $package,
        ?array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    ) {
        $vars = (array) $vars;
        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors(['module_row' => ['missing' => Language::_('Xtreampro.!error.module_row.missing', true)]]);

            return;
        }

        $this->validateService($package, $vars);
        if ($this->Input->errors()) {
            return;
        }

        $type = $this->serviceType($package);
        $username = trim((string) ($vars['xtreampro_username'] ?? ''));
        $password = (string) ($vars['xtreampro_password'] ?? '');
        $panel_id = '';
        $generation = max(0, (int) ($vars['xtreampro_generation'] ?? 0));
        $nonce = (isset($vars['xtreampro_nonce']) && preg_match('/^[a-f0-9]{12}$/', (string) $vars['xtreampro_nonce']))
            ? $vars['xtreampro_nonce']
            : bin2hex(random_bytes(6));

        // Only provision the service on the panel when "use module" is on.
        if (($vars['use_module'] ?? 'true') == 'true') {
            $api = $this->getApiFromRow($row);
            if (!$api) {
                $this->Input->setErrors(['module_row' => ['missing' => Language::_('Xtreampro.!error.module_row.missing', true)]]);

                return;
            }

            // The panel needs both values when a request id is sent, so blank
            // ones are generated here (CSPRNG). The panel's final values are
            // read back below: the reseller's group may ignore ours.
            if ($username === '') {
                $username = ($type === 'reseller' ? 'r' : 'xt') . $this->random(8, 'abcdefghijklmnopqrstuvwxyz0123456789');
            }
            if ($password === '') {
                $password = $this->random(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
            }

            if ($type === 'reseller') {
                $created = $this->createSubReseller($api, $package, $vars, $username, $password, $nonce, $generation);
            } else {
                $created = $this->createLine($api, $package, $username, $password, $nonce, $generation);
            }
            if ($this->Input->errors() || $created === null) {
                return;
            }
            list($panel_id, $username, $password) = $created;
        }

        return $this->serviceFields($username, $password, $panel_id, $nonce, $generation);
    }

    private function createLine($api, $package, $username, $password, $nonce, $generation)
    {
        $package_id = (int) $this->meta($package, 'package_id', 0);
        if ($package_id <= 0) {
            $this->Input->setErrors(['api' => ['response' => Language::_('Xtreampro.!error.package_missing', true)]]);

            return null;
        }
        $trial = ($this->meta($package, 'trial', 'false') === 'true');

        // Ask the panel first: too few credits or a package that cannot be sold fails here and nothing is created.
        $this->run($api, 'pricing', function ($api) use ($package_id, $trial) {
            $api->assertCanSellPackage($package_id, $trial, true);
        });
        if ($this->Input->errors()) {
            return null;
        }

        $data = $this->run($api, 'create_line', function ($api) use ($package_id, $trial, $username, $password, $nonce, $generation) {
            return $api->createLine($package_id, $trial, $username, $password, 'blesta-create-' . $nonce . '-' . $generation);
        });
        if ($this->Input->errors()) {
            return null;
        }

        $line = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : [];
        if (!isset($line['id'])) {
            $this->Input->setErrors(['api' => ['response' => $this->describe('BAD_RESPONSE')]]);

            return null;
        }
        $final_password = (string) ($line['password'] ?? '');
        if ($final_password === '' && isset($data['password'])) {
            $final_password = (string) $data['password'];
        }

        return [
            (string) $line['id'],
            (string) ($line['username'] ?? $username),
            $final_password !== '' ? $final_password : $password
        ];
    }

    private function createSubReseller($api, $package, array $vars, $username, $password, $nonce, $generation)
    {
        $username = substr($username, 0, 32);
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
            $this->Input->setErrors(['xtreampro_username' => ['format' => Language::_('Xtreampro.!error.xtreampro_username.reseller', true)]]);

            return null;
        }

        $email = '';
        $fullname = '';
        if (!empty($vars['client_id'])) {
            Loader::loadModels($this, ['Clients']);
            $client = $this->Clients->get($vars['client_id'], false);
            if ($client) {
                $email = (string) ($client->email ?? '');
                $fullname = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
            }
        }

        $this->run($api, 'pricing', function ($api) use ($package) {
            $api->assertCanCreateSubUser($this->credits($this->meta($package, 'credits_on_creation', '0')));
        });
        if ($this->Input->errors()) {
            return null;
        }

        $data = $this->run($api, 'create_user', function ($api) use ($username, $password, $email, $fullname, $nonce, $generation) {
            return $api->createUser($username, $password, $email, $fullname, 'blesta-sub-' . $nonce . '-' . $generation);
        });
        if ($this->Input->errors()) {
            return null;
        }
        $user = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : [];
        if (!isset($user['id'])) {
            $this->Input->setErrors(['api' => ['response' => $this->describe('BAD_RESPONSE')]]);

            return null;
        }
        $user_id = (string) $user['id'];
        if (!empty($user['username'])) {
            $username = (string) $user['username'];
        }
        if (!empty($data['password'])) {
            $password = (string) $data['password'];
        }

        $credits = $this->credits($this->meta($package, 'credits_on_creation', '0'));
        if ($credits > 0) {
            $this->run($api, 'adjust_credits', function ($api) use ($user_id, $credits, $nonce, $generation) {
                return $api->adjustCredits($user_id, $credits, 'Blesta new service', 'blesta-subc-' . $nonce . '-' . $generation);
            }, [], false, function ($message) use ($username) {
                return Language::_('Xtreampro.!error.credits_failed', true, $username, $message);
            });
            if ($this->Input->errors()) {
                return null;
            }
        }

        return [$user_id, $username, $password];
    }

    /**
     * Edits the service. Only the password can be changed: it is sent to the
     * panel and the values the panel keeps are stored.
     *
     * @param stdClass $package The current package
     * @param stdClass $service The current service
     * @param array $vars User supplied info
     * @param stdClass $parent_package Parent package (addon services)
     * @param stdClass $parent_service Parent service (addon services)
     * @return array Service fields (key, value, encrypted)
     */
    public function editService($package, $service, ?array $vars = null, $parent_package = null, $parent_service = null)
    {
        $vars = (array) $vars;
        $fields = $this->serviceFieldsToObject($service->fields);

        $this->validateServiceEdit($service, $vars);
        if ($this->Input->errors()) {
            return;
        }

        $password = (string) ($fields->xtreampro_password ?? '');
        $username = (string) ($fields->xtreampro_username ?? '');
        $new_password = (string) ($vars['xtreampro_password'] ?? '');

        if (($vars['use_module'] ?? 'true') == 'true' && $new_password !== '' && $new_password !== $password) {
            $api = $this->getApiFromRow($this->getModuleRow());
            if (!$api) {
                $this->Input->setErrors(['module_row' => ['missing' => Language::_('Xtreampro.!error.module_row.missing', true)]]);

                return;
            }
            $id = $this->requirePanelId($api, $package, $fields);
            if ($this->Input->errors()) {
                return;
            }
            if ($this->serviceType($package) === 'reseller') {
                $this->run($api, 'edit_user', function ($api) use ($id, $new_password) {
                    return $api->editUser($id, ['password' => $new_password]);
                });
            } else {
                $this->run($api, 'edit_line', function ($api) use ($id, $new_password) {
                    return $api->editLine($id, ['password' => $new_password]);
                });
            }
            if ($this->Input->errors()) {
                return;
            }
            $password = $new_password;

            // A line's group may refuse the change: keep what the panel has.
            if ($this->serviceType($package) !== 'reseller') {
                $line = $this->run($api, 'get_line', function ($api) use ($id) {
                    return $api->getLine($id);
                }, [], true);
                if (is_array($line) && isset($line['password']) && $line['password'] !== '') {
                    $password = (string) $line['password'];
                }
            }
        } elseif ($new_password !== '') {
            $password = $new_password;
        }

        return $this->serviceFields(
            $username,
            $password,
            (string) ($fields->xtreampro_panel_id ?? ''),
            (string) ($fields->xtreampro_nonce ?? ''),
            (int) ($fields->xtreampro_generation ?? 0)
        );
    }

    /**
     * Suspends the service on the panel (the line / account is disabled).
     *
     * @param stdClass $package The current package
     * @param stdClass $service The current service
     * @param stdClass $parent_package Parent package (addon services)
     * @param stdClass $parent_service Parent service (addon services)
     * @return null Keeps the stored fields
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $this->toggle($package, $service, false);

        return null;
    }

    /**
     * Unsuspends the service on the panel (the line / account is enabled).
     *
     * @param stdClass $package The current package
     * @param stdClass $service The current service
     * @param stdClass $parent_package Parent package (addon services)
     * @param stdClass $parent_service Parent service (addon services)
     * @return null Keeps the stored fields
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $this->toggle($package, $service, true);

        return null;
    }

    private function toggle($package, $service, $enable)
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return;
        }
        $api = $this->getApiFromRow($row);
        if (!$api) {
            return;
        }
        $fields = $this->serviceFieldsToObject($service->fields);
        $id = $this->requirePanelId($api, $package, $fields);
        if ($this->Input->errors()) {
            return;
        }

        if ($this->serviceType($package) === 'reseller') {
            $action = $enable ? 'enable_user' : 'disable_user';
            $this->run($api, $action, function ($api) use ($action, $id) {
                return $api->userAction($action, $id);
            });
        } else {
            $action = $enable ? 'enable_line' : 'disable_line';
            $this->run($api, $action, function ($api) use ($action, $id) {
                return $api->lineAction($action, $id);
            });
        }
    }

    /**
     * Cancels the service: the line / account is deleted (final) or only
     * disabled, as the package says. One that is already gone counts as done.
     * After a deletion the service fields lose the panel id and the generation
     * goes up, so creating the service again sells a new line.
     *
     * @param stdClass $package The current package
     * @param stdClass $service The current service
     * @param stdClass $parent_package Parent package (addon services)
     * @param stdClass $parent_service Parent service (addon services)
     * @return mixed null to keep the fields, or the new service fields
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return null;
        }
        $api = $this->getApiFromRow($row);
        if (!$api) {
            return null;
        }
        $fields = $this->serviceFieldsToObject($service->fields);
        $reseller = ($this->serviceType($package) === 'reseller');
        $delete = ($this->meta($package, 'delete_on_cancel', 'false') === 'true');

        $id = $this->resolvePanelId($api, $package, $fields);
        if ($this->Input->errors()) {
            return null;
        }
        if ($id === null) {
            // Nothing on the panel: already terminated.
            return $this->closedFields($fields);
        }

        if ($reseller) {
            $action = $delete ? 'delete_user' : 'disable_user';
            $this->run($api, $action, function ($api) use ($action, $id) {
                return $api->userAction($action, $id);
            }, ['RESOURCE_NOT_FOUND']);
        } else {
            $action = $delete ? 'delete_line' : 'disable_line';
            $this->run($api, $action, function ($api) use ($action, $id) {
                return $api->lineAction($action, $id);
            }, ['RESOURCE_NOT_FOUND']);
        }
        if ($this->Input->errors()) {
            return null;
        }

        return $delete ? $this->closedFields($fields) : null;
    }

    /**
     * Upgrade / downgrade: Blesta calls this when the package of a service is changed. The new package is sold
     * on the panel's line (`change_package`, charged to the reseller). The panel is asked first
     * (`package_compatibility`): too few credits, or a package that cannot be sold as a line, refuse the change
     * before anything is sold, and what the change does to the time left goes to the module log. A panel that does
     * not know the action is handled as before: the sale itself is then decided by the panel.
     * Sub-reseller accounts have no package on the panel, so only the Blesta package changes.
     *
     * @param stdClass $package_from The package the service had
     * @param stdClass $package_to The package the service gets
     * @param stdClass $service The service
     * @param stdClass $parent_package Parent package (addon services)
     * @param stdClass $parent_service Parent service (addon services)
     * @return null Keeps the stored fields
     */
    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        if ($this->serviceType($package_to) === 'reseller' || $this->serviceType($package_from) === 'reseller') {
            return null;
        }
        $to = (int) $this->meta($package_to, 'package_id', 0);
        $row = $this->getModuleRow();
        $api = $row ? $this->getApiFromRow($row) : null;
        if (!$api) {
            return null;
        }
        if ($to <= 0) {
            $this->Input->setErrors(['api' => ['response' => Language::_('Xtreampro.!error.package_missing', true)]]);

            return null;
        }
        $fields = $this->serviceFieldsToObject($service->fields);
        $id = $this->requirePanelId($api, $package_to, $fields);
        if ($this->Input->errors()) {
            return null;
        }

        $line = $this->run($api, 'get_line', function ($api) use ($id) {
            return $api->getLine((int) $id);
        });
        if ($this->Input->errors() || !is_array($line)) {
            return null;
        }
        if ((int) ($line['package_id'] ?? 0) === $to) {
            // Already on that package (a repeated call after the sale): nothing to sell.
            return null;
        }

        $this->run($api, 'pricing', function ($api) use ($to) {
            $api->assertCanSellPackage($to, false, true);
        });
        if ($this->Input->errors()) {
            return null;
        }
        $compat = $this->run($api, 'package_compatibility', function ($api) use ($id, $to) {
            return $api->packageCompatibility((int) $id, $to);
        });
        if ($this->Input->errors()) {
            return null;
        }
        if (is_array($compat)) {
            $this->log($api->baseUrl() . '|package_compatibility', XtreamproApi::describeCompatibility($compat), 'output', true);
            if (empty($compat['can_afford'])) {
                $this->Input->setErrors(['api' => ['response' => $this->describe(
                    'INSUFFICIENT_CREDITS',
                    sprintf('The change costs %d credits.', (int) ($compat['price'] ?? 0))
                )]]);

                return null;
            }
        }

        // One request id per line state: a retry of the same change is not charged twice.
        $request = 'blesta-chg-' . (string) ($fields->xtreampro_nonce ?? '') . '-' . $to . '-' . (int) ($line['exp_date'] ?? 0);
        $this->run($api, 'change_package', function ($api) use ($id, $to, $request) {
            return $api->changePackage((int) $id, $to, $request);
        });

        return null;
    }

    /**
     * Renews the service. Blesta calls this when a renewal is paid: an IPTV line
     * is extended by its package's duration (charged to the reseller), a
     * sub-reseller account gets the package's "credits per renewal".
     *
     * The request id is made of the service id and the renewal date, so a retry
     * of the same renewal is not charged twice.
     *
     * @param stdClass $package The current package
     * @param stdClass $service The current service
     * @param stdClass $parent_package Parent package (addon services)
     * @param stdClass $parent_service Parent service (addon services)
     * @return null Keeps the stored fields
     */
    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return null;
        }
        $fields = $this->serviceFieldsToObject($service->fields);
        $stamp = $this->renewStamp($service);

        if ($this->serviceType($package) === 'reseller') {
            $credits = $this->credits($this->meta($package, 'credits_per_renewal', '0'));
            if ($credits <= 0) {
                return null;
            }
            $api = $this->getApiFromRow($row);
            if (!$api) {
                return null;
            }
            $id = $this->requirePanelId($api, $package, $fields);
            if ($this->Input->errors()) {
                return null;
            }
            $this->run($api, 'pricing', function ($api) use ($credits) {
                $api->assertCanGiveCredits($credits);
            });
            if ($this->Input->errors()) {
                return null;
            }
            $this->run($api, 'adjust_credits', function ($api) use ($id, $credits, $service, $stamp) {
                return $api->adjustCredits($id, $credits, 'Blesta renewal, service #' . $service->id, 'blesta-subr-' . $service->id . '-' . $stamp);
            });

            return null;
        }

        $api = $this->getApiFromRow($row);
        if (!$api) {
            return null;
        }
        $id = $this->requirePanelId($api, $package, $fields);
        if ($this->Input->errors()) {
            return null;
        }
        $package_id = (int) $this->meta($package, 'package_id', 0);
        if ($package_id > 0) {
            // A renewal sells an official period of the package.
            $this->run($api, 'pricing', function ($api) use ($package_id) {
                $api->assertCanSellPackage($package_id, false, false);
            });
            if ($this->Input->errors()) {
                return null;
            }
        }
        $this->run($api, 'renew_line', function ($api) use ($id, $service, $stamp) {
            return $api->renewLine($id, 'blesta-renew-' . $service->id . '-' . $stamp);
        });

        return null;
    }

    // ------------------------------------------------------------------
    // Admin / client display
    // ------------------------------------------------------------------

    /**
     * HTML shown to an admin on the service page.
     *
     * @param stdClass $service The service
     * @param stdClass $package The service's package
     * @return string HTML content
     */
    public function getAdminServiceInfo($service, $package)
    {
        return $this->renderInfo('admin_service_info', $service, $package);
    }

    /**
     * HTML shown to a client on the service page.
     *
     * @param stdClass $service The service
     * @param stdClass $package The service's package
     * @return string HTML content
     */
    public function getClientServiceInfo($service, $package)
    {
        return $this->renderInfo('client_service_info', $service, $package);
    }

    /**
     * Client tab: credentials and play links (line) or credit balance (sub-reseller).
     *
     * @param stdClass $package The current package
     * @param stdClass $service The current service
     * @param array $get GET parameters
     * @param array $post POST parameters
     * @param array $files FILES parameters
     * @return string HTML content of the tab
     */
    public function tabClientAccount($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->renderInfo('tab_client_account', $service, $package);
    }

    private function renderInfo($template, $service, $package)
    {
        $row = $this->getModuleRow();

        $this->view = new View($template, 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'xtreampro' . DS);

        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = $this->serviceFieldsToObject($service->fields);
        $info = null;
        $error = '';
        if ($row && ($api = $this->getApiFromRow($row))) {
            try {
                $info = $this->liveInfo($api, $package, $fields);
                $this->logCall($api, 'info', true);
            } catch (XtreamproApiException $e) {
                $this->logCall($api, 'info', false);
                $error = $this->describe($e->getErrorCode(), $e->getDetail());
            }
        }

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $fields);
        $this->view->set('type', $this->serviceType($package));
        $this->view->set('info', $info);
        $this->view->set('error', $error);
        $this->view->set('expiry', ($info !== null && isset($info['exp_date'])) ? $this->formatExpiry($info['exp_date']) : '');

        return $this->view->fetch();
    }

    /** Current panel data of the service's line / account, or null when it has none yet. */
    private function liveInfo($api, $package, $fields)
    {
        $stored = (string) ($fields->xtreampro_panel_id ?? '');
        $username = (string) ($fields->xtreampro_username ?? '');

        if ($this->serviceType($package) === 'reseller') {
            if ($stored !== '') {
                return $api->getUser($stored);
            }

            return $username !== '' ? $api->findUserByUsername($username) : null;
        }
        if ($stored !== '') {
            return $api->getLine((int) $stored);
        }
        if ($username !== '' && ($line = $api->findLineByUsername($username)) && isset($line['id'])) {
            return $api->getLine((int) $line['id']);
        }

        return null;
    }

    private function formatExpiry($exp)
    {
        if ($exp === null || $exp === '' || (int) $exp <= 0) {
            return Language::_('Xtreampro.service_info.never_expires', true);
        }

        return gmdate('Y-m-d H:i', (int) $exp) . ' UTC';
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** line (default) or reseller. */
    private function serviceType($package)
    {
        return $this->meta($package, 'service_type', 'line') === 'reseller' ? 'reseller' : 'line';
    }

    private function meta($package, $key, $default = null)
    {
        if (isset($package->meta) && isset($package->meta->$key) && $package->meta->$key !== '') {
            return $package->meta->$key;
        }

        return $default;
    }

    private function credits($value)
    {
        $value = trim((string) $value);

        return ($value !== '' && ctype_digit($value) && strlen($value) <= 9) ? (int) $value : 0;
    }

    /** Renewal date of the service as Ymd (UTC) for request ids; today when unknown. */
    private function renewStamp($service)
    {
        $date = isset($service->date_renews) ? (string) $service->date_renews : '';
        if ($date !== '' && substr($date, 0, 4) !== '0000' && ($time = strtotime($date . ' UTC')) !== false) {
            return gmdate('Ymd', $time);
        }

        return gmdate('Ymd');
    }

    private function random($length, $alphabet)
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    private function serviceFields($username, $password, $panel_id, $nonce, $generation)
    {
        return [
            ['key' => 'xtreampro_username', 'value' => (string) $username, 'encrypted' => 0],
            ['key' => 'xtreampro_password', 'value' => (string) $password, 'encrypted' => 1],
            ['key' => 'xtreampro_panel_id', 'value' => (string) $panel_id, 'encrypted' => 0],
            ['key' => 'xtreampro_nonce', 'value' => (string) $nonce, 'encrypted' => 0],
            ['key' => 'xtreampro_generation', 'value' => (string) $generation, 'encrypted' => 0]
        ];
    }

    /** Fields of a service whose line was deleted: no panel id, next generation. */
    private function closedFields($fields)
    {
        return $this->serviceFields(
            (string) ($fields->xtreampro_username ?? ''),
            (string) ($fields->xtreampro_password ?? ''),
            '',
            (string) ($fields->xtreampro_nonce ?? ''),
            (int) ($fields->xtreampro_generation ?? 0) + 1
        );
    }

    /**
     * Panel id (line id or account UUID) of a service: the stored one,
     * otherwise looked up by exact username. Null when there is none.
     */
    private function resolvePanelId($api, $package, $fields)
    {
        $stored = trim((string) ($fields->xtreampro_panel_id ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $username = trim((string) ($fields->xtreampro_username ?? ''));
        if ($username === '') {
            return null;
        }

        $found = $this->run($api, 'find', function ($api) use ($package, $username) {
            return $this->serviceType($package) === 'reseller'
                ? $api->findUserByUsername($username)
                : $api->findLineByUsername($username);
        });

        return (is_array($found) && isset($found['id'])) ? (string) $found['id'] : null;
    }

    private function requirePanelId($api, $package, $fields)
    {
        $id = $this->resolvePanelId($api, $package, $fields);
        if ($id === null && !$this->Input->errors()) {
            $this->Input->setErrors(['api' => ['response' => $this->describe('RESOURCE_NOT_FOUND')]]);
        }

        return $id;
    }

    /** API client from a module row. Null when the row is not usable. */
    private function getApiFromRow($row)
    {
        if (!$row || !isset($row->meta)) {
            return null;
        }
        try {
            return $this->getApi(
                $row->meta->host_name ?? '',
                $row->meta->port ?? '',
                $row->meta->use_ssl ?? 'true',
                $row->meta->api_key ?? ''
            );
        } catch (XtreamproApiException $e) {
            return null;
        }
    }

    private function getApi($host_name, $port, $use_ssl, $api_key)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'xtreampro_api.php');

        return new XtreamproApi($host_name, $port, ($use_ssl === 'true' || $use_ssl === true || $use_ssl === '1'), $api_key);
    }

    /**
     * Runs one API call: logs it (key never, line passwords masked) and turns an
     * API error into an Input error.
     *
     * @param XtreamproApi $api The client
     * @param string $name Name of the call, for the log
     * @param callable $call Receives the client, returns the data
     * @param array $tolerate Error codes that count as success (returns null)
     * @param bool $quiet True to only log: no Input error, the result is null
     * @param callable|null $format Turns the readable message into the Input error text
     * @return mixed The data, or null on an error
     */
    private function run($api, $name, callable $call, array $tolerate = [], $quiet = false, $format = null)
    {
        try {
            $result = $call($api);
            $this->logCall($api, $name, true);

            return $result;
        } catch (XtreamproApiException $e) {
            $tolerated = in_array($e->getErrorCode(), $tolerate, true);
            $this->logCall($api, $name, $tolerated);
            if (!$tolerated && !$quiet) {
                $message = $this->describe($e->getErrorCode(), $e->getDetail());
                $this->Input->setErrors(['api' => ['response' => $format ? $format($message) : $message]]);
            }

            return null;
        }
    }

    private function logCall($api, $name, $success)
    {
        $host = $api->baseUrl();
        if ($api->lastRequest !== null) {
            $this->log($host . '|' . $name, serialize($api->lastRequest), 'input', true);
        }
        if ($api->lastResponse !== null) {
            $this->log($host, serialize($api->lastResponse), 'output', $success);
        }
    }

    /** Readable text of an API (or client side) error code. */
    private function describe($code, $detail = '')
    {
        $known = [
            'INVALID_API_KEY', 'FORBIDDEN', 'RESOURCE_NOT_FOUND', 'INVALID_REQUEST', 'INVALID_PACKAGE',
            'INSUFFICIENT_CREDITS', 'CONFLICT', 'REQUEST_ID_SPENT', 'READ_ONLY_KEY', 'POST_REQUIRED', 'RATE_LIMITED', 'UNKNOWN_ACTION', 'SERVER_ERROR',
            'CONNECTION_FAILED', 'BAD_RESPONSE', 'CONFIG'
        ];
        if (in_array($code, $known, true)) {
            $text = Language::_('Xtreampro.!error.api.' . $code, true);

            return $detail === '' ? $text : $text . ' ' . $detail;
        }

        return Language::_('Xtreampro.!error.api.unknown', true, $code);
    }
}
