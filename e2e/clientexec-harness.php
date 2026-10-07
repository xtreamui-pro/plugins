<?php
// Stand-in for the parts of ClientExec the Xtream UI Pro server plugin touches,
// to run the real plugin and the real core (lib/Client.php, lib/Provisioner.php)
// against a panel API. Not shipped.
//
// ClientExec is commercial (licence needed), so it cannot be started. This file
// re-implements, from the open-source server plugins of github.com/clientexec
// (DirectAdmin, Virtualizor, Enhance), only what the plugin uses: ServerPlugin
// ::buildParams, UserPackage custom fields, CE_Exception, CE_Lib::log, lang().
// Every place where that behaviour is a guess is listed in plugins/clientexec/README.md.
//
//   PLUGIN_DIR=.../plugins/clientexec/plugins/server/xtreampro \
//   API_PORT_NUM=18095 API_KEY=... php plugins/e2e/clientexec-harness.php
//   (API_HOST overrides 127.0.0.1)

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

define('CUSTOM_FIELDS_FOR_PACKAGE', 2);
$GLOBALS['celog'] = array();   // everything the plugin writes with CE_Lib::log

function lang($s) { return $s; }
class CE_Exception extends Exception {}
class CE_Lib
{
    public static function log($level, $msg) { $GLOBALS['celog'][] = (string) $msg; }
    public static function getSessionHash() { return 'hash'; }
}
class User
{
    public function __construct($id) {}
    public function getFirstName() { return 'Ann'; }
    public function getLastname() { return 'Berg'; }
}
class UserPackage
{
    /** @var array package id => array(fields => array, vars => array) */
    public static $packages = array();
    private $id;
    public function __construct($id) { $this->id = (int) $id; }
    public function getId() { return $this->id; }
    public function setCustomField($name, $value, $type = null)
    {
        // ClientExec keeps text in html-encoded form; mimic it so html_entity_decode matters.
        self::$packages[$this->id]['fields'][$name] = htmlspecialchars((string) $value, ENT_QUOTES);
    }
    public function getCustomField($name, $type = null)
    {
        return self::$packages[$this->id]['fields'][$name] ?? '';
    }
}
class ServerPlugin
{
    public $user;
    public static $server = array();
    public static $email = '';
    public function __construct() { $this->user = new class { function lang($s) { return $s; } }; }
    // The shape of the array the open-source plugins read from buildParams().
    public function buildParams($userPackage, $changes = null)
    {
        $p = UserPackage::$packages[$userPackage->getId()];
        $args = array(
            'server' => array('variables' => self::$server),
            'package' => array(
                'id' => $userPackage->getId(),
                'username' => $p['fields']['User Name'] ?? '',
                'password' => $p['fields']['Password'] ?? '',
                'ServerAcctProperties' => $p['fields']['Server Acct Properties'] ?? '',
                'variables' => $p['vars'],
                'name_on_server' => '',
            ),
            'customer' => array('id' => 1, 'email' => self::$email),
        );
        if (is_array($changes) && isset($changes['changes'])) {
            $args['changes'] = $changes['changes'];
        }
        return $args;
    }
}

$pluginDir = rtrim((string) getenv('PLUGIN_DIR'), '/');
$tmp = sys_get_temp_dir() . '/ce-harness-' . getmypid();
mkdir($tmp . '/modules/admin/models', 0777, true);
file_put_contents($tmp . '/modules/admin/models/ServerPlugin.php', "<?php\n");  // the stand-in above is already loaded
chdir($tmp);
register_shutdown_function(function () use ($tmp) {
    restore_error_handler();
    @unlink($tmp . '/modules/admin/models/ServerPlugin.php');
    foreach (array('/modules/admin/models', '/modules/admin', '/modules', '') as $d) { @rmdir($tmp . $d); }
});
require $pluginDir . '/PluginXtreampro.php';

