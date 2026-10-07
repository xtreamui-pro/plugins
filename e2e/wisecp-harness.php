<?php
// Stand-in for the parts of WISECP the XtreamPro product module touches, to run
// the real module for real against a panel API. Not shipped.
//
// WISECP is commercial (ionCube-encoded, licence needed), so it cannot be started.
// This file re-implements, from the developer documentation and the published
// sample module, only what the module uses: the ProductModule base class (config,
// lang, options persistence, save_log, encode_str, get_page, use_controller), the
// Filter helper, and a core driver that calls module methods the way
// Services::run_module() does (terminate -> cancel, an array result is merged into
// the service options, false / exception = failed). Every place where that
// behaviour is a guess is listed in plugins/wisecp/README.md.
//
//   MODULE_DIR=.../plugins/wisecp/coremio/modules/Product/XtreamPro \
//   API_PORT_NUM=18095 API_KEY=... php plugins/e2e/wisecp-harness.php
//   (API_URL overrides http://127.0.0.1:API_PORT_NUM)

define('CORE_FOLDER', __DIR__);
define('DS', DIRECTORY_SEPARATOR);
define('XP_DIR', rtrim((string) getenv('MODULE_DIR'), '/'));
$GLOBALS['db'] = array('services' => array(), 'products' => array(), 'users' => array());
$GLOBALS['store'] = array();   // the module's config.php "file"
$GLOBALS['logs'] = array();    // the panel's action history

class Filter
{
    public static function init($path, $type = '')
    {
        $parts = explode('/', $path, 2);
        $v = $_POST[$parts[1]] ?? '';
        if (!is_string($v)) {
            return '';
        }
        return $type === 'hclear' ? strip_tags(trim($v)) : $v;
    }
}

class ProductModule
{
    public $_name = '';
    public $_type = 'Product';
    public $dir = '';
    public $url = '';
    public $config = array();
    public $lang = array();
    public $service = array();
    public $product = array();
    public $order = array();
    public $user = array();
    public $admin = array();
    public $options = array();
    public $addons = array();
    public $addon_params = array();
    public $requirement_params = array();
    public $error = '';
    public $client_area = false;
    public $area_link = '/admin/modules/product';
    public $client_callable_methods = array();

    public function __construct()
    {
        $this->dir = XP_DIR . '/';
        $this->config = $GLOBALS['store']['config'] ?? include $this->dir . 'config.php';
        $this->lang = include $this->dir . 'lang/en.php';
    }

    public function set_service($id)
    {
        $row = $GLOBALS['db']['services'][$id];
        $this->service = $row;
        unset($this->service['options']);
        $this->product = $GLOBALS['db']['products'][$row['product_id']];
        $this->user = $GLOBALS['db']['users'][$row['owner_id']];
        $this->options = $row['options'];
        $this->requirement_params = $row['requirements'] ?? array();
    }

    public function save_options()
    {
        $GLOBALS['db']['services'][$this->service['id']]['options'] = $this->options;
        return true;
    }

    protected function save_config($data = array(), $auto_status = true)
    {
        $GLOBALS['store']['config'] = $data;   // WISECP writes config.php: a readable file
        return true;
    }

    // Reversible and not readable: stands in for the module's crypt sub-key.
    protected function encode_str(string $str = '', string $key = ''): string
    {
        return $str === '' ? '' : 'enc:' . strrev(base64_encode($str));
    }

    protected function decode_str(string $str = '', string $key = ''): string
    {
        return strpos($str, 'enc:') === 0 ? (string) base64_decode(strrev(substr($str, 4))) : '';
    }

    // WISECP v5 signature: save_log($action, $request, $response, $processed).
    protected function save_log($action = '', $request = '', $response = '', $processed = ''): int
    {
        $GLOBALS['logs'][] = array($action, json_encode(array($request, $response, $processed)));
        return count($GLOBALS['logs']);
    }

    public function get_page($page_file = '', $vars = array()): string
    {
        $module = $this;
        extract($vars);
        ob_start();
        include $this->dir . 'pages/' . $page_file . '.php';
        return (string) ob_get_clean();
    }

