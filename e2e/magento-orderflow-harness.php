<?php
// Runs Model/OrderService.php of the Magento connector (the real file, plus the real core) against a
// panel API, with stand-ins for what Magento would provide: orders, order items, product repository,
// order comments, lock manager and the module's own database tables (kept in memory). It exercises the
// order logic: claim, provision, replay, failure and retry, guest refusal, partial refund, full refund,
// a second order of the same customer. The SQL of Model/Storage.php and every observer, controller,
// block and template are NOT run here (they need Magento): they are only linted. Not shipped.
//
//   API_PORT_NUM=18095 API_KEY_FILE=/path/to/key php plugins/e2e/magento-orderflow-harness.php
//
// Prints ALL OK and exits 0 when every check passed.
namespace Magento\Framework\Exception { class NoSuchEntityException extends \Exception {} }
namespace Magento\Catalog\Api { interface ProductRepositoryInterface { public function getById($id); } }
namespace Magento\Framework\Lock { interface LockManagerInterface { public function lock(string $name, int $timeout = -1): bool; public function unlock(string $name): bool; } }
namespace Magento\Sales\Api {
    interface OrderRepositoryInterface { public function get($id); }
    interface OrderStatusHistoryRepositoryInterface { public function save($entity); }
}
namespace Psr\Log {
    interface LoggerInterface {
        public function error($message, array $context = array());
        public function warning($message, array $context = array());
        public function log($level, $message, array $context = array());
    }
}
namespace Magento\Eav\Model\Entity\Attribute\Source { abstract class AbstractSource { protected $_options; } }
namespace Magento\Sales\Model\Order {
    class Item
    {
        public $d;
        function __construct(array $d) { $this->d = $d + array('parent_item_id' => null, 'product_type' => 'virtual', 'qty_invoiced' => 0, 'qty_refunded' => 0); }
        function getId() { return $this->d['id']; }
        function getParentItemId() { return $this->d['parent_item_id']; }
        function getQtyInvoiced() { return $this->d['qty_invoiced']; }
        function getQtyRefunded() { return $this->d['qty_refunded']; }
        function getProductType() { return $this->d['product_type']; }
        function getProductId() { return $this->d['product_id']; }
        function getName() { return $this->d['name']; }
    }
}
namespace Magento\Sales\Model {
    class Order
    {
        const STATE_CANCELED = 'canceled';
        public $d; public $items = array(); public $comments = array();
        function __construct(array $d) { $this->d = $d; }
        function getId() { return $this->d['id']; }
        function getIncrementId() { return $this->d['increment_id']; }
        function getCustomerId() { return $this->d['customer_id']; }
        function getCustomerEmail() { return $this->d['email']; }
        function getCustomerFirstname() { return 'Ann'; }
        function getCustomerLastname() { return 'Berg'; }
        function getState() { return $this->d['state'] ?? 'processing'; }
        function hasInvoices() { return true; }
        function getAllItems() { return $this->items; }
        function getItemById($id) { foreach ($this->items as $i) { if ($i->getId() == $id) { return $i; } } return null; }
        function addCommentToStatusHistory($text) { $this->comments[] = $text; return $text; }
    }
}
namespace {
    error_reporting(E_ALL);
    set_error_handler(function ($no, $str, $file, $line) { echo "  PHP warning: $str ($file:$line)\n"; $GLOBALS['warnings']++; return true; });
    $GLOBALS['warnings'] = 0;

    // Magento's __(): placeholders %1, %2...
    class Phrase { private $t; function __construct($t) { $this->t = $t; } function __toString() { return $this->t; } }
    function __($text, ...$args) { foreach ($args as $i => $a) { $text = str_replace('%' . ($i + 1), (string) $a, $text); } return new Phrase($text); }