$KEY = (string) getenv('API_KEY');
$host = getenv('API_HOST') ?: '127.0.0.1';
ServerPlugin::$server = array(
    'ServerHostName' => $host,
    'plugin_xtreampro_API_Key' => $KEY,
    'plugin_xtreampro_Use_SSL' => '0',
    'plugin_xtreampro_Port' => (string) getenv('API_PORT_NUM'),
    'plugin_xtreampro_Playlist_URL_Custom_Field' => 'Playlist URL',
    'plugin_xtreampro_Web_Player_URL_Custom_Field' => 'Web Player',
    'plugin_xtreampro_Server_URL_Custom_Field' => 'Server URL',
    'plugin_xtreampro_Credit_Balance_Custom_Field' => 'Credits',
);
// Independent view of the panel, to check what the plugin did.
$panel = new XtreamPro\ClientExec\Client('http://' . $host . ':' . getenv('API_PORT_NUM'), $KEY);

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    if (!$ok) { $fail++; }
};
$plugin = new PluginXtreampro();
ServerPlugin::$email = 'ce-' . getmypid() . '-a@example.test';
$secrets = array();   // every password that existed during the run
$rememberPw = function ($id) use (&$secrets) {
    $pw = html_entity_decode(UserPackage::$packages[$id]['fields']['Password'] ?? '');
    if ($pw !== '') { $secrets[$pw] = true; }
};
$errorOf = function (callable $fn) {
    try { $fn(); } catch (CE_Exception $e) { return $e->getMessage(); }
    return null;
};
$newPackage = function ($id, array $vars, array $fields = array()) {
    UserPackage::$packages[$id] = array('fields' => $fields, 'vars' => $vars);
};
$f = function ($id, $name) { return html_entity_decode(UserPackage::$packages[$id]['fields'][$name] ?? ''); };
$uniq = getmypid() % 100000;

echo "== plugin definition\n";
$vars = $plugin->getVariables();
$check('hidden Name is Xtreampro', ($vars['Name']['value'] ?? '') === 'Xtreampro');
$check('API key is an encrypted password variable', ($vars['API Key']['type'] ?? '') === 'password' && !empty($vars['API Key']['encryptable']));
$pv = $vars['package_vars_values']['value'] ?? array();
$check('package variables', array_keys($pv) === array('service_type', 'panel_package_id', 'trial', 'delete_on_terminate', 'credits_on_creation', 'credits_per_renewal'), array_keys($pv));
$check('service type options', array_keys($plugin->getServiceTypes()) === array('line', 'reseller'));
$check('Renew is a registered action', strpos($vars['Actions']['value'], 'Renew') !== false && method_exists($plugin, 'doRenew'));
$ini = parse_ini_file($pluginDir . '/resource/plugin.ini', true);
$check('plugin.ini is valid and says type = server', ($ini['meta']['type'] ?? '') === 'server' && ($ini['meta']['version'] ?? '') === '1.1.0' && ($ini['features']['changepackage'] ?? '') == 1, $ini);
$check('1.1.0 is in the entry file, the core and the API client (X-Connector)', strpos(file_get_contents($pluginDir . '/PluginXtreampro.php'), '1.1.0') !== false && strpos(file_get_contents($pluginDir . '/lib/Client.php'), "VERSION = '1.1.0'") !== false);
$check('Delete permanently on terminate is off by default', ($pv['delete_on_terminate']['value'] ?? '') === '0' && stripos($pv['delete_on_terminate']['description'], 'final') !== false);

echo "== connection\n";
$args = array('server' => array('variables' => ServerPlugin::$server));
$check('testConnection', $errorOf(function () use ($plugin, $args) { $plugin->testConnection($args); }) === null);
$badArgs = $args; $badArgs['server']['variables']['plugin_xtreampro_API_Key'] = 'xk_wrong';
$msg = $errorOf(function () use ($plugin, $badArgs) { $plugin->testConnection($badArgs); });
$check('wrong key fails readably', $msg !== null && stripos($msg, 'API key') !== false, (string) $msg);
$noHost = $args; $noHost['server']['variables']['ServerHostName'] = '';
$msg = $errorOf(function () use ($plugin, $noHost) { $plugin->testConnection($noHost); });
$check('missing host fails readably', $msg !== null && stripos($msg, 'not configured') !== false, (string) $msg);

