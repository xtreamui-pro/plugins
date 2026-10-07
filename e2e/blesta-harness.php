<?php
// Stand-in for the parts of Blesta the Xtream UI Pro module touches, to run the
// real module (and its real views and API client) against a panel API.
// Blesta is commercial and needs a licence, so it is not started. Not shipped.
//
//   MODULE_DIR=plugins/blesta/components/modules/xtreampro API_PORT_NUM=18095 API_KEY=... \
//     php plugins/e2e/blesta-harness.php
error_reporting(E_ALL);

define('DS', DIRECTORY_SEPARATOR);
$GLOBALS['H'] = array('log' => array(), 'lang' => array(), 'missing' => array(), 'row' => null, 'warnings' => array());
set_error_handler(function ($no, $str, $file, $line) {
    $GLOBALS['H']['warnings'][] = "$str ($file:$line)";
    return true;
});

// ---- Blesta classes ----------------------------------------------------------
class Input
{
    private $rules = array();
    private $errors = array();
    function setRules(array $r) { $this->rules = $r; $this->errors = array(); }
    function setErrors(array $e) { $this->errors = array_merge($this->errors, $e); }
    function errors() { return $this->errors ?: false; }
    private function lookup(array $vars, $name)
    {
        if (preg_match('/^(\w+)\[(\w+)\]$/', $name, $m)) {
            return array(isset($vars[$m[1]][$m[2]]) || (isset($vars[$m[1]]) && array_key_exists($m[2], (array) $vars[$m[1]])), $vars[$m[1]][$m[2]] ?? null);
        }
        return array(array_key_exists($name, $vars), $vars[$name] ?? null);
    }
    function validates(array $vars = array())
    {
        $this->errors = array();
        foreach ($this->rules as $field => $set) {
            list($isset, $value) = $this->lookup($vars, $field);
            foreach ($set as $name => $rule) {
                if (!empty($rule['if_set']) && !$isset) { continue; }
                $r = $rule['rule'];
                if (is_string($r)) { $r = array($r); }
                $fn = array_shift($r);
                if (is_string($fn)) {
                    switch ($fn) {
                        case 'isEmpty': $ok = ($value === null || $value === '' || $value === false); break;
                        case 'matches': $ok = (bool) preg_match($r[0], (string) $value); break;
                        case 'betweenLength': $ok = strlen((string) $value) >= $r[0] && strlen((string) $value) <= $r[1]; break;
                        default: throw new Exception('Input rule not in the stand-in: ' . $fn);
                    }
                } else {
                    $ok = call_user_func_array($fn, array_merge(array($value), $r));
                }
                if (!empty($rule['negate'])) { $ok = !$ok; }
                if (!$ok) {
                    $this->errors[$field][$name] = $rule['message'];
                    if (!empty($rule['last'])) { break; }
                } 
            }
        }
        return !$this->errors;
    }
}
class Configure { static function get($k) { return 1; } }
class Language
{
    static function loadLang($name, $lang, $dir)
    {
        $lang = array();
        include rtrim($dir, '/') . '/en_us/' . $name . '.php';
        $GLOBALS['H']['lang'] = $lang;
    }
    static function _($key, $return = false)
    {
        $args = array_slice(func_get_args(), 2);
        if (!isset($GLOBALS['H']['lang'][$key]) && strpos($key, 'AdminCompanyModules.') === 0) { $GLOBALS['H']['lang'][$key] = 'Manage %1$s'; }
        if (!isset($GLOBALS['H']['lang'][$key])) { $GLOBALS['H']['missing'][$key] = true; $text = $key; }
        else { $text = $args ? vsprintf($GLOBALS['H']['lang'][$key], $args) : $GLOBALS['H']['lang'][$key]; }
        if ($return) { return $text; }
        echo $text;
    }
}
class FormHelper
{
    function __call($m, $a) { echo "<!--form:$m-->"; return ''; }
}
class HtmlHelper { function safe($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
class WidgetHelper { function __call($m, $a) { return ''; } }
#[AllowDynamicProperties]
class View
{
    public $base_uri = '/';
    private $vars = array();
    private $name;
    function __construct($name, $dir) { $this->name = $name; }
    function setDefaultView($p) {}
    function set($k, $v = null) { if (is_array($k)) { $this->vars = $k + $this->vars; } else { $this->vars[$k] = $v; } }
    function _($key, $return = false) { return call_user_func_array(array('Language', '_'), func_get_args()); }
    function fetch()
    {
        extract($this->vars);
        ob_start();
        include getenv('MODULE_DIR') . '/views/default/' . $this->name . '.pdt';
        return ob_get_clean();
    }
}
class Loader
{
    static function load($file) { require_once $file; }
    static function loadComponents($o, array $c) { foreach ($c as $n) { $o->$n = new $n(); } }
    static function loadHelpers($o, array $h)
    {
        foreach ($h as $n) {
            $cls = $n . 'Helper';
            $target = ($o->view instanceof View) ? $o->view : $o;
            $target->$n = new $cls();
        }
    }
    static function loadModels($o, array $m)
    {
        foreach ($m as $n) {
            $o->$n = $GLOBALS['H']['models'][$n];
        }
    }
}
class MFLabel { public $text; public $for; public $attached = array(); function __construct($t, $f) { $this->text = $t; $this->for = $f; } function attach($x) { $this->attached[] = $x; } }
class ModuleFields
{
    public $fields = array();
    public $html = '';
    function label($t, $f = null) { return new MFLabel($t, $f); }
    function fieldText($n, $v = null, $a = array()) { return array('type' => 'text', 'name' => $n, 'value' => $v); }
    function fieldPassword($n, $a = array()) { return array('type' => 'password', 'name' => $n, 'attrs' => $a); }
    function fieldSelect($n, $o, $s = null, $a = array()) { return array('type' => 'select', 'name' => $n, 'options' => $o, 'selected' => $s); }
    function fieldCheckbox($n, $v, $c = false, $a = array(), $l = null) { return array('type' => 'checkbox', 'name' => $n, 'checked' => $c); }
    function tooltip($t) { return array('type' => 'tooltip', 'text' => $t); }
    function setField($l) { $this->fields[] = $l; }
    function setHtml($h) { $this->html .= $h; }
    function find($name)
    {
        foreach ($this->fields as $l) { foreach ($l->attached as $f) { if (($f['name'] ?? null) === $name) { return $f; } } }
        return null;
    }
}
#[AllowDynamicProperties]
class Module
{
    public $Input;
    public $view;
    public $base_uri = '/';
    public $config;
    function loadConfig($file) { $this->config = json_decode(file_get_contents($file)); }
    function log($url, $data = null, $direction = 'input', $success = false) { $GLOBALS['H']['log'][] = array($url, $data, $direction, $success); }
    function getModuleRow($id = null) { return $GLOBALS['H']['row']; }
    function getModuleRows($group = null) { return $GLOBALS['H']['row'] ? array($GLOBALS['H']['row']) : array(); }
    function serviceFieldsToObject(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $f) { $o->{$f->key} = $f->value; }
        return $o;
    }
}
class FakeClients { public $c; function get($id, $x = true) { return $GLOBALS['H']['client']; } }
class FakeModuleManager
{
    function getByClass($c, $company) { return array(array('id' => 7, 'name' => 'Xtream UI Pro')); }
    function getGroup($id) { return (object) array('rows' => array((object) array('id' => 5))); }
}
$GLOBALS['H']['models'] = array('Clients' => new FakeClients(), 'ModuleManager' => new FakeModuleManager());

// ---- panel access of the harness itself (independent of the module) ----------------
function panel($method, $action, array $params = array())
{
    $url = 'http://127.0.0.1:' . getenv('API_PORT_NUM') . '/reseller/v1';
    $ch = curl_init();
    $params = array('action' => $action) + $params;
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } else {
        $url .= '?' . http_build_query($params);
    }
    curl_setopt_array($ch, array(CURLOPT_URL => $url, CURLOPT_HTTPHEADER => array('X-API-Key: ' . getenv('API_KEY')), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
    $j = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $j ?: array('status' => 'STATUS_FAILURE', 'error' => 'NO_RESPONSE');
}
function panelData($action, array $q = array())
{
    $j = panel('GET', $action, $q);
    return $j['status'] === 'STATUS_SUCCESS' ? $j['data'] : $j['error'];
}

require getenv('MODULE_DIR') . '/xtreampro.php';
require_once getenv('MODULE_DIR') . '/apis/xtreampro_api.php';

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    if (!$ok) { $fail++; }
};
$run = substr(bin2hex(random_bytes(4)), 0, 8);
$apiKey = getenv('API_KEY');
$goodRow = (object) array('id' => 3, 'meta' => (object) array(
    'server_name' => 'test', 'host_name' => '127.0.0.1', 'port' => getenv('API_PORT_NUM'), 'use_ssl' => 'false', 'api_key' => $apiKey,
));
$GLOBALS['H']['row'] = $goodRow;
$m = new Xtreampro();
$errs = function () use ($m) { $e = $m->Input->errors(); return $e ? json_encode($e) : ''; };
$toFields = function ($arr) { $o = array(); foreach ((array) $arr as $f) { $o[] = (object) $f; } return $o; };
$cleanup = array();

echo "== module definition\n";
$cfg = json_decode(file_get_contents(getenv('MODULE_DIR') . '/config.json'), true);
$check('config.json: version 1.1.0, authors, row key, email tags', $cfg['version'] === '1.1.0' && !empty($cfg['authors']) && $cfg['module']['row_key'] === 'server_name' && !empty($cfg['email_tags']['service']), $cfg);
$check('getVersion 1.1.0, in step with the API client that is sent as X-Connector', $m->getVersion() === '1.1.0' && XtreamproApi::VERSION === '1.1.0');
$check('language has the module name and description', isset($GLOBALS['H']['lang']['Xtreampro.name'], $GLOBALS['H']['lang']['Xtreampro.description']));
$check('getEmailTags', isset($m->getEmailTags()['service']) && in_array('xtreampro_username', $m->getEmailTags()['service']));
$check('getClientTabs', array_keys($m->getClientTabs(null)) === array('tabClientAccount'));
$check('selectModuleRow', $m->selectModuleRow(1) === 5);

echo "== panel (module row) pages and validation\n";
$vars = array();
$check('manageModule renders', strlen($m->manageModule((object) array('id' => 7, 'name' => 'X <b>', 'rows' => array($goodRow), 'groups' => array()), $vars)) > 100);
$html = $m->manageModule((object) array('id' => 7, 'name' => 'X', 'rows' => array((object) array('id' => 1, 'meta' => (object) array('server_name' => '<script>alert(1)</script>', 'host_name' => 'h"x'))), 'groups' => array()), $vars);
$check('manageModule escapes row values', strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;') !== false);
$v = array();
$check('manageAddRow renders', strlen($m->manageAddRow($v)) > 100);
$v = array();
$ed = $m->manageEditRow($goodRow, $v);
$check('manageEditRow renders and never sends the API key back', strlen($ed) > 100 && strpos($ed, $apiKey) === false);
$bad = array('server_name' => 'x', 'host_name' => 'http://evil/x', 'port' => '99999', 'use_ssl' => '', 'api_key' => 'k');
$r = $m->addModuleRow($bad);
$check('addModuleRow refuses a bad host name and port', $r === null && isset($m->Input->errors()['host_name'], $m->Input->errors()['port']), $errs());
$bad = array('server_name' => 'x', 'host_name' => '127.0.0.1', 'port' => getenv('API_PORT_NUM'), 'use_ssl' => '', 'api_key' => 'xk_wrong_key');
$r = $m->addModuleRow($bad);
$check('addModuleRow with a wrong key fails the connection test', $r === null && isset($m->Input->errors()['api_key']['valid_connection']), $errs());
$good = array('server_name' => 'test', 'host_name' => '127.0.0.1', 'port' => getenv('API_PORT_NUM'), 'use_ssl' => '', 'api_key' => $apiKey, 'other' => 'ignored');
$meta = $m->addModuleRow($good);
$byKey = array();
foreach ((array) $meta as $f) { $byKey[$f['key']] = $f; }
$check('addModuleRow stores the row, API key encrypted, nothing else', is_array($meta) && isset($byKey['api_key']) && $byKey['api_key']['encrypted'] === 1 && $byKey['use_ssl']['value'] === 'false' && !isset($byKey['other']) && count($byKey) === 5, $meta ?: $errs());
$ev = array('server_name' => 'renamed', 'host_name' => '127.0.0.1', 'port' => getenv('API_PORT_NUM'), 'use_ssl' => '', 'api_key' => '');
$meta = $m->editModuleRow($goodRow, $ev);
$byKey = array();
foreach ((array) $meta as $f) { $byKey[$f['key']] = $f; }
$check('editModuleRow with an empty key keeps the stored key', isset($byKey['api_key']) && $byKey['api_key']['value'] === $apiKey, $errs());

echo "== package fields\n";
$pf = $m->getPackageFields((object) array('meta' => array(), 'module_group' => '', 'module_row' => 3));
$sel = $pf->find('meta[package_id]');
$check('panel package dropdown is loaded from the panel', $sel && count($sel['options']) >= 2, $sel);
$check('package fields: type, trial, delete, credits', $pf->find('meta[service_type]') && $pf->find('meta[trial]') && $pf->find('meta[delete_on_cancel]') && $pf->find('meta[credits_on_creation]') && $pf->find('meta[credits_per_renewal]'));
$pkgId = '';
foreach ($sel['options'] as $k => $label) { if ($k !== '' && strpos($label, 'trial only') === false) { $pkgId = (string) $k; break; } }
$GLOBALS['H']['row'] = null;
$pf2 = $m->getPackageFields((object) array('meta' => array()));
$check('package fields still render without a panel', count($pf2->find('meta[package_id]')['options']) === 1);
$GLOBALS['H']['row'] = $goodRow;
$pm = $m->addPackage(array('meta' => array('service_type' => 'line', 'package_id' => 'abc')));
$check('addPackage refuses a line package without a panel package', $pm === array() && isset($m->Input->errors()['meta[package_id]']), $errs());
$pm = $m->addPackage(array('meta' => array('service_type' => 'reseller', 'credits_on_creation' => '-5')));
$check('addPackage refuses negative credits', $pm === array() && isset($m->Input->errors()['meta[credits_on_creation]']), $errs());
$pm = $m->addPackage(array('meta' => array('service_type' => 'line', 'package_id' => $pkgId, 'trial' => 'true')));
$pmk = array();
foreach ($pm as $f) { $pmk[$f['key']] = $f['value']; }
$check('addPackage stores the meta (unticked box = false)', ($pmk['package_id'] ?? '') === $pkgId && $pmk['trial'] === 'true' && $pmk['delete_on_cancel'] === 'false' && $pmk['credits_on_creation'] === '0', $pmk ?: $errs());
$check('editPackage validates too', $m->editPackage((object) array(), array('meta' => array('service_type' => 'bogus'))) === array());

$mkPackage = function (array $meta) { return (object) array('meta' => (object) $meta); };
$linePkg = $mkPackage(array('service_type' => 'line', 'package_id' => $pkgId, 'trial' => 'false', 'delete_on_cancel' => 'true'));
$subPkg = $mkPackage(array('service_type' => 'reseller', 'credits_on_creation' => '50', 'credits_per_renewal' => '20', 'delete_on_cancel' => 'true'));

$svcSeq = 9000 + (getmypid() % 1000) * 10;
$newService = function ($fields) use (&$svcSeq, $toFields) {
    return (object) array('id' => ++$svcSeq, 'date_renews' => '2026-11-01 00:00:00', 'fields' => $toFields($fields));
};
$fieldMap = function ($svc) { $o = array(); foreach ($svc->fields as $f) { $o[$f->key] = $f->value; } return $o; };

echo "== IPTV line service\n";
$GLOBALS['H']['client'] = (object) array('email' => "blesta-$run-a@example.test", 'first_name' => 'Ann', 'last_name' => 'Berg');
$secretPw = 'Passw0rd-' . $run;
$fields = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true', 'xtreampro_username' => 'bl' . $run . 'ln', 'xtreampro_password' => $secretPw), null, null, 'pending');
$check('addService (line)', is_array($fields) && !$m->Input->errors(), $errs());
$svc = $newService($fields);
$f = $fieldMap($svc);
$lineId = (int) ($f['xtreampro_panel_id'] ?? 0);
$cleanup[] = array('delete_line', $lineId);
$check('service fields: username, encrypted password, panel id, nonce, generation 0', $f['xtreampro_username'] !== '' && $f['xtreampro_password'] !== '' && $lineId > 0 && preg_match('/^[a-f0-9]{12}$/', $f['xtreampro_nonce']) && $f['xtreampro_generation'] === '0' && $fields[1]['encrypted'] === 1 && $fields[0]['encrypted'] === 0, $f);
$pl = panelData('get_line', array('id' => $lineId));
$check('panel: the line exists, active, same username and password', is_array($pl) && $pl['status'] === 'active' && $pl['username'] === $f['xtreampro_username'] && $pl['password'] === $f['xtreampro_password'], $pl);
$check('getServiceName', $m->getServiceName($svc) === $f['xtreampro_username']);
$client = $m->getClientServiceInfo($svc, $linePkg);
$check('getClientServiceInfo shows username, password, status, expiry', strpos($client, htmlspecialchars($f['xtreampro_username'])) !== false && strpos($client, htmlspecialchars($f['xtreampro_password'])) !== false && strpos($client, 'active') !== false && strpos($client, 'UTC') !== false, $client);
$tab = $m->tabClientAccount($linePkg, $svc, array(), array(), array());
$check('client tab shows play links, HTML-escaped', strpos($tab, '/get.php?') !== false && strpos($tab, 'password=') !== false && strpos($tab, '&amp;') !== false && strpos($tab, 'Web player') !== false && !preg_match('/value="[^"]*&(?!amp;|quot;|#039;|lt;|gt;)/', $tab), substr($tab, 0, 1500));
$admin = $m->getAdminServiceInfo($svc, $linePkg);
$check('getAdminServiceInfo shows line id and status', strpos($admin, (string) $lineId) !== false && strpos($admin, 'active') !== false);

$check('suspend', ($m->suspendService($linePkg, $svc) === null) && !$m->Input->errors(), $errs());
$check('panel: line disabled', (panelData('get_line', array('id' => $lineId))['status'] ?? '') === 'disabled');
$check('unsuspend', ($m->unsuspendService($linePkg, $svc) === null) && !$m->Input->errors(), $errs());
$check('panel: line active again', (panelData('get_line', array('id' => $lineId))['status'] ?? '') === 'active');

$exp0 = (int) panelData('get_line', array('id' => $lineId))['exp_date'];
$svc->date_renews = '2026-11-01 00:00:00';
$check('renew', ($m->renewService($linePkg, $svc) === null) && !$m->Input->errors(), $errs());
$exp1 = (int) panelData('get_line', array('id' => $lineId))['exp_date'];
$check('panel: expiry moved by the renewal', $exp1 > $exp0, "$exp0 -> $exp1");
$m->renewService($linePkg, $svc);
$check('renew repeated for the same renewal does not extend twice', (int) panelData('get_line', array('id' => $lineId))['exp_date'] === $exp1 && !$m->Input->errors(), $errs());
$svc->date_renews = '2027-11-01 00:00:00';
$m->renewService($linePkg, $svc);
$exp2 = (int) panelData('get_line', array('id' => $lineId))['exp_date'];
$check('the next renewal period extends again', $exp2 > $exp1, "$exp1 -> $exp2");
$check('renew again, same day, still once', ($m->renewService($linePkg, $svc) === null) && (int) panelData('get_line', array('id' => $lineId))['exp_date'] === $exp2);

$closed = $m->cancelService($linePkg, $svc);
$check('cancel (delete)', is_array($closed) && !$m->Input->errors(), $errs());
$cf = $fieldMap((object) array('fields' => $toFields($closed)));
$check('panel: line is gone', panelData('get_line', array('id' => $lineId)) === 'RESOURCE_NOT_FOUND');
$check('fields after delete: no panel id, generation 1, same nonce', $cf['xtreampro_panel_id'] === '' && $cf['xtreampro_generation'] === '1' && $cf['xtreampro_nonce'] === $f['xtreampro_nonce'], $cf);
$svc->fields = $toFields($closed);
$check('cancel again is still a success', ($m->cancelService($linePkg, $svc) !== false) && !$m->Input->errors(), $errs());
$check('suspend of a deleted line is a readable error', ($m->suspendService($linePkg, $svc) === null) && stripos($errs(), 'not found') !== false, $errs());

$again = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true') + array_intersect_key($cf, array_flip(array('xtreampro_nonce', 'xtreampro_generation'))), null, null, 'active');
$check('add again after the cancel', is_array($again) && !$m->Input->errors(), $errs());
$af = $fieldMap((object) array('fields' => $toFields($again)));
$cleanup[] = array('delete_line', (int) $af['xtreampro_panel_id']);
$check('panel: a new line was sold (new id, active)', (int) $af['xtreampro_panel_id'] > 0 && (int) $af['xtreampro_panel_id'] !== $lineId && (panelData('get_line', array('id' => (int) $af['xtreampro_panel_id']))['status'] ?? '') === 'active', $af);
$replay = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true', 'xtreampro_nonce' => $af['xtreampro_nonce'], 'xtreampro_generation' => $af['xtreampro_generation'], 'xtreampro_username' => $af['xtreampro_username'], 'xtreampro_password' => $af['xtreampro_password']), null, null, 'active');
$rf = $fieldMap((object) array('fields' => $toFields($replay)));
$check('the same request again returns the same line (no second charge)', ($rf['xtreampro_panel_id'] ?? '') === $af['xtreampro_panel_id'], $errs() ?: $rf);

echo "== IPTV line: change password, disable on cancel, usernames\n";
$svc2 = $newService($again);
$svc2->fields = $toFields($again);
$edit = $m->editService($linePkg, $svc2, array('use_module' => 'true', 'xtreampro_password' => 'NewPassw0rd-' . $run));
$ef = $fieldMap((object) array('fields' => $toFields($edit)));
$pl2 = panelData('get_line', array('id' => (int) $af['xtreampro_panel_id']));
$check('editService changes the password on the panel and stores what the panel keeps', is_array($edit) && $ef['xtreampro_password'] === $pl2['password'], $errs() ?: array($ef, $pl2));
$keep = $mkPackage(array('service_type' => 'line', 'package_id' => $pkgId, 'delete_on_cancel' => 'false'));
$f3 = $m->addService($keep, array('client_id' => 1, 'use_module' => 'true'), null, null, 'active');
$f3m = $fieldMap((object) array('fields' => $toFields($f3)));
$id3 = (int) ($f3m['xtreampro_panel_id'] ?? 0);
$cleanup[] = array('delete_line', $id3);
$check('add with generated credentials', $id3 > 0 && strlen($f3m['xtreampro_username']) >= 8 && strlen($f3m['xtreampro_password']) >= 8, $errs() ?: $f3m);
$svc3 = $newService($f3);
$check('cancel with "delete on cancel" off only disables the line', $m->cancelService($keep, $svc3) === null && !$m->Input->errors() && (panelData('get_line', array('id' => $id3))['status'] ?? '') === 'disabled', $errs());
$svc3->fields = $toFields(array_merge($f3, array()));
$none = $newService(array(array('key' => 'xtreampro_username', 'value' => $f3m['xtreampro_username'], 'encrypted' => 0)));
$check('a service without a stored panel id finds its line by username', $m->unsuspendService($keep, $none) === null && !$m->Input->errors() && (panelData('get_line', array('id' => $id3))['status'] ?? '') === 'active', $errs());
$taken = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true', 'xtreampro_username' => $f3m['xtreampro_username']), null, null, 'active');
$check('a username that is taken gives a readable error and no service', $taken === null && stripos($errs(), 'already taken') !== false, $errs());
$off = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'false', 'xtreampro_username' => 'manual-user'), null, null, 'active');
$offf = $fieldMap((object) array('fields' => $toFields($off)));
$check('"use module" off creates the service without calling the panel', $offf['xtreampro_panel_id'] === '' && $offf['xtreampro_username'] === 'manual-user' && $offf['xtreampro_password'] === '', $errs() ?: $offf);
$nopkg = $m->addService($mkPackage(array('service_type' => 'line')), array('client_id' => 1, 'use_module' => 'true'));
$check('a line package without a panel package is refused', $nopkg === null && stripos($errs(), 'package') !== false, $errs());
$badpkg = $m->addService($mkPackage(array('service_type' => 'line', 'package_id' => '999999')), array('client_id' => 1, 'use_module' => 'true'));
$check('an unknown panel package gives a readable error', $badpkg === null && $errs() !== '' && strpos($errs(), 'INVALID') === false, $errs());
$GLOBALS['H']['row'] = (object) array('id' => 4, 'meta' => (object) array_merge((array) $goodRow->meta, array('api_key' => 'xk_wrong_key_zz')));
$wrong = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true'));
$check('a wrong API key gives a readable error', $wrong === null && stripos($errs(), 'API key') !== false, $errs());
$GLOBALS['H']['row'] = (object) array('id' => 5, 'meta' => (object) array_merge((array) $goodRow->meta, array('port' => '1')));
$down = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true'));
$check('an unreachable panel gives a readable error', $down === null && stripos($errs(), 'could not connect') !== false, $errs());
$GLOBALS['H']['row'] = $goodRow;