    public function use_controller($param = '')
    {
        $method = 'controller_' . str_replace('-', '_', $param);
        return method_exists($this, $method) ? $this->$method() : null;
    }
}

// What Services::run_module() does around a module method.
function core_run($sid, $action, $args = array())
{
    $m = new XtreamPro();
    $m->set_service($sid);
    if ($action === 'terminate' && !method_exists($m, 'terminate')) {
        $action = 'cancel';
    }
    if (!method_exists($m, $action)) {
        return array('ok' => null, 'error' => 'module method not found');
    }
    try {
        $r = $m->$action(...$args);
    } catch (\Throwable $e) {
        return array('ok' => false, 'error' => $e->getMessage());
    }
    if ($r === false) {
        return array('ok' => false, 'error' => (string) $m->error);
    }
    if (is_array($r)) {
        foreach (array('config', 'login', 'creation_info', 'options') as $k) {
            if (isset($r[$k]) && $k !== 'options') {
                $opts = &$GLOBALS['db']['services'][$sid]['options'];
                $opts[$k] = array_replace_recursive($opts[$k] ?? array(), $r[$k]);
                unset($opts);
            }
        }
    }
    return array('ok' => true, 'result' => $r);
}

function new_service($productId, $ownerId, $due = '2026-10-01 00:00:00')
{
    $sid = random_int(1000000, 900000000);
    $GLOBALS['db']['services'][$sid] = array(
        'id' => $sid, 'product_id' => $productId, 'owner_id' => $ownerId, 'duedate' => $due,
        'status' => 'waiting', 'options' => array(), 'requirements' => array(),
    );
    return $sid;
}

function module_for($sid, $client = false)
{
    $m = new XtreamPro();
    $m->set_service($sid);
    $m->client_area = $client;
    return $m;
}

// ---- panel access for verification (independent of the module) ---------------

$API_BASE = getenv('API_URL') ?: ('http://127.0.0.1:' . getenv('API_PORT_NUM'));
$API_KEY = (string) getenv('API_KEY');
function panel($action, array $q = array(), $post = false)
{
    global $API_BASE, $API_KEY;
    $url = $API_BASE . '/reseller/v1';
    $ch = curl_init();
    $q = array_merge(array('action' => $action), $q);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($q));
    } else {
        $url .= '?' . http_build_query($q);
    }
    curl_setopt_array($ch, array(CURLOPT_URL => $url, CURLOPT_HTTPHEADER => array('X-API-Key: ' . $API_KEY),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : array('status' => 'STATUS_FAILURE', 'error' => 'BAD_RESPONSE');
}
function credits() { return (int) panel('user_info')['data']['credits']; }
function line_of($id) { $r = panel('get_line', array('id' => $id)); return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null; }
function sub_of($username)
{
    $r = panel('get_users', array('search' => $username, 'start' => 0, 'limit' => 50));
    foreach ($r['data'] ?? array() as $u) {
        if (($u['username'] ?? '') === $username) { return $u; }
    }
    return null;
}

require XP_DIR . '/XtreamPro.php';

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    if (!$ok) { $fail++; }
};
$secrets = array($API_KEY);
$RUN = getmypid() . random_int(100, 999);
$cleanupLines = array();
$cleanupUsers = array();

// ==========================================================================
echo "== module settings\n";
$GLOBALS['store'] = array();
$check('config.php ships without credentials', (include XP_DIR . '/config.php')['settings']['api_key'] === '');
$check('version 1.1.0 in the entry file, the config and the API client (X-Connector)', strpos(file_get_contents(XP_DIR . '/XtreamPro.php'), '1.1.0') !== false && (include XP_DIR . '/config.php')['meta']['version'] === '1.1.0' && strpos(file_get_contents(XP_DIR . '/src/ApiClient.php'), "VERSION = '1.1.0'") !== false);

$_POST = array('api_url' => $API_BASE . '/', 'api_key' => $API_KEY);
$m = new XtreamPro();
$r = $m->use_controller('save');
$check('save settings', ($r['status'] ?? '') === 'successful', $r);
$saved = $GLOBALS['store']['config']['settings'];
$check('api_url stored without trailing slash', $saved['api_url'] === rtrim($API_BASE, '/'), $saved['api_url']);
$check('api_key stored encrypted, not in clear', $saved['api_key'] !== '' && strpos(json_encode($GLOBALS['store']), $API_KEY) === false);
$check('config keeps meta after save', ($GLOBALS['store']['config']['meta']['name'] ?? '') === 'Xtream UI Pro');