$pk = $panel->packages();
$check('panel has packages', count($pk) >= 1);
// A package that is sold as an official period on a plain line (the first one of the seed is a trial).
$sellable = array_values(array_filter($pk, function ($p) { return !empty($p['is_official']) && XtreamPro\ClientExec\Client::sellsLine($p); }));
$boxOnly = array_values(array_filter($pk, function ($p) { return !XtreamPro\ClientExec\Client::sellsLine($p); }));
$check('the panel marks a box-only package with sells without "line"', count($boxOnly) >= 1 && count($sellable) >= 2, $pk);
$check('a panel that sends no sells counts as selling lines', XtreamPro\ClientExec\Client::sellsLine(array('id' => 1)) && !XtreamPro\ClientExec\Client::sellsLine(array('sells' => array('mag'))));
$pkgId = (string) $sellable[0]['id'];
$pkgId2 = (string) $sellable[1]['id'];
$boxId = (string) $boxOnly[0]['id'];

echo "== 1.1.0: pricing, sells, connector name, spent request id\n";
$B = 2900 + $uniq;
$newPackage($B, array('service_type' => 'line', 'panel_package_id' => $boxId, 'trial' => '0', 'delete_on_terminate' => '1'), array('User Name' => 'cex' . getmypid() . 'box', 'Password' => 'Passw0rd-box' . getmypid()));
$creditsBefore = (int) $panel->userInfo()['credits'];
$err = (string) $errorOf(function () use ($plugin, $B) { $plugin->doCreate(array('userPackageId' => $B)); });
$check('a box-only package is refused before anything is sold, with a readable reason', stripos($err, 'boxes only') !== false && (int) $panel->userInfo()['credits'] === $creditsBefore && ($f($B, 'Server Acct Properties') === ''), $err);
$cn = array();
foreach (($panel->get('api_logs', array('limit' => 50))['data'] ?? array()) as $row) { $cn[] = $row['connector'] ?? ''; }
$check('the panel call log names the connector', in_array('clientexec/1.1.0', $cn, true), array_unique($cn));
$SP = 2800 + $uniq;
$newPackage($SP, array('service_type' => 'line', 'panel_package_id' => $pkgId, 'trial' => '0', 'delete_on_terminate' => '1'), array('User Name' => 'cex' . getmypid() . 'sp', 'Password' => 'Passw0rd-sp' . getmypid()));
$secrets['Passw0rd-sp' . getmypid()] = true;
$plugin->doCreate(array('userPackageId' => $SP));
$spId = (int) explode('|', $f($SP, 'Server Acct Properties'))[0];
$panel->lineAction('delete_line', $spId);
UserPackage::$packages[$SP]['fields']['Server Acct Properties'] = '';
$err = (string) $errorOf(function () use ($plugin, $SP) { $plugin->doCreate(array('userPackageId' => $SP)); });
$check('the same request id after its line was deleted on the panel gives a readable message (REQUEST_ID_SPENT)', stripos($err, 'already made') !== false && strpos($err, 'REQUEST_ID') === false, $err);

echo "== line service\n";
$L = 3000 + $uniq;
$newPackage($L, array('service_type' => 'line', 'panel_package_id' => $pkgId, 'trial' => '0', 'delete_on_terminate' => '1', 'credits_on_creation' => '0', 'credits_per_renewal' => '0'),
    array('User Name' => 'cex' . getmypid() . 'a', 'Password' => 'Passw0rd-' . getmypid()));
$secrets['Passw0rd-' . getmypid()] = true;
$check('available before create: Create only', $plugin->getAvailableActions(new UserPackage($L)) === array('Create'));
$msg = $plugin->doCreate(array('userPackageId' => $L));
$check('doCreate', strpos($msg, 'has been created') !== false, $msg);
$rememberPw($L);
$props = $f($L, 'Server Acct Properties');
$lineId = (int) explode('|', $props)[0];
$check('panel id stored as "<id>|0"', $lineId > 0 && substr($props, -2) === '|0', $props);
$line = $panel->getLine($lineId);
$check('line exists in the panel with the stored username', ($line['username'] ?? '') === $f($L, 'User Name'), $line);
$check('playlist / player / server fields filled for the customer',
    strpos($f($L, 'Playlist URL'), '/get.php') !== false && $f($L, 'Web Player') !== '' && $f($L, 'Server URL') !== '', UserPackage::$packages[$L]['fields']);