echo "== 1.1.0: sells, pricing, change package, spent request id, connector name\n";
$apiPkgs = panelData('packages');
$boxOnly = array_values(array_filter(is_array($apiPkgs) ? $apiPkgs : array(), function ($p) { return isset($p['sells']) && !in_array('line', $p['sells'], true); }));
$check('the panel marks a box-only package with sells without "line"', count($boxOnly) >= 1, $apiPkgs);
$check('the package dropdown leaves box-only packages out', $boxOnly && !array_key_exists((string) $boxOnly[0]['id'], $sel['options']), array_keys($sel['options']));
$check('a panel that sends no sells counts as selling lines', XtreamproApi::sellsLine(array('id' => 1)) && !XtreamproApi::sellsLine(array('sells' => array('mag'))));
$boxy = $m->addService($mkPackage(array('service_type' => 'line', 'package_id' => (string) $boxOnly[0]['id'])), array('client_id' => 1, 'use_module' => 'true'));
$check('a box-only package is refused before anything is sold, with a readable reason', $boxy === null && stripos($errs(), 'boxes only') !== false, $errs());
$cn = array();
foreach ((array) panelData('api_logs', array('limit' => 50)) as $row) { $cn[] = $row['connector'] ?? ''; }
$check('the panel call log names the connector', in_array('blesta/1.1.0', $cn, true), array_unique($cn));
$other = '';
foreach ($apiPkgs as $p) { if ((string) $p['id'] !== $pkgId && !empty($p['is_official']) && in_array('line', $p['sells'] ?? array('line'), true)) { $other = (string) $p['id']; break; } }
$changePkg = $mkPackage(array('service_type' => 'line', 'package_id' => $other, 'trial' => 'false', 'delete_on_cancel' => 'true'));
$balB = (int) panelData('user_info')['credits'];
if ($other !== '') {
    $m->Input->setRules(array());
    $m->changeServicePackage($linePkg, $changePkg, $svc2);
    $now = panelData('get_line', array('id' => (int) $af['xtreampro_panel_id']));
    $check('changeServicePackage sells the new package on the line', !$m->Input->errors() && (int) $now['package_id'] === (int) $other, $errs() ?: $now);
    $check('and charged for it', (int) panelData('user_info')['credits'] < $balB);
    $balC = (int) panelData('user_info')['credits'];
    $m->Input->setRules(array());
    $m->changeServicePackage($linePkg, $changePkg, $svc2);
    $check('repeating the change is not charged again', !$m->Input->errors() && (int) panelData('user_info')['credits'] === $balC, $errs());
    $check('the module log keeps what the change did to the time left', strpos(serialize($GLOBALS['H']['log']), 'On the panel') !== false);
    $m->Input->setRules(array());
    $m->changeServicePackage($linePkg, $mkPackage(array('service_type' => 'line', 'package_id' => (string) $boxOnly[0]['id'])), $svc2);
    $check('a change to a box-only package fails readably', stripos($errs(), 'boxes only') !== false, $errs());
} else {
    echo "  skip changeServicePackage: the panel has only one official line package\n";
}
$spentNonce = bin2hex(random_bytes(6));
$sp = $m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true', 'xtreampro_nonce' => $spentNonce, 'xtreampro_generation' => '0', 'xtreampro_username' => 'bs' . $run, 'xtreampro_password' => 'Passw0rd-' . $run), null, null, 'active');
$spf = $fieldMap((object) array('fields' => $toFields($sp)));
$check('a sale for the spent-id test', is_array($sp) && (int) ($spf['xtreampro_panel_id'] ?? 0) > 0, $errs());
panel('POST', 'delete_line', array('id' => (int) $spf['xtreampro_panel_id']));
$m->Input->setRules(array());
$m->addService($linePkg, array('client_id' => 1, 'use_module' => 'true', 'xtreampro_nonce' => $spentNonce, 'xtreampro_generation' => '0', 'xtreampro_username' => 'bs' . $run, 'xtreampro_password' => 'Passw0rd-' . $run), null, null, 'active');
$check('an order replayed after its line was deleted on the panel gives a readable message (REQUEST_ID_SPENT)', stripos($errs(), 'already made') !== false && strpos($errs(), 'REQUEST_ID') === false, $errs());
$dflt = $m->getPackageFields((object) array('meta' => array()));
$check('"Delete permanently on cancel" is off by default', $dflt->find('meta[delete_on_cancel]')['checked'] === false);