$page = (new XtreamPro())->page_configuration();
$check('settings page renders the url and never the key', strpos($page, htmlspecialchars(rtrim($API_BASE, '/'))) !== false && strpos($page, $API_KEY) === false);
$check('settings page has save + test controllers', strpos($page, 'value="save"') !== false && strpos($page, 'value="test-connection"') !== false);

$_POST = array('api_url' => $API_BASE, 'api_key' => '');
(new XtreamPro())->use_controller('save');
$check('empty key field keeps the saved key', $GLOBALS['store']['config']['settings']['api_key'] === $saved['api_key']);
$_POST = array('api_url' => $API_BASE, 'api_key' => '********');
(new XtreamPro())->use_controller('save');
$check('masked key field keeps the saved key', $GLOBALS['store']['config']['settings']['api_key'] === $saved['api_key']);
$thrown = '';
try { $_POST = array('api_url' => 'ftp://example.test', 'api_key' => ''); (new XtreamPro())->use_controller('save'); } catch (\Exception $e) { $thrown = $e->getMessage(); }
$check('a non-http URL is refused', stripos($thrown, 'http') !== false, $thrown);

$tc = (new XtreamPro())->use_controller('test-connection');
$check('test connection', ($tc['status'] ?? '') === 'successful' && strpos($tc['message'], 'credits') !== false, $tc);
$_POST = array('api_url' => $API_BASE, 'api_key' => 'xk_wrong_key');
(new XtreamPro())->use_controller('save');
$thrown = '';
try { (new XtreamPro())->use_controller('test-connection'); } catch (\Exception $e) { $thrown = $e->getMessage(); }
$check('test connection with a wrong key fails readably', stripos($thrown, 'API key') !== false, $thrown);
$_POST = array('api_url' => 'http://127.0.0.1:1', 'api_key' => 'xk_wrong_key');
(new XtreamPro())->use_controller('save');
$thrown = '';
try { (new XtreamPro())->use_controller('test-connection'); } catch (\Exception $e) { $thrown = $e->getMessage(); }
$check('test connection to a dead port fails readably', stripos($thrown, 'Could not connect') !== false, $thrown);
$pf = (new XtreamPro())->product_configuration(array());
$check('product fields fall back to a package id field when the panel is down',
    $pf['package']['type'] === 'text' && stripos($pf['package']['description'], 'Could not connect') !== false, $pf['package']);
// restore the good settings
$_POST = array('api_url' => $API_BASE, 'api_key' => $API_KEY);
(new XtreamPro())->use_controller('save');
$check('settings are usable again', ((new XtreamPro())->use_controller('test-connection')['status'] ?? '') === 'successful');

// not configured at all
$bak = $GLOBALS['store'];
$GLOBALS['store'] = array('config' => array('settings' => array('api_url' => '', 'api_key' => '')));
$GLOBALS['db']['products'][1] = array('module_data' => array('service_type' => 'line', 'package' => '1'));
$GLOBALS['db']['users'][1] = array('email' => 'x@example.test');
$r = core_run(new_service(1, 1), 'create');
$check('create without module settings fails readably', $r['ok'] === false && stripos($r['error'], 'incomplete') !== false, $r);
$GLOBALS['store'] = $bak;