$check('actions after create', $plugin->getAvailableActions(new UserPackage($L)) === array('Suspend', 'Delete', 'Renew'), $plugin->getAvailableActions(new UserPackage($L)));
$plugin->doCreate(array('userPackageId' => $L));
$rows = $panel->get('get_lines', array('search' => $f($L, 'User Name'), 'limit' => 50))['data'];
$check('replay of create sells no second line', count($rows) === 1 && (int) $rows[0]['id'] === $lineId, $rows);
$check('suspend', strpos($plugin->doSuspend(array('userPackageId' => $L)), 'suspended') !== false);
$check('panel: disabled', ($panel->getLine($lineId)['status'] ?? '') === 'disabled');
$check('actions while suspended offer UnSuspend', in_array('UnSuspend', $plugin->getAvailableActions(new UserPackage($L)), true));
$check('unsuspend', strpos($plugin->doUnSuspend(array('userPackageId' => $L)), 'unsuspended') !== false);
$check('panel: active', ($panel->getLine($lineId)['status'] ?? '') === 'active');
$before = (int) $panel->getLine($lineId)['exp_date'];
$check('renew', strpos($plugin->doRenew(array('userPackageId' => $L)), 'renewed') !== false);
$after = (int) $panel->getLine($lineId)['exp_date'];
$check('expiry moved by the renewal', $after > $before, "$before -> $after");
$plugin->doRenew(array('userPackageId' => $L));
$check('renew repeated the same day does not extend twice', (int) $panel->getLine($lineId)['exp_date'] === $after);
$link = $plugin->dopanellogin(array('userPackageId' => $L));
$check('direct link is the web player', $link !== '' && strpos($link, '/player') !== false, $link);
// Upgrade / downgrade: ClientExec calls update() with the new package's variables in $args['package'] (assumed).
$balBefore = (int) $panel->userInfo()['credits'];
$oldVars = UserPackage::$packages[$L]['vars'];
UserPackage::$packages[$L]['vars']['panel_package_id'] = $boxId;
$err = (string) $errorOf(function () use ($plugin, $L) { $plugin->doUpdate(array('userPackageId' => $L, 'changes' => array('package' => 1))); });
$check('update: a change to a box-only package is refused readably and sells nothing', stripos($err, 'boxes only') !== false && (int) $panel->getLine($lineId)['package_id'] === (int) $pkgId && (int) $panel->userInfo()['credits'] === $balBefore, $err);
UserPackage::$packages[$L]['vars']['panel_package_id'] = $pkgId2;
$err = $errorOf(function () use ($plugin, $L) { $plugin->doUpdate(array('userPackageId' => $L, 'changes' => array('package' => 1))); });
$check('update: a package change sells the new package on the line', $err === null && (int) $panel->getLine($lineId)['package_id'] === (int) $pkgId2, (string) $err);
$check('and was charged', (int) $panel->userInfo()['credits'] < $balBefore);
$balAfter = (int) $panel->userInfo()['credits'];
$plugin->doUpdate(array('userPackageId' => $L, 'changes' => array('package' => 1)));
$check('repeating the change is not charged again', (int) $panel->userInfo()['credits'] === $balAfter);
$check('the log keeps what the change did to the time left and what it cost', count(array_filter($GLOBALS['celog'], function ($m) { return strpos($m, 'package changed. On the panel') !== false && strpos($m, 'credits.') !== false; })) === 1, $GLOBALS['celog']);
UserPackage::$packages[$L]['vars'] = $oldVars;
$check('update: password change is explained', stripos((string) $errorOf(function () use ($plugin, $L) { $plugin->doUpdate(array('userPackageId' => $L, 'changes' => array('password' => 'x'))); }), 'password is not available') !== false);
$check('terminate (delete)', strpos($plugin->doDelete(array('userPackageId' => $L)), 'deleted') !== false);
$gone = null;
try { $panel->getLine($lineId); } catch (XtreamPro\ClientExec\ApiException $e) { $gone = $e->getErrorCode(); }
$check('panel: line is gone', $gone === 'RESOURCE_NOT_FOUND', (string) $gone);
$check('state keeps the generation: "|1"', $f($L, 'Server Acct Properties') === '|1', $f($L, 'Server Acct Properties'));
$check('available after terminate: Create only', $plugin->getAvailableActions(new UserPackage($L)) === array('Create'));
$check('terminate again is still fine', strpos($plugin->doDelete(array('userPackageId' => $L)), 'deleted') !== false);
$check('state after the second terminate is "|2"', $f($L, 'Server Acct Properties') === '|2', $f($L, 'Server Acct Properties'));
UserPackage::$packages[$L]['fields']['User Name'] = 'cex' . getmypid() . 'b';
UserPackage::$packages[$L]['fields']['Server Acct Properties'] = '|1';   // as after one terminate
$plugin->doCreate(array('userPackageId' => $L));
$rememberPw($L);
$line2 = (int) explode('|', $f($L, 'Server Acct Properties'))[0];
$check('create after terminate sells a new line', $line2 > 0 && $line2 !== $lineId && substr($f($L, 'Server Acct Properties'), -2) === '|1', $f($L, 'Server Acct Properties'));
$plugin->doDelete(array('userPackageId' => $L));