    spl_autoload_register(function ($class) {
        $prefix = 'XtreamPro\\Connector\\';
        if (strpos($class, $prefix) === 0) {
            require __DIR__ . '/../magento/app/code/XtreamPro/Connector/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });

    use Magento\Sales\Model\Order;
    use Magento\Sales\Model\Order\Item;
    use XtreamPro\Connector\Model\Gateway;
    use XtreamPro\Connector\Model\OrderService;
    use XtreamPro\Connector\Model\Storage;

    class FakeConfig extends XtreamPro\Connector\Model\Config {
        public $url; public $key;
        function __construct($url, $key) { $this->url = $url; $this->key = $key; }
        function getApiUrl(): string { return $this->url; }
        function getApiKey(): string { return $this->key; }
        function getPanelUrl(): string { return ''; }
    }
    class FakeLogger implements Psr\Log\LoggerInterface {
        public $lines = array();
        function error($m, array $c = array()) { $this->log('error', $m, $c); }
        function warning($m, array $c = array()) { $this->log('warning', $m, $c); }
        function log($l, $m, array $c = array()) { $this->lines[] = json_encode(array($l, (string) $m, $c)); }
    }
    class FakeLocks implements Magento\Framework\Lock\LockManagerInterface {
        public $held = array();
        function lock(string $n, int $t = -1): bool { if (isset($this->held[$n])) { return false; } $this->held[$n] = true; return true; }
        function unlock(string $n): bool { unset($this->held[$n]); return true; }
    }
    class FakeProducts implements Magento\Catalog\Api\ProductRepositoryInterface {
        public $products = array();
        function getById($id) {
            if (!isset($this->products[$id])) { throw new Magento\Framework\Exception\NoSuchEntityException('no product'); }
            return new class($this->products[$id]) { private $d; function __construct($d) { $this->d = $d; } function getData($k) { return $this->d[$k] ?? null; } };
        }
    }
    class FakeOrders implements Magento\Sales\Api\OrderRepositoryInterface { function get($id) { throw new Magento\Framework\Exception\NoSuchEntityException(); } }
    class FakeHistory implements Magento\Sales\Api\OrderStatusHistoryRepositoryInterface { public $saved = array(); function save($e) { $this->saved[] = (string) $e; } }

    /** The module's two tables, in memory. Same rules as Model/Storage.php (unique item+unit, one account per customer). */
    class FakeStorage extends Storage {
        public $units = array(); public $accounts = array(); private $next = 1;
        function __construct() {}
        function claimUnits(array $row, int $quantity): void {
            for ($u = 1; $u <= $quantity; $u++) {
                foreach ($this->units as $x) { if ($x['order_item_id'] == $row['order_item_id'] && $x['unit'] == $u) { continue 2; } }
                $id = $this->next++;
                $this->units[$id] = array(
                    'unit_id' => $id, 'order_id' => $row['order_id'], 'order_item_id' => $row['order_item_id'], 'unit' => $u,
                    'customer_id' => $row['customer_id'] ?: null, 'kind' => $row['kind'], 'package_id' => $row['package_id'] ?: null,
                    'trial' => empty($row['trial']) ? 0 : 1, 'credits' => $row['credits'], 'credits_given' => 0, 'credits_taken_back' => 0,
                    'status' => 'pending', 'panel_id' => null, 'username' => null, 'password' => '', 'links' => array(), 'attempts' => 0, 'last_error' => null,
                    'item_name' => 'item', 'increment_id' => '1',
                );
            }
        }
        function unitsOfOrder(int $orderId): array { return array_values(array_filter($this->units, function ($u) use ($orderId) { return $u['order_id'] == $orderId; })); }
        function unitsOfCustomer(int $c): array { return array_values(array_filter($this->units, function ($u) use ($c) { return $u['customer_id'] == $c; })); }
        function hasUnits(int $o): bool { return (bool) $this->unitsOfOrder($o); }
        function hasOpenUnits(int $o): bool { foreach ($this->unitsOfOrder($o) as $u) { if (in_array($u['status'], array('pending', 'failed', 'revoking'), true)) { return true; } } return false; }
        function updateUnit(int $id, array $changes): void { $this->units[$id] = $changes + $this->units[$id]; }
        function failUnit(int $id, string $message, string $status = 'failed'): void { $this->units[$id]['status'] = $status; $this->units[$id]['last_error'] = $message; $this->units[$id]['attempts']++; }
        function resellerOf(int $c): ?array { return $this->accounts[$c] ?? null; }
        function claimReseller(int $c, string $username, string $password): void {
            if (!isset($this->accounts[$c])) { $this->accounts[$c] = array('customer_id' => $c, 'panel_user_id' => null, 'username' => $username, 'password' => $password); }
        }
        function saveReseller(int $c, string $id, string $username, string $password): void { $this->accounts[$c] = array('customer_id' => $c, 'panel_user_id' => $id, 'username' => $username, 'password' => $password); }
        function activeResellerUnits(int $c, int $except): int { return count(array_filter($this->units, function ($u) use ($c, $except) { return $u['customer_id'] == $c && $u['kind'] == 'reseller' && $u['status'] == 'provisioned' && $u['unit_id'] != $except; })); }
    }

    $key = getenv('API_KEY');
    if (!$key && getenv('API_KEY_FILE')) { $key = trim((string) file_get_contents(getenv('API_KEY_FILE'))); }
    if (!$key) { fwrite(STDERR, "Set API_KEY or API_KEY_FILE\n"); exit(2); }
    $base = 'http://' . (getenv('API_HOST') ?: '127.0.0.1') . ':' . (getenv('API_PORT_NUM') ?: '18095');

    $fail = 0;
    $check = function ($label, $ok, $detail = '') use (&$fail) {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
        if (!$ok) { $fail++; }
    };
    $panel = function ($action, array $query = array()) use ($base, $key) {
        $ch = curl_init($base . '/reseller/v1?' . http_build_query(array('action' => $action) + $query));
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array('X-API-Key: ' . $key)));
        $body = curl_exec($ch); curl_close($ch);
        return json_decode((string) $body, true);
    };

    // package id of an official package
    $pk = $panel('packages');
    $pkgId = 0;
    foreach ($pk['data'] as $p) { if (!empty($p['is_official'])) { $pkgId = (int) $p['id']; break; } }

    $run = getmypid() . mt_rand(100, 999);
    $n0 = 700000000 + (int) substr($run, -7) * 10;      // unique ids for orders / items / customers (they feed the request ids)
    $logger = new FakeLogger();
    $config = new FakeConfig($base, $key);
    $storage = new FakeStorage();
    $history = new FakeHistory();
    $products = new FakeProducts();
    $products->products = array(
        1 => array('xtreampro_kind' => 'line', 'xtreampro_package_id' => $pkgId, 'xtreampro_trial' => 0),
        2 => array('xtreampro_kind' => 'reseller', 'xtreampro_credits' => 40),
        3 => array('xtreampro_kind' => ''),
        4 => array('xtreampro_kind' => 'line', 'xtreampro_package_id' => 0),
    );
    $service = new OrderService($storage, new Gateway($config, $logger), $config, $products, new FakeOrders(), $history, new FakeLocks(), $logger);

    $customer = $n0 + 1;
    $mkOrder = function ($id, $customerId, array $items) use ($run) {
        $o = new Order(array('id' => $id, 'increment_id' => '1000' . $id, 'customer_id' => $customerId, 'email' => 'mage-' . $run . '@example.test'));
        foreach ($items as $i) { $o->items[] = new Item($i); }
        return $o;
    };

    echo "== order with 2 lines, 1 reseller unit and a plain product\n";
    $order = $mkOrder($n0 + 10, $customer, array(
        array('id' => $n0 + 101, 'product_id' => 1, 'name' => 'IPTV monthly', 'qty_invoiced' => 2),
        array('id' => $n0 + 102, 'product_id' => 2, 'name' => 'Reseller pack', 'qty_invoiced' => 1),
        array('id' => $n0 + 103, 'product_id' => 3, 'name' => 'T-shirt', 'qty_invoiced' => 5, 'product_type' => 'simple'),
        array('id' => $n0 + 104, 'product_id' => 1, 'name' => 'child row', 'qty_invoiced' => 1, 'parent_item_id' => $n0 + 101),
        array('id' => $n0 + 105, 'product_id' => 1, 'name' => 'Bundle', 'qty_invoiced' => 1, 'product_type' => 'bundle'),
        array('id' => $n0 + 106, 'product_id' => 1, 'name' => 'Not invoiced yet', 'qty_invoiced' => 0),
    ));
    $service->claim($order, $order->getAllItems());
    $units = $storage->unitsOfOrder($n0 + 10);
    $check('claim: one unit per invoiced quantity of IPTV items only (2 lines + 1 reseller)', count($units) === 3, count($units));
    $service->claim($order, $order->getAllItems());
    $check('claim twice does not duplicate', count($storage->unitsOfOrder($n0 + 10)) === 3);
    $check('needsAttention while units are open', $service->needsAttention($order) === true);

    $res = $service->process($order);
    $check('process: 3 units done, no errors', $res['done'] === 3 && $res['failed'] === 0, $res);
    $units = $storage->unitsOfOrder($n0 + 10);
    $lineUnits = array_values(array_filter($units, function ($u) { return $u['kind'] === 'line'; }));
    $check('both lines provisioned with their own panel line', $lineUnits[0]['status'] === 'provisioned' && $lineUnits[1]['status'] === 'provisioned' && $lineUnits[0]['panel_id'] !== $lineUnits[1]['panel_id'], $lineUnits);
    $l1 = $panel('get_line', array('id' => $lineUnits[0]['panel_id']));
    $check('the panel has line 1 active, password stored', $l1['data']['status'] === 'active' && $l1['data']['password'] === $lineUnits[0]['password'] && !empty($lineUnits[0]['links']['m3u']), $l1['status'] ?? '');
    $acct = $storage->resellerOf($customer);
    $sub = $panel('get_user', array('id' => $acct['panel_user_id']));
    $check('the panel has the customer account with 40 credits', (int) $sub['data']['credits'] === 40 && $sub['data']['email'] === 'mage-' . $run . '@example.test', $sub['data'] ?? $sub);
    $check('needsAttention is false when everything is provisioned', $service->needsAttention($order) === false);
    $again = $service->process($order);
    $check('process again changes nothing (idempotent)', $again['done'] === 0 && $again['failed'] === 0 && (int) $panel('get_user', array('id' => $acct['panel_user_id']))['data']['credits'] === 40, $again);

    echo "== panel not reachable, then fixed\n";
    $order2 = $mkOrder($n0 + 20, $customer, array(array('id' => $n0 + 201, 'product_id' => 1, 'name' => 'IPTV again', 'qty_invoiced' => 1)));
    $service->claim($order2, $order2->getAllItems());
    $config->url = 'http://127.0.0.1:1';
    $res = $service->process($order2);
    $u = $storage->unitsOfOrder($n0 + 20)[0];
    $check('failure is recorded, unit stays open', $res['failed'] === 1 && $u['status'] === 'failed' && $u['attempts'] === 1 && $u['last_error'] !== '', $u);
    $check('the failure is written on the order', (bool) array_filter($order2->comments, function ($c) { return strpos($c, 'could not provision') !== false; }), $order2->comments);
    $check('needsAttention offers the button', $service->needsAttention($order2) === true);
    $config->url = $base;
    $res = $service->process($order2);
    $check('retry after the panel is back provisions it', $res['done'] === 1 && $storage->unitsOfOrder($n0 + 20)[0]['status'] === 'provisioned', $res);

    echo "== setup problems stay final until the admin acts\n";
    $order3 = $mkOrder($n0 + 30, $customer, array(array('id' => $n0 + 301, 'product_id' => 4, 'name' => 'No package', 'qty_invoiced' => 1)));
    $service->claim($order3, $order3->getAllItems());
    $res = $service->process($order3);
    $u = $storage->unitsOfOrder($n0 + 30)[0];
    $check('product without package fails with a clear message', $res['failed'] === 1 && strpos($u['last_error'], 'package') !== false, $u['last_error']);
    $check('the cron limit is used up for a final error', $u['attempts'] >= OrderService::MAX_ATTEMPTS);
    $check('a normal run leaves it alone', $service->process($order3)['failed'] === 0);
    $products->products[4]['xtreampro_package_id'] = $pkgId;
    $res = $service->process($order3, true);
    $check('after fixing the product the forced run provisions it', $res['done'] === 1 && $storage->unitsOfOrder($n0 + 30)[0]['status'] === 'provisioned', $res);

    $guest = $mkOrder($n0 + 40, null, array(array('id' => $n0 + 401, 'product_id' => 2, 'name' => 'Reseller pack', 'qty_invoiced' => 1)));
    $service->claim($guest, $guest->getAllItems());
    $res = $service->process($guest, true);
    $check('a guest order for a sub-reseller product is refused', $res['failed'] === 1 && strpos($res['errors'][0], 'registered customer') !== false, $res);
    $check('a guest order for a line product works', (function () use ($service, $storage, $mkOrder, $n0) {
        $o = $mkOrder($n0 + 50, null, array(array('id' => $n0 + 501, 'product_id' => 1, 'name' => 'IPTV guest', 'qty_invoiced' => 1)));
        $service->claim($o, $o->getAllItems());
        $r = $service->process($o);
        return $r['done'] === 1 && $storage->unitsOfOrder($n0 + 50)[0]['status'] === 'provisioned';
    })());

    echo "== second order of the same customer tops up the same account\n";
    $order4 = $mkOrder($n0 + 60, $customer, array(array('id' => $n0 + 601, 'product_id' => 2, 'name' => 'Reseller pack', 'qty_invoiced' => 2)));
    $service->claim($order4, $order4->getAllItems());
    $res = $service->process($order4);
    $acct2 = $storage->resellerOf($customer);
    $check('same panel account, credits 40 + 2 x 40', $res['done'] === 2 && $acct2['panel_user_id'] === $acct['panel_user_id'] && (int) $panel('get_user', array('id' => $acct['panel_user_id']))['data']['credits'] === 120, $res);

    echo "== refunds\n";
    $order->items[0]->d['qty_refunded'] = 1;
    $service->reconcile($order);
    $states = array_map(function ($u) { return $u['status']; }, array_values(array_filter($storage->unitsOfOrder($n0 + 10), function ($u) { return $u['kind'] === 'line'; })));
    $check('partial refund of one of two lines revokes the highest unit only', $states === array('provisioned', 'revoked'), $states);
    $check('the refunded line is disabled in the panel, not deleted', $panel('get_line', array('id' => $lineUnits[1]['panel_id']))['data']['status'] === 'disabled');
    $check('the other line is still active', $panel('get_line', array('id' => $lineUnits[0]['panel_id']))['data']['status'] === 'active');
    $service->reconcile($order);
    $check('reconcile twice changes nothing', $panel('get_line', array('id' => $lineUnits[0]['panel_id']))['data']['status'] === 'active');

    $order->items[1]->d['qty_refunded'] = 1;
    $service->reconcile($order);
    $unit = array_values(array_filter($storage->unitsOfOrder($n0 + 10), function ($u) { return $u['kind'] === 'reseller'; }))[0];
    $check('refunding the reseller item revokes the unit and takes the 40 credits back', $unit['status'] === 'revoked' && $unit['credits_taken_back'] === 40 && (int) $panel('get_user', array('id' => $acct['panel_user_id']))['data']['credits'] === 80, $unit);
    $check('the account stays enabled while another paid unit exists', $panel('get_user', array('id' => $acct['panel_user_id']))['data']['status'] === 'active');

    $order4->d['state'] = 'canceled';
    $service->reconcile($order4, true);
    $b = $panel('get_user', array('id' => $acct['panel_user_id']))['data'];
    $check('cancelling the order revokes all its units and takes the credits back: balance is 0', (int) $b['credits'] === 0, $b['credits']);
    $check('the account is disabled when its last paid unit is gone', $b['status'] === 'disabled', $b['status']);

    echo "== clean up and logs\n";
    $core = (new Gateway($config, $logger))->provisioner();
    foreach ($storage->units as $u) {
        if ($u['kind'] === 'line' && $u['panel_id']) { $core->terminateLine((int) $u['panel_id'], true); }
    }
    $secrets = array($key);
    foreach ($storage->units as $u) { if ($u['password'] !== '') { $secrets[] = $u['password']; } }
    foreach ($storage->accounts as $a) { $secrets[] = $a['password']; }
    $leak = '';
    foreach (array_merge($logger->lines, $history->saved) as $entry) {
        foreach ($secrets as $s) { if ($s !== '' && strpos((string) $entry, $s) !== false) { $leak = substr($entry, 0, 160); break 2; } }
    }
    $check('no API key and no password in the log or in any order comment', $leak === '', $leak);
    $check('order comments were written', count($history->saved) > 5, count($history->saved));
    $check('no PHP warnings', $GLOBALS['warnings'] === 0, $GLOBALS['warnings']);

    echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
    exit($fail ? 1 : 0);
}