echo "== product settings\n";
$pf = (new XtreamPro())->product_configuration(array());
$check('package dropdown loaded from the panel', $pf['package']['type'] === 'dropdown' && count($pf['package']['options']) >= 1, $pf['package']);
$check('product fields keep their names', array_keys($pf) === array('service_type', 'package', 'trial', 'delete_on_terminate', 'credits_on_creation', 'credits_per_renewal'), array_keys($pf));
$check('delete on terminate is off by default (disable is the default) and says that deleting is final', $pf['delete_on_terminate']['checked'] === false && $pf['trial']['checked'] === false && stripos($pf['delete_on_terminate']['description'], 'final') !== false);
$check('every field has a label and a description', !array_filter($pf, function ($f) { return empty($f['name']) || empty($f['description']); }));
$vals = array('service_type' => 'line', 'package' => '3', 'credits_on_creation' => '', 'credits_per_renewal' => '5');
(new XtreamPro())->save_product_configuration($vals);
$check('save_product_configuration normalises (unticked boxes become 0)', $vals['trial'] === 0 && $vals['delete_on_terminate'] === 0 && $vals['credits_on_creation'] === '0' && $vals['credits_per_renewal'] === '5', $vals);
foreach (array(
    'unknown service type' => array('service_type' => 'bogus'),
    'a line without package' => array('service_type' => 'line', 'package' => ''),
    'negative credits' => array('service_type' => 'reseller', 'credits_on_creation' => '-5'),
    'decimal renewal credits' => array('service_type' => 'reseller', 'credits_per_renewal' => '1.5'),
) as $label => $bad) {
    $thrown = '';
    try { (new XtreamPro())->save_product_configuration($bad); } catch (\Exception $e) { $thrown = $e->getMessage(); }
    $check("refused: $label", $thrown !== '', $bad);
}

// ==========================================================================
$packages = panel('packages')['data'];
$pkg = null;
foreach ($packages as $p) { if (!empty($p['is_official'])) { $pkg = $p; break; } }
$GLOBALS['db']['users'][1] = array('email' => "wisecp-$RUN-line@example.test", 'full_name' => 'Ann Berg', 'name' => 'Ann', 'surname' => 'Berg');
// The panel keeps e-mail addresses unique: every sub-reseller service gets its own customer.
foreach (array(2, 3, 4, 5) as $n) {
    $GLOBALS['db']['users'][$n] = array('email' => "wisecp-$RUN-sub$n@example.test", 'full_name' => 'Bo Carl', 'name' => 'Bo', 'surname' => 'Carl');
}
$lineData = array('service_type' => 'line', 'package' => (string) $pkg['id'], 'trial' => 0, 'delete_on_terminate' => 1, 'credits_on_creation' => '0', 'credits_per_renewal' => '0');
$GLOBALS['db']['products'][10] = array('module_data' => $lineData);
$GLOBALS['db']['products'][11] = array('module_data' => array_merge($lineData, array('delete_on_terminate' => 0)));
$GLOBALS['db']['products'][12] = array('module_data' => array_merge($lineData, array('package' => '999999')));
$exp = function ($id) { $l = line_of($id); return $l ? (int) $l['exp_date'] : -1; };

echo "== 1.1.0: sells, pricing, connector name, spent request id\n";
$boxPkg = null;
foreach ($packages as $p) { if (isset($p['sells']) && !in_array('line', $p['sells'], true)) { $boxPkg = $p; break; } }
$check('the panel marks a box-only package with sells without "line"', $boxPkg !== null, $packages);
$check('the product package list leaves box-only packages out', $boxPkg !== null && !isset($pf['package']['options'][(string) $boxPkg['id']]) && isset($pf['package']['options'][(string) $pkg['id']]), array_keys($pf['package']['options']));
$check('a panel that sends no sells counts as selling lines', \XtreamPro\Wisecp\Client::sellsLine(array('id' => 1)) && !\XtreamPro\Wisecp\Client::sellsLine(array('sells' => array('mag'))));
$GLOBALS['db']['products'][13] = array('module_data' => array_merge($lineData, array('package' => (string) $boxPkg['id'])));
$sidBox = new_service(13, 1);
$creditsBox = credits();
$r = core_run($sidBox, 'create');
$check('a box-only package is refused before anything is sold, with a readable reason', $r['ok'] === false && stripos($r['error'], 'boxes only') !== false && empty($GLOBALS['db']['services'][$sidBox]['options']['config']['id']) && credits() === $creditsBox, $r);
$cn = array();
foreach ((panel('api_logs', array('limit' => 50))['data'] ?? array()) as $row) { $cn[] = $row['connector'] ?? ''; }
$check('the panel call log names the connector', in_array('wisecp/1.1.0', $cn, true), array_unique($cn));
$sidSp = new_service(10, 1);
$r = core_run($sidSp, 'create');
$spLine = (int) ($GLOBALS['db']['services'][$sidSp]['options']['config']['id'] ?? 0);
$check('a sale for the spent-id test', $r['ok'] === true && $spLine > 0, $r);
panel('delete_line', array('id' => $spLine), true);
unset($GLOBALS['db']['services'][$sidSp]['options']['config']['id']);
$r = core_run($sidSp, 'create');
$check('the same request id after its line was deleted on the panel gives a readable message (REQUEST_ID_SPENT)', $r['ok'] === false && stripos($r['error'], 'already made') !== false && strpos($r['error'], 'REQUEST_ID') === false, $r);