echo "== line service, disable on terminate, trial\n";
$D = 3500 + $uniq;
$newPackage($D, array('service_type' => 'line', 'panel_package_id' => $pkgId, 'trial' => '0', 'delete_on_terminate' => '0'), array('User Name' => 'cex' . getmypid() . 'c'));
$plugin->doCreate(array('userPackageId' => $D));
$rememberPw($D);
$dId = (int) explode('|', $f($D, 'Server Acct Properties'))[0];
$check('blank password: the panel made one and it was stored', $f($D, 'Password') !== '');
$check('terminate with Delete=no only disables', strpos($plugin->doDelete(array('userPackageId' => $D)), 'disabled') !== false && ($panel->getLine($dId)['status'] ?? '') === 'disabled');
$panel->lineAction('delete_line', $dId);   // clean up the panel

echo "== errors\n";
$E = 3600 + $uniq;
$newPackage($E, array('service_type' => 'line', 'panel_package_id' => '99999999'), array('User Name' => 'cex' . getmypid() . 'e'));
$msg = $errorOf(function () use ($plugin, $E) { $plugin->doCreate(array('userPackageId' => $E)); });
$check('unknown package is readable', $msg !== null && stripos($msg, 'package') !== false, (string) $msg);
$newPackage($E, array('service_type' => 'line', 'panel_package_id' => ''), array());
$msg = $errorOf(function () use ($plugin, $E) { $plugin->doCreate(array('userPackageId' => $E)); });
$check('no package set is readable', $msg !== null && stripos($msg, 'package') !== false, (string) $msg);
$good = ServerPlugin::$server;
ServerPlugin::$server['plugin_xtreampro_API_Key'] = 'xk_wrong';
$newPackage($E, array('service_type' => 'line', 'panel_package_id' => $pkgId), array('User Name' => 'cex' . getmypid() . 'f'));
$msg = $errorOf(function () use ($plugin, $E) { $plugin->doCreate(array('userPackageId' => $E)); });
$check('create with a wrong key is readable', $msg !== null && stripos($msg, 'API key') !== false, (string) $msg);
ServerPlugin::$server = $good;