echo "== sub-reseller service\n";
$GLOBALS['H']['client'] = (object) array('email' => "blesta-$run-b@example.test", 'first_name' => 'Bo', 'last_name' => 'Chen');
$badname = $m->addService($subPkg, array('client_id' => 1, 'use_module' => 'true', 'xtreampro_username' => 'a b'));
$check('an invalid sub-reseller username is refused', $badname === null && isset($m->Input->errors()['xtreampro_username']), $errs());
$sf = $m->addService($subPkg, array('client_id' => 1, 'use_module' => 'true'), null, null, 'pending');
$check('addService (sub-reseller, generated credentials)', is_array($sf) && !$m->Input->errors(), $errs());
$ssvc = $newService($sf);
$sm = $fieldMap($ssvc);
$userId = $sm['xtreampro_panel_id'];
$cleanup[] = array('delete_user', $userId);
$check('fields: generated username 3-32, password >= 8, UUID', preg_match('/^[A-Za-z0-9._-]{3,32}$/', $sm['xtreampro_username']) && strlen($sm['xtreampro_password']) >= 8 && preg_match('/^[0-9a-f-]{36}$/', $userId), $sm);
$pu = panelData('get_user', array('id' => $userId));
$userShape = is_array($pu) ? $pu : array();
if (isset($userShape['user'])) { $userShape = $userShape['user']; }
$check('panel: account exists with the credits handed over (50)', ($userShape['username'] ?? '') === $sm['xtreampro_username'] && (int) ($userShape['credits'] ?? -1) === 50, $pu);
$credits = function () use (&$userId) { $u = panelData('get_user', array('id' => $userId)); if (isset($u['user'])) { $u = $u['user']; } return is_array($u) ? (int) $u['credits'] : -999; };
$statusOf = function () use (&$userId) { $u = panelData('get_user', array('id' => $userId)); if (isset($u['user'])) { $u = $u['user']; } return is_array($u) ? ($u['status'] ?? '') : (string) $u; };
$ct = $m->tabClientAccount($subPkg, $ssvc, array(), array(), array());
$check('client tab: credentials and credit balance, no play links', strpos($ct, htmlspecialchars($sm['xtreampro_username'])) !== false && strpos($ct, 'Credit balance') !== false && strpos($ct, '>50<') !== false && strpos($ct, 'get.php') === false, $ct);
$ci = $m->getClientServiceInfo($ssvc, $subPkg);
$check('getClientServiceInfo (sub-reseller) shows credits', strpos($ci, '>50<') !== false && strpos($ci, 'Credit balance') !== false);
$ai = $m->getAdminServiceInfo($ssvc, $subPkg);
$check('getAdminServiceInfo (sub-reseller) shows the account id', strpos($ai, $userId) !== false);
$s0 = $statusOf();
$check('suspend', $m->suspendService($subPkg, $ssvc) === null && !$m->Input->errors(), $errs());
$s1 = $statusOf();
$check('panel: account status changed by suspend', $s1 !== $s0, "$s0 -> $s1");
$check('unsuspend', $m->unsuspendService($subPkg, $ssvc) === null && !$m->Input->errors() && $statusOf() === $s0, $errs());
$ssvc->date_renews = '2026-11-01 00:00:00';
$check('renew tops up 20 credits', $m->renewService($subPkg, $ssvc) === null && !$m->Input->errors() && $credits() === 70, $errs() ?: $credits());
$m->renewService($subPkg, $ssvc);
$check('renew repeated for the same renewal does not top up twice', $credits() === 70 && !$m->Input->errors(), $errs() ?: $credits());
$ssvc->date_renews = '2027-11-01 00:00:00';
$m->renewService($subPkg, $ssvc);
$check('the next renewal period tops up again', $credits() === 90, $credits());
$zero = $mkPackage(array('service_type' => 'reseller', 'credits_per_renewal' => '0'));
$check('credits per renewal 0: a renewal does nothing', $m->renewService($zero, $ssvc) === null && !$m->Input->errors() && $credits() === 90, $errs());
$editSub = $m->editService($subPkg, $ssvc, array('use_module' => 'true', 'xtreampro_password' => 'SubNewPass-' . $run));
$check('editService changes the sub-reseller password', is_array($editSub) && $fieldMap((object) array('fields' => $toFields($editSub)))['xtreampro_password'] === 'SubNewPass-' . $run, $errs());
$sclosed = $m->cancelService($subPkg, $ssvc);
$scf = $fieldMap((object) array('fields' => $toFields($sclosed)));
$check('cancel (delete) removes the account', is_array($sclosed) && !$m->Input->errors() && panelData('get_user', array('id' => $userId)) === 'RESOURCE_NOT_FOUND' && $scf['xtreampro_panel_id'] === '' && $scf['xtreampro_generation'] === '1', $errs());
$ssvc->fields = $toFields($sclosed);
$check('cancel again is still a success', $m->cancelService($subPkg, $ssvc) !== false && !$m->Input->errors(), $errs());
$sa = $m->addService($subPkg, array('client_id' => 1, 'use_module' => 'true') + array_intersect_key($scf, array_flip(array('xtreampro_nonce', 'xtreampro_generation'))), null, null, 'active');
$sam = $fieldMap((object) array('fields' => $toFields($sa)));
if (!empty($sam['xtreampro_panel_id'])) { $cleanup[] = array('delete_user', $sam['xtreampro_panel_id']); }
$userId = $sam['xtreampro_panel_id'] ?? '';
$check('add again sells a new account with the starting credits', is_array($sa) && $userId !== '' && $userId !== $sm['xtreampro_panel_id'] && $credits() === 50, $errs() ?: $sam);
$GLOBALS['H']['client'] = (object) array('email' => "blesta-$run-c@example.test", 'first_name' => 'Cy', 'last_name' => 'Dee');
$keepSub = $mkPackage(array('service_type' => 'reseller', 'credits_on_creation' => '0', 'credits_per_renewal' => '5', 'delete_on_cancel' => 'false'));
$ks = $m->addService($keepSub, array('client_id' => 1, 'use_module' => 'true'));
$ksm = $fieldMap((object) array('fields' => $toFields($ks)));
$cleanup[] = array('delete_user', $ksm['xtreampro_panel_id'] ?? '');
$userId = $ksm['xtreampro_panel_id'] ?? '';
$ksvc = $newService($ks);
$check('cancel with "delete on cancel" off only disables the account', $m->cancelService($keepSub, $ksvc) === null && !$m->Input->errors() && $statusOf() !== $s0, $errs() ?: $statusOf());
$GLOBALS['H']['client'] = (object) array('email' => "blesta-$run-b@example.test", 'first_name' => 'X', 'last_name' => 'Y');
$dup = $m->addService($keepSub, array('client_id' => 1, 'use_module' => 'true'));
$check('a taken email gives a readable error and no service', $dup === null && stripos($errs(), 'already taken') !== false, $errs());