echo "== line service\n";
$sid = new_service(10, 1);
$before = credits();
$r = core_run($sid, 'create');
$check('create', $r['ok'] === true, $r);
$st = $GLOBALS['db']['services'][$sid]['options'];
$lineId = (int) ($st['config']['id'] ?? 0);
$cleanupLines[] = $lineId;
$check('line id kept in the service options', $lineId > 0 && ($st['config']['generation'] ?? -1) === 0, $st);
$pl = line_of($lineId);
$check('line exists and is active in the panel', $pl && $pl['status'] === 'active' && (int) $pl['package_id'] === (int) $pkg['id'], $pl);
$opts = $GLOBALS['db']['services'][$sid]['options'];
$check('the login on the service is the one the panel holds',
    ($opts['login']['username'] ?? '') === $pl['username'] && strpos(json_encode($opts['login']), $pl['password']) === false, $opts['login'] ?? array());
$secrets[] = $pl['password'];
$chargedCreate = $before - credits();
$check('creating charged the reseller once', $chargedCreate > 0, $chargedCreate);
$r = core_run($sid, 'create');
$check('create repeated does not fail', $r['ok'] === true, $r);
$check('create repeated sells nothing twice', credits() === $before - $chargedCreate && (int) $GLOBALS['db']['services'][$sid]['options']['config']['id'] === $lineId);

$html = module_for($sid, true)->get_page('dashboard');
$check('client area shows the credentials and the play link',
    strpos($html, htmlspecialchars($pl['username'])) !== false && strpos($html, htmlspecialchars($pl['password'])) !== false && strpos($html, 'get.php?') !== false, substr($html, 0, 300));
$check('client area escapes the link (&amp;) and never shows the API key', strpos($html, '&amp;type=m3u_plus') !== false && strpos($html, $API_KEY) === false);
$check('client area links the web player', strpos($html, '/player/') !== false);
$ov = module_for($sid, true)->client_overview_data();
$check('client overview rows', !empty($ov['account']) && $ov['account'][0]['value'] === $pl['username'], $ov);
$rs = module_for($sid)->fetchRemoteStatus();
$check('fetchRemoteStatus', ($rs['status'] ?? '') === 'active' && !empty($rs['expires_at']), $rs);
$check('admin service detail (older loaders) renders the same page', strpos(module_for($sid)->adminArea_service_fields()['xtreampro']['value'], $pl['username']) !== false);

$check('suspend', ($r = core_run($sid, 'suspend'))['ok'] === true, $r);
$check('panel: line is disabled', (line_of($lineId)['status'] ?? '') === 'disabled', line_of($lineId));
$check('unsuspend', ($r = core_run($sid, 'unsuspend'))['ok'] === true, $r);
$check('panel: line is active again', (line_of($lineId)['status'] ?? '') === 'active');

$e0 = $exp($lineId); $c0 = credits();
$GLOBALS['db']['services'][$sid]['duedate'] = '2026-11-01 00:00:00';
$check('renewal', ($r = core_run($sid, 'renew'))['ok'] === true, $r);
$e1 = $exp($lineId); $c1 = credits();
$check('panel: expiry moved by the renewal and it was charged', $e1 > $e0 && $c1 < $c0, "$e0 -> $e1, $c0 -> $c1");
$check('renewal repeated for the same due date does not extend or charge twice', core_run($sid, 'renew')['ok'] === true && $exp($lineId) === $e1 && credits() === $c1);
$GLOBALS['db']['services'][$sid]['duedate'] = '2027-11-01 00:00:00';
$check('renewal of the next period extends again', core_run($sid, 'renew')['ok'] === true && $exp($lineId) > $e1 && credits() === $c1 - ($c0 - $c1), $exp($lineId));