echo "== sub-reseller service\n";
$S = 4000 + $uniq;
$newPackage($S, array('service_type' => 'reseller', 'panel_package_id' => '', 'trial' => '0', 'delete_on_terminate' => '1', 'credits_on_creation' => '50', 'credits_per_renewal' => '20'), array());
$msg = $plugin->doCreate(array('userPackageId' => $S));
$check('doCreate (generated credentials)', strpos($msg, 'has been created') !== false, $msg);
$rememberPw($S);
$check('generated credentials saved', strlen($f($S, 'User Name')) >= 3 && strlen($f($S, 'Password')) >= 8, UserPackage::$packages[$S]['fields']);
$uid = explode('|', $f($S, 'Server Acct Properties'))[0];
$user = $panel->findSubUser($uid, $f($S, 'User Name'));
$check('panel: account with a balance of 50', $user !== null && (string) $user['credits'] === '50', $user);
$check('credit balance field filled', $f($S, 'Credits') === '50', $f($S, 'Credits'));
$plugin->doCreate(array('userPackageId' => $S));
$user = $panel->findSubUser($uid, $f($S, 'User Name'));
$check('replay of create neither duplicates nor credits twice', (string) $user['credits'] === '50' && count($panel->subUsers($f($S, 'User Name'))) === 1, $user);
$check('actions', $plugin->getAvailableActions(new UserPackage($S)) === array('Suspend', 'Delete', 'Renew'));
$plugin->doSuspend(array('userPackageId' => $S));
$check('panel: disabled', ($panel->findSubUser($uid, '')['status'] ?? '') === 'disabled');
$plugin->doUnSuspend(array('userPackageId' => $S));
$check('panel: active', ($panel->findSubUser($uid, '')['status'] ?? '') === 'active');
$check('renew tops up', strpos($plugin->doRenew(array('userPackageId' => $S)), 'renewed') !== false);
$check('balance is 70', (string) $panel->findSubUser($uid, '')['credits'] === '70', $panel->findSubUser($uid, ''));
$plugin->doRenew(array('userPackageId' => $S));
$check('renew repeated the same day does not top up twice', (string) $panel->findSubUser($uid, '')['credits'] === '70');
$msg = $errorOf(function () use ($plugin, $S) { $plugin->getDirectLink(new UserPackage($S)); });
$check('no direct link for a sub-reseller', $msg !== null && stripos($msg, 'dashboard') !== false, (string) $msg);
$check('terminate (disables)', strpos($plugin->doDelete(array('userPackageId' => $S)), 'disabled') !== false);
$check('panel: disabled after terminate', ($panel->findSubUser($uid, '')['status'] ?? '') === 'disabled');
$check('state "|1"', $f($S, 'Server Acct Properties') === '|1');
UserPackage::$packages[$S]['fields']['User Name'] = 'cexs' . getmypid() . 'n';
UserPackage::$packages[$S]['fields']['Password'] = '';
// The panel has no delete for accounts, and an email address is unique: the disabled account still holds the old one.
ServerPlugin::$email = 'ce-' . getmypid() . '-b@example.test';
$plugin->doCreate(array('userPackageId' => $S));
$rememberPw($S);
$uid2 = explode('|', $f($S, 'Server Acct Properties'))[0];
$check('create after terminate sells a new account', $uid2 !== '' && $uid2 !== $uid && (string) $panel->findSubUser($uid2, '')['credits'] === '50');
$plugin->doDelete(array('userPackageId' => $S));
$Z = 4500 + $uniq;
ServerPlugin::$email = 'ce-' . getmypid() . '-c@example.test';
$newPackage($Z, array('service_type' => 'reseller', 'credits_on_creation' => '0', 'credits_per_renewal' => '0'), array('User Name' => 'cexz' . getmypid()));
$plugin->doCreate(array('userPackageId' => $Z));
$check('renew with 0 credits does nothing, says so', strpos($plugin->doRenew(array('userPackageId' => $Z)), 'nothing to renew') !== false);
$check('actions hide Renew then', $plugin->getAvailableActions(new UserPackage($Z)) === array('Suspend', 'Delete'));
$plugin->doDelete(array('userPackageId' => $Z));
$newPackage($Z, array('service_type' => 'reseller', 'credits_on_creation' => 'abc'), array());
$msg = $errorOf(function () use ($plugin, $Z) { $plugin->doCreate(array('userPackageId' => $Z)); });
$check('bad credits value is readable', $msg !== null && stripos($msg, 'whole number') !== false, (string) $msg);

echo "== logging\n";
$all = implode("\n", $GLOBALS['celog']);
$check('something was logged', count($GLOBALS['celog']) > 20);
$check('the API key never reached the log', $KEY !== '' && strpos($all, $KEY) === false);
$leak = '';
foreach (array_keys($secrets) as $pw) { if ($pw !== '' && strpos($all, $pw) !== false) { $leak = 'a password'; } }
$check('no line or account password in the log', $leak === '' && count($secrets) >= 3, $leak . ' (' . count($secrets) . ' passwords checked)');
echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