echo "== logging\n";
$all = '';
foreach ($GLOBALS['H']['log'] as $e) { $all .= $e[0] . '|' . $e[1] . "\n"; }
$check('the module log has entries (input and output)', count($GLOBALS['H']['log']) > 20 && strpos($all, 'blesta-renew-') !== false);
$check('the API key never reached the module log', strpos($all, $apiKey) === false && strpos($all, 'X-API-Key') === false);
$leaks = array();
foreach (array($secretPw, 'NewPassw0rd-' . $run, 'SubNewPass-' . $run, $f['xtreampro_password'], $sm['xtreampro_password'], $af['xtreampro_password'], $f3m['xtreampro_password']) as $pw) {
    if ($pw !== '' && strpos($all, $pw) !== false) { $leaks[] = substr($pw, 0, 4) . '...'; }
}
$check('no line password or sub-reseller password in the module log', !$leaks, $leaks);
$check('every log call carries a success flag', count(array_filter($GLOBALS['H']['log'], function ($e) { return is_bool($e[3]); })) === count($GLOBALS['H']['log']));

echo "== language and PHP\n";
$check('no language key is missing', !$GLOBALS['H']['missing'], array_keys($GLOBALS['H']['missing']));
$check('no PHP warnings or notices', !$GLOBALS['H']['warnings'], $GLOBALS['H']['warnings']);

foreach (array_reverse($cleanup) as $c) {
    if ($c[1] !== '' && $c[1] !== 0) { panel('POST', $c[0], array('id' => $c[1])); }
}
echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