$check('cancel (delete on terminate)', ($r = core_run($sid, 'terminate'))['ok'] === true, $r);
$gone = panel('get_line', array('id' => $lineId));
$check('panel: the line is deleted', ($gone['error'] ?? '') === 'RESOURCE_NOT_FOUND', $gone);
$st = $GLOBALS['db']['services'][$sid]['options'];
$check('service closed: no id, generation 1, login cleared', empty($st['config']['id']) && $st['config']['generation'] === 1 && empty($st['login']), $st);
$dv = module_for($sid, true)->dashboard_view();
$check('client area after cancel shows a friendly error', $dv['error'] !== '', $dv);
$check('cancel again is still success', core_run($sid, 'terminate')['ok'] === true);
$r = core_run($sid, 'create');
$st = $GLOBALS['db']['services'][$sid]['options'];
$newLine = (int) ($st['config']['id'] ?? 0);
$cleanupLines[] = $newLine;
$check('create after cancel sells a NEW line', $r['ok'] === true && $newLine > 0 && $newLine !== $lineId, $r);
$check('panel: the new line is active, the old one stays gone', (line_of($newLine)['status'] ?? '') === 'active' && line_of($lineId) === null);
$secrets[] = line_of($newLine)['password'];
core_run($sid, 'terminate');
$check('panel: new line deleted by the second cancel', line_of($newLine) === null);

echo "== line service, only disable on terminate\n";
$sid2 = new_service(11, 1);
$check('create', ($r = core_run($sid2, 'create'))['ok'] === true, $r);
$l2 = (int) $GLOBALS['db']['services'][$sid2]['options']['config']['id'];
$cleanupLines[] = $l2;
$secrets[] = line_of($l2)['password'];
$check('cancel disables the line', core_run($sid2, 'terminate')['ok'] === true && (line_of($l2)['status'] ?? '') === 'disabled', line_of($l2));

echo "== line service, errors\n";
$sid3 = new_service(12, 1);
$r = core_run($sid3, 'create');
$check('an unknown package fails readably and stores nothing', $r['ok'] === false && stripos($r['error'], 'package') !== false && empty($GLOBALS['db']['services'][$sid3]['options']['config']['id']), $r);
$r = core_run(new_service(10, 1), 'suspend');
$check('suspend of a service that was never provisioned fails readably', $r['ok'] === false && stripos($r['error'], 'not found') !== false, $r);

echo "== older loader entry points (renewal / delete)\n";
$sid4 = new_service(10, 1);
core_run($sid4, 'create');
$l4 = (int) $GLOBALS['db']['services'][$sid4]['options']['config']['id'];
$cleanupLines[] = $l4;
$secrets[] = line_of($l4)['password'];
$GLOBALS['db']['services'][$sid4]['duedate'] = '2026-12-01 00:00:00';
$e0 = $exp($l4);
$m = module_for($sid4);
$check('renewal() returns true and extends', $m->renewal() === true && $exp($l4) > $e0);
$m = module_for($sid4);
$check('delete() returns true and deletes', $m->delete() === true && line_of($l4) === null);
$m = module_for($sid4);
$check('renewal() on a closed service returns false with a message', $m->renewal() === false && $m->error !== '', $m->error);

// ==========================================================================
echo "== sub-reseller service\n";
$subData = array('service_type' => 'reseller', 'package' => '', 'trial' => 0, 'delete_on_terminate' => 1, 'credits_on_creation' => '50', 'credits_per_renewal' => '20');
$GLOBALS['db']['products'][20] = array('module_data' => $subData);
$sub = new_service(20, 2);
$r = core_run($sub, 'create');
$check('create (generated credentials)', $r['ok'] === true, $r);
$st = $GLOBALS['db']['services'][$sub]['options'];
$uname = $st['login']['username'] ?? '';
$upw = (new class extends ProductModule { function dec($s) { return $this->decode_str($s); } })->dec($st['login']['password'] ?? '');
$secrets[] = $upw;
$pu = sub_of($uname);
$cleanupUsers[] = $pu['id'] ?? '';
$check('generated credentials are valid for the panel', preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $uname) && strlen($upw) >= 8 && $pu !== null, array($uname, strlen($upw)));
$check('panel: account exists with the customer email and the starting credits', $pu && $pu['email'] === "wisecp-$RUN-sub2@example.test" && (int) $pu['credits'] === 50 && $pu['status'] === 'active', $pu);
$check('account id is a UUID in the service options', ($st['config']['id'] ?? '') === $pu['id'] && ($st['config']['credits_done'] ?? 0) === 1, $st['config'] ?? array());
$r = core_run($sub, 'create');
$check('create repeated neither fails nor credits twice', $r['ok'] === true && (int) sub_of($uname)['credits'] === 50, sub_of($uname));
$html = module_for($sub, true)->get_page('dashboard');
$check('client area shows credentials and balance, no playlist', strpos($html, htmlspecialchars($uname)) !== false && strpos($html, htmlspecialchars($upw)) !== false
    && strpos($html, '50') !== false && strpos($html, 'get.php') === false, substr($html, 0, 300));
$ov = module_for($sub, true)->client_overview_data();
$check('client overview shows the balance', ($ov['resources'][0]['value'] ?? '') === '50', $ov);
$check('suspend', ($r = core_run($sub, 'suspend'))['ok'] === true && (sub_of($uname)['status'] ?? '') === 'disabled', $r);
$check('unsuspend', ($r = core_run($sub, 'unsuspend'))['ok'] === true && (sub_of($uname)['status'] ?? '') === 'active', $r);
$GLOBALS['db']['services'][$sub]['duedate'] = '2026-11-01 00:00:00';
$check('renewal tops up 20', core_run($sub, 'renew')['ok'] === true && (int) sub_of($uname)['credits'] === 70, sub_of($uname));
$check('renewal repeated for the same due date does not top up twice', core_run($sub, 'renew')['ok'] === true && (int) sub_of($uname)['credits'] === 70, sub_of($uname));
$GLOBALS['db']['services'][$sub]['duedate'] = '2027-11-01 00:00:00';
$check('renewal of the next period tops up again', core_run($sub, 'renew')['ok'] === true && (int) sub_of($uname)['credits'] === 90, sub_of($uname));
$GLOBALS['db']['products'][21] = array('module_data' => array_merge($subData, array('credits_per_renewal' => '0')));
$zero = new_service(21, 3);
core_run($zero, 'create');
$zname = $GLOBALS['db']['services'][$zero]['options']['login']['username'];
$cleanupUsers[] = sub_of($zname)['id'] ?? '';
$c = (int) sub_of($zname)['credits'];
$check('credits per renewal 0: renewal succeeds and does nothing', core_run($zero, 'renew')['ok'] === true && (int) sub_of($zname)['credits'] === $c);
core_run($zero, 'terminate');

$check('cancel disables the account (never deletes it)', core_run($sub, 'terminate')['ok'] === true && (sub_of($uname)['status'] ?? '') === 'disabled', sub_of($uname));
$st = $GLOBALS['db']['services'][$sub]['options'];
$check('service closed: generation 1, login cleared', empty($st['config']['id']) && $st['config']['generation'] === 1 && empty($st['login']), $st);
$check('cancel again is still success', core_run($sub, 'terminate')['ok'] === true);
$r = core_run($sub, 'create');
$st = $GLOBALS['db']['services'][$sub]['options'];
$u2 = sub_of($st['login']['username'] ?? '');
$cleanupUsers[] = $u2['id'] ?? '';
$check('create after cancel makes a NEW account (new username, tagged email) with its credits',
    $r['ok'] === true && $u2 && $u2['id'] !== $pu['id'] && $u2['username'] !== $uname && (int) $u2['credits'] === 50 && $u2['email'] === "wisecp-$RUN-sub2+g1@example.test", array($r, $u2));
$secrets[] = (new class extends ProductModule { function dec($s) { return $this->decode_str($s); } })->dec($st['login']['password'] ?? '');
core_run($sub, 'terminate');

echo "== sub-reseller service, too few credits fails at once; a transfer that did not happen resumes\n";
$GLOBALS['db']['products'][22] = array('module_data' => array_merge($subData, array('credits_on_creation' => '999999999')));
$res = new_service(22, 4);
$usersBefore = count(panel('get_users', array('start' => 0, 'limit' => 500))['data'] ?? array());
$r = core_run($res, 'create');
$st = $GLOBALS['db']['services'][$res]['options'];
$check('too few credits for the account plus its starting credits fail at once with the amounts and create no account',
    $r['ok'] === false && stripos($r['error'], 'needs 1000000009 credits') !== false && empty($st['config']['id']) && count(panel('get_users', array('start' => 0, 'limit' => 500))['data'] ?? array()) === $usersBefore, $r);
// The resume path (the transfer failed after the account was made, e.g. a race with another sale): an account that exists, credits not handed over yet.
$GLOBALS['db']['products'][22]['module_data']['credits_on_creation'] = '0';
$r = core_run($res, 'create');
$st = $GLOBALS['db']['services'][$res]['options'];
$rname = $st['login']['username'] ?? '';
$ru = sub_of($rname);
$cleanupUsers[] = $ru['id'] ?? '';
$check('an account is created with no credits to hand over', $r['ok'] === true && $ru && ($st['config']['id'] ?? '') === $ru['id'] && (int) $ru['credits'] === 0, array($r, $st['config'] ?? null));
$GLOBALS['db']['products'][22]['module_data']['credits_on_creation'] = '30';
$before = credits();
$r = core_run($res, 'create');
$check('create again only completes the transfer (no second account, no second price)',
    $r['ok'] === true && (int) sub_of($rname)['credits'] === 30 && credits() === $before - 30, array($r, sub_of($rname), $before, credits()));
$check('and once more does nothing', core_run($res, 'create')['ok'] === true && (int) sub_of($rname)['credits'] === 30);
core_run($res, 'terminate');

echo "== sub-reseller service, errors\n";
$GLOBALS['db']['products'][23] = array('module_data' => array_merge($subData, array('credits_on_creation' => 'abc')));
$bad = new_service(23, 5);
$r = core_run($bad, 'create');
$check('invalid credits are refused before anything is created', $r['ok'] === false && stripos($r['error'], 'whole number') !== false && empty($GLOBALS['db']['services'][$bad]['options']['config']['id']), $r);

// ==========================================================================
echo "== secrets\n";
$all = '';
foreach ($GLOBALS['logs'] as $entry) { $all .= $entry[1] . "\n"; }
$check('the leak check had real secrets to look for', count(array_unique(array_filter($secrets))) >= 6, (string) count($secrets));
$check('the module logged its calls', count($GLOBALS['logs']) > 20, (string) count($GLOBALS['logs']));
$leak = '';
foreach (array_unique(array_filter($secrets)) as $s) {
    if (strpos($all, $s) !== false) { $leak = $s === $API_KEY ? 'API key' : 'a password'; break; }
}
$check('neither the API key nor any password reached a log', $leak === '', $leak);
$check('no clear password= in any logged link', !preg_match('/password=(?!\*)[^&"\\\\]/', $all));
$dbJson = json_encode($GLOBALS['db']['services']);
$stored = '';
foreach (array_unique(array_filter($secrets)) as $s) { if ($s !== $API_KEY && strpos($dbJson, $s) !== false) { $stored = 'a password'; } }
$check('no password is stored in clear on a service', $stored === '', $stored);
$check('the API key is not stored in a service or in the config file in clear', strpos($dbJson, $API_KEY) === false && strpos(json_encode($GLOBALS['store']), $API_KEY) === false);
$check('every logged request carries the panel URL and no key header', strpos($all, 'X-API-Key') === false);

// ---- clean up what is left on the shared panel -----------------------------
foreach (array_unique($cleanupLines) as $id) { if ($id) { panel('delete_line', array('id' => $id), true); } }
foreach (array_unique(array_filter($cleanupUsers)) as $id) { panel('disable_user', array('id' => $id), true); }

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
