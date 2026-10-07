<?php
/**
 * Paymenter scenario: drives the Xtream UI Pro server extension inside a real
 * Paymenter (Docker) against a real panel API. Run by paymenter-run.sh with
 *   API_URL  the panel API as the Paymenter container sees it
 *   API_KEY  the reseller API key
 * It uses the app's own paths: orders, invoices, payments (the observers and
 * RenewServiceService), the jobs Paymenter dispatches (CreateJob, SuspendJob,
 * TerminateJob) and the client area view. Prints "ALL OK" at the end.
 */

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$API_URL = getenv('API_URL');
$API_KEY = getenv('API_KEY');
$RUN = substr(bin2hex(random_bytes(4)), 0, 6);
$failures = 0;

function ok($cond, $msg)
{
    global $failures;
    if ($cond) {
        echo "  ok   $msg\n";
    } else {
        echo "  FAIL $msg\n";
        $failures++;
    }
}

/** Independent panel reader (not the extension's own client). */
function panel($action, $query = [])
{
    global $API_URL, $API_KEY;
    $r = Http::withHeaders(['X-API-Key' => $API_KEY])->get($API_URL . '/reseller/v1', ['action' => $action] + $query);

    return $r->json();
}

function lineOf($id)
{
    return panel('get_line', ['id' => $id]);
}

function userOf($id)
{
    return panel('get_user', ['id' => $id]);
}

function prop(Service $s, $key)
{
    return $s->properties()->where('key', 'xtreampro_' . $key)->value('value');
}

function setSetting($model, $key, $value)
{
    $model->settings()->updateOrCreate(['key' => $key], ['type' => 'string', 'value' => $value, 'encrypted' => false]);
}

/** Order + invoice + pending service, like the cart does. Returns [service, invoice]. */
function order(Product $product, User $user)
{
    $plan = $product->plans()->first();
    $order = Order::create(['user_id' => $user->id, 'currency_code' => 'USD']);
    $invoice = Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'due_at' => now()->addDays(7)]);
    $service = $order->services()->create([
        'user_id' => $user->id, 'currency_code' => 'USD', 'product_id' => $product->id,
        'plan_id' => $plan->id, 'price' => 5, 'quantity' => 1,
    ]);
    $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'price' => 5, 'quantity' => 1, 'description' => $product->name]);

    return [$service, $invoice];
}

function pay(Invoice $invoice)
{
    ExtensionHelper::addPayment($invoice->id, null, amount: 5);
}

/** Renewal invoice like the cron job makes it. */
function renewalInvoice(Service $service)
{
    $invoice = $service->invoices()->make(['user_id' => $service->user_id, 'status' => 'pending', 'due_at' => $service->expires_at, 'currency_code' => 'USD']);
    $invoice->save();
    $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'price' => 5, 'quantity' => 1, 'description' => $service->description]);

    return $invoice->refresh();
}

function clientArea(Service $service)
{
    $actions = ExtensionHelper::getActions($service->fresh());
    $views = array_values(array_filter($actions, fn ($a) => $a['type'] === 'view'));

    return ExtensionHelper::getView($service->fresh(), $views[0])->render();
}

/** Fill the product's extension settings through the real admin form (Filament + Livewire). */
function adminSave(User $admin, Product $product, array $settings)
{
    auth()->guard('web')->setUser($admin);
    $t = Livewire::actingAs($admin)->test(\App\Admin\Resources\ProductResource\Pages\EditProduct::class, ['record' => $product->id]);
    foreach ($settings as $k => $v) {
        $t->set('data.settings.' . $k, $v);
    }
    $t->call('save');
}

function product($name, $server, $category, array $settings, ?User $admin = null)
{
    $product = Product::create(['name' => $name, 'slug' => strtolower(preg_replace('/\W+/', '-', $name)), 'category_id' => $category->id, 'server_id' => $server->id]);
    $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month'])
        ->prices()->create(['price' => 5, 'currency_code' => 'USD']);
    if ($admin !== null) {
        adminSave($admin, $product, $settings);
    } else {
        foreach ($settings as $k => $v) {
            setSetting($product, $k, $v);
        }
    }

    return $product;
}

echo "queue: " . config('queue.default') . "\n";

// ---------------------------------------------------------------- setup
echo "\n== server extension and product configuration\n";
$extensions = collect(ExtensionHelper::getExtensions('server'))->pluck('name')->all();
ok(in_array('XtreamPro', $extensions), 'Paymenter finds the XtreamPro server extension (' . implode(',', $extensions) . ')');
$meta = ExtensionHelper::getMeta('\\Paymenter\\Extensions\\Servers\\XtreamPro\\XtreamPro');
ok($meta && $meta->version === '1.1.0', 'extension meta carries version 1.1.0');

$server = Server::create(['name' => 'Xtream UI Pro ' . $RUN, 'extension' => 'XtreamPro', 'type' => 'server', 'enabled' => true]);
// Paymenter boots server extensions at the start of every request, once their server exists: do it for this process.
ExtensionHelper::call($server, 'boot');
$conf = ExtensionHelper::getConfig('server', 'XtreamPro');
ok(collect($conf)->firstWhere('name', 'api_key')['type'] === 'password', 'the API key is a password-type field');
ok(collect($conf)->firstWhere('name', 'api_key')['encrypted'] === true, 'the API key is stored encrypted');
User::unguard();
$admin = User::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => "pm-admin-$RUN@example.test", 'password' => bcrypt('admin-' . $RUN . '-pass'), 'role_id' => 1]);
auth()->guard('web')->setUser($admin);
\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
// The server's settings go through the real admin form (password field, encryption).
$form = Livewire::actingAs($admin)->test(\App\Admin\Resources\ServerResource\Pages\EditServer::class, ['record' => $server->id]);
$form->set('data.settings.host', $API_URL)->set('data.settings.api_key', $API_KEY)->call('save');
$server->refresh();
ok($server->settings()->where('key', 'host')->value('value') === $API_URL, 'the admin form saved the API URL');
$rawKey = DB::table('settings')->where('settingable_id', $server->id)->where('key', 'api_key')->value('value');
ok($rawKey !== $API_KEY && strlen((string) $rawKey) > 20, 'the API key is not stored in clear in the database');

ok(ExtensionHelper::testConfig($server, ['host' => $API_URL, 'api_key' => $API_KEY]) === true, 'test connection with the right key');
$bad = ExtensionHelper::testConfig($server, ['host' => $API_URL, 'api_key' => 'definitely-wrong-' . $RUN]);
ok(is_string($bad) && str_contains($bad, 'rejected the API key') && !str_contains($bad, 'definitely-wrong'), 'test connection with a wrong key: readable message, key not echoed');
$bad = ExtensionHelper::testConfig($server, ['host' => 'http://127.0.0.1:1', 'api_key' => $API_KEY]);
ok(is_string($bad) && str_contains($bad, 'Could not connect'), 'test connection to a dead address: readable message');
$bad = ExtensionHelper::testConfig($server, ['host' => '', 'api_key' => $API_KEY]);
ok(is_string($bad) && str_contains($bad, 'not configured'), 'test connection without host: readable message');

$packages = collect(panel('packages')['data'])->where('is_official', true)->values();
$pkg = $packages->first();
// The least a renewal of this package must add (a month counts as 28 days, a year as 360).
$unitSecs = ['hours' => 3600, 'days' => 86400, 'months' => 28 * 86400, 'years' => 360 * 86400][$pkg['official_duration_in']] ?? 86400;
$minPeriod = (int) $pkg['official_duration'] * $unitSecs;
$pc = collect(ExtensionHelper::getProductConfig($server, []));
$options = $pc->firstWhere('name', 'package_id')['options'];
ok(isset($options[$pkg['id']]) && str_contains($options[$pkg['id']], $pkg['name']), 'the package dropdown is loaded from the panel');
ok($pc->pluck('name')->contains('service_type') && $pc->pluck('name')->contains('credits_per_renewal'), 'product config has service type and credits fields');
ok($pc->firstWhere('name', 'credits_on_creation')['disabled'] === true && $pc->firstWhere('name', 'package_id')['disabled'] === false, 'line type: credit fields disabled, package enabled');
$pcr = collect(ExtensionHelper::getProductConfig($server, ['service_type' => 'reseller']));
ok($pcr->firstWhere('name', 'package_id')['disabled'] === true && $pcr->firstWhere('name', 'credits_on_creation')['disabled'] === false, 'reseller type: package disabled, credit fields enabled');

$category = Category::create(['name' => 'IPTV ' . $RUN, 'slug' => 'iptv-' . $RUN]);
$customer = User::create(['first_name' => 'Pay', 'last_name' => 'Menter', 'email' => "pm-$RUN@example.test", 'password' => bcrypt('x' . $RUN . 'longenough')]);
$customer2 = User::create(['first_name' => 'Pay2', 'last_name' => 'Menter', 'email' => "pm-$RUN-b@example.test", 'password' => bcrypt('x' . $RUN . 'longenough')]);

// Products are configured through the real admin form too.
$lineProduct = product("Line $RUN", $server, $category, ['service_type' => 'line', 'package_id' => $pkg['id'], 'trial' => false, 'delete_on_terminate' => true], $admin);
$keepProduct = product("Keep $RUN", $server, $category, ['service_type' => 'line', 'package_id' => $pkg['id'], 'trial' => false, 'delete_on_terminate' => false], $admin);
$subProduct = product("Sub $RUN", $server, $category, ['service_type' => 'reseller', 'credits_on_creation' => 25, 'credits_per_renewal' => 7], $admin);
ok($lineProduct->settings()->where('key', 'package_id')->value('value') == $pkg['id'] && $keepProduct->settings()->where('key', 'delete_on_terminate')->value('value') === '0', 'the admin form stored the product settings (package, checkboxes)');
ok($subProduct->settings()->where('key', 'credits_on_creation')->value('value') === '25', 'the admin form stored the sub-reseller credits');

// ---------------------------------------------------------------- line
echo "\n== IPTV line: pay, create, replay\n";
[$svc, $inv] = order($lineProduct, $customer);
pay($inv);
$svc->refresh();
ok($svc->status === 'active', 'paying the first invoice activates the service');
$lineId = (int) prop($svc, 'line_id');
ok($lineId > 0, "the line id is kept in the service properties ($lineId)");
$line = lineOf($lineId);
ok(($line['status'] ?? '') === 'STATUS_SUCCESS', 'the line exists in the panel');
$data = $line['data'];
$exp1 = (int) $data['exp_date'];
ok($data['username'] === prop($svc, 'username'), 'the panel username is kept on the service');
ok(abs($exp1 - strtotime('+' . $pkg['official_duration'] . ' ' . rtrim($pkg['official_duration_in'], 's'))) < 3 * 86400, 'the first payment created the line for exactly one package period (not renewed on top)');
ok(prop($svc, 'renew_invoices') === null, 'the first payment is not marked as a renewal');

$out = clientArea($svc);
ok(str_contains($out, $data['username']) && str_contains($out, $data['password']), 'client area shows username and password');
ok(!empty($data['links']['m3u']) && str_contains($out, htmlspecialchars($data['links']['m3u'], ENT_QUOTES)), 'client area shows the m3u link built by the panel');
ok(str_contains($out, 'Web player') && str_contains($out, 'Max connections'), 'client area shows the web player link and max connections');
ok(!str_contains($out, $API_KEY), 'client area never shows the API key');

$page = Livewire::actingAs($customer)->test(\App\Livewire\Services\Show::class, ['service' => $svc->fresh()])->html();
ok(str_contains($page, 'IPTV line') && str_contains($page, 'Max connections') && str_contains($page, htmlspecialchars($data['username'])), 'the real client area page (Livewire) shows the line tab with the credentials');

ExtensionHelper::createServer($svc->fresh());
ok((int) prop($svc, 'line_id') === $lineId && (int) lineOf($lineId)['data']['exp_date'] === $exp1, 'creating again replays the same line (no second charge)');

echo "\n== IPTV line: suspend, unsuspend by paying the overdue renewal\n";
SuspendJob::dispatch($svc->fresh());
$svc->update(['status' => 'suspended']);
$st = lineOf($lineId)['data'];
ok(($st['enabled'] ?? $st['status'] ?? null) !== null && (($st['status'] ?? '') !== 'active' || ($st['enabled'] ?? true) === false), 'suspend disables the line in the panel (' . json_encode(array_intersect_key($st, array_flip(['status', 'enabled', 'admin_enabled']))) . ')');
$suspendedLine = $st;
$renewal = renewalInvoice($svc->fresh());
pay($renewal);
$svc->refresh();
ok($svc->status === 'active', 'paying the renewal reactivates the service');
$after = lineOf($lineId)['data'];
ok($after['status'] !== $suspendedLine['status'] || ($after['enabled'] ?? null) !== ($suspendedLine['enabled'] ?? null), 'unsuspend enabled the line again (' . json_encode(array_intersect_key($after, array_flip(['status', 'enabled', 'admin_enabled']))) . ')');
$exp2 = (int) $after['exp_date'];
ok($exp2 > $exp1 + $minPeriod, 'the paid renewal extended the line by one package period');
ok(prop($svc, 'renew_invoices') === null && prop($svc, 'renew_error') === null, 'no renewal left pending');

echo "\n== IPTV line: renewal of an active service, no double renewal\n";
$renewal = renewalInvoice($svc->fresh());
pay($renewal);
$exp3 = (int) lineOf($lineId)['data']['exp_date'];
ok($exp3 > $exp2 + $minPeriod, 'a paid renewal invoice of an active service renews the line');
ExtensionHelper::upgradeServer($svc->fresh());
$invoice = Invoice::find($renewal->id);
$invoice->status = 'paid';
$invoice->save();
ok((int) lineOf($lineId)['data']['exp_date'] === $exp3, 'retrying (upgrade action) and saving the paid invoice again do not renew twice');

echo "\n== IPTV line: a failing renewal is kept and retried\n";
$renewal = renewalInvoice($svc->fresh());
$hostRow = $server->settings()->where('key', 'host')->first();
$hostRow->update(['value' => 'http://127.0.0.1:1']);
pay($renewal);
ok(Invoice::find($renewal->id)->status === 'paid', 'the invoice is paid even though the panel was unreachable');
ok((int) lineOf($lineId)['data']['exp_date'] === $exp3, 'nothing was renewed while the panel was unreachable');
ok(str_contains((string) prop($svc, 'renew_error'), 'Could not connect') && prop($svc, 'renew_invoices') === (string) $renewal->id, 'the failure and the pending invoice are kept on the service');
$hostRow->update(['value' => $API_URL]);
ExtensionHelper::upgradeServer($svc->fresh());
$exp4 = (int) lineOf($lineId)['data']['exp_date'];
ok($exp4 > $exp3 + $minPeriod, 'the retry (admin "Upgrade server") applies the missed renewal');
ok(prop($svc, 'renew_invoices') === null && prop($svc, 'renew_error') === null, 'the pending marker and the error are cleared');
ExtensionHelper::upgradeServer($svc->fresh());
ok((int) lineOf($lineId)['data']['exp_date'] === $exp4, 'a second retry does nothing');

echo "\n== 1.1.0: sells, pricing, connector name, change package, spent request id\n";
$allPk = collect(panel('packages')['data']);
$boxPkg = $allPk->first(fn ($p) => isset($p['sells']) && !in_array('line', $p['sells'], true));
ok($boxPkg !== null, 'the panel marks a box-only package with sells without "line"');
ok($boxPkg !== null && !isset($options[$boxPkg['id']]) && isset($options[$pkg['id']]), 'the package dropdown leaves box-only packages out');
$boxProduct = product("Box $RUN", $server, $category, ['service_type' => 'line', 'package_id' => $boxPkg['id'], 'trial' => '0', 'delete_on_terminate' => '0']);
[$svcBox] = order($boxProduct, $customer);
$creditsBox = (int) panel('user_info')['data']['credits'];
$msg = '';
try {
    ExtensionHelper::createServer($svcBox);
} catch (Exception $e) {
    $msg = $e->getMessage();
}
ok(str_contains($msg, 'boxes only') && prop($svcBox, 'line_id') === null && (int) panel('user_info')['data']['credits'] === $creditsBox, 'a box-only package is refused before anything is sold, with a readable reason (' . $msg . ')');
$connectors = collect(panel('api_logs', ['limit' => 50])['data'] ?? [])->pluck('connector')->unique()->values()->all();
ok(in_array('paymenter/1.1.0', $connectors, true), 'the panel call log names the connector (' . implode(',', $connectors) . ')');
ok(!isset($pc->firstWhere('name', 'delete_on_terminate')['default']) || $pc->firstWhere('name', 'delete_on_terminate')['default'] === false, 'Delete permanently on terminate is off by default');
ok(str_contains($pc->firstWhere('name', 'delete_on_terminate')['description'], 'final'), 'its help text says that deleting is final');

// Upgrade: the service's product now sells another package; Paymenter's upgrade action sells it on the line.
$otherPkg = $packages->first(fn ($p) => $p['id'] !== $pkg['id'] && in_array('line', $p['sells'] ?? ['line'], true));
$upProduct = product("Up $RUN", $server, $category, ['service_type' => 'line', 'package_id' => $otherPkg['id'], 'trial' => false, 'delete_on_terminate' => true], $admin);
$creditsUp = (int) panel('user_info')['data']['credits'];
$svc->update(['product_id' => $upProduct->id]);
ExtensionHelper::upgradeServer($svc->fresh());
ok((int) lineOf($lineId)['data']['package_id'] === (int) $otherPkg['id'], 'the upgrade action sells the new package on the line');
ok((int) panel('user_info')['data']['credits'] < $creditsUp, 'and it was charged');
$creditsUp2 = (int) panel('user_info')['data']['credits'];
ExtensionHelper::upgradeServer($svc->fresh());
ok((int) panel('user_info')['data']['credits'] === $creditsUp2, 'repeating the upgrade action is not charged again');
$svc->update(['product_id' => $boxProduct->id]);
$msg = '';
try {
    ExtensionHelper::upgradeServer($svc->fresh());
} catch (Exception $e) {
    $msg = $e->getMessage();
}
ok(str_contains($msg, 'boxes only') && (int) lineOf($lineId)['data']['package_id'] === (int) $otherPkg['id'], 'an upgrade to a box-only package fails readably and changes nothing');
$svc->update(['product_id' => $upProduct->id]);

[$svcSp, $invSp] = order($lineProduct, $customer);
pay($invSp);
$spLine = (int) prop($svcSp->refresh(), 'line_id');
Http::withHeaders(['X-API-Key' => $API_KEY])->asForm()->post($API_URL . '/reseller/v1', ['action' => 'delete_line', 'id' => $spLine]);
$svcSp->properties()->whereIn('key', ['xtreampro_line_id', 'xtreampro_username'])->delete();
$msg = '';
try {
    ExtensionHelper::createServer($svcSp->fresh());
} catch (Exception $e) {
    $msg = $e->getMessage();
}
ok(str_contains($msg, 'already made') && !str_contains($msg, 'REQUEST_ID'), 'selling again under the request id of a line deleted on the panel gives a readable message (REQUEST_ID_SPENT)');

echo "\n== IPTV line: terminate (delete), create again\n";
TerminateJob::dispatch($svc->fresh());
$svc->update(['status' => 'cancelled']);
$gone = panel('get_line', ['id' => $lineId]);
ok(($gone['status'] ?? '') === 'STATUS_FAILURE' && ($gone['error'] ?? '') === 'RESOURCE_NOT_FOUND', 'terminate deleted the line in the panel');
ok(prop($svc, 'line_id') === null && prop($svc, 'generation') === '1', 'the mapping is closed and the generation counts the termination');
$svc->update(['status' => 'active']);
ExtensionHelper::createServer($svc->fresh());
$newLine = (int) prop($svc, 'line_id');
ok($newLine > 0 && $newLine !== $lineId && (lineOf($newLine)['status'] ?? '') === 'STATUS_SUCCESS', "creating the service again sells a new line ($newLine)");
TerminateJob::dispatch($svc->fresh());
TerminateJob::dispatch($svc->fresh());
ok(true, 'terminating an already terminated service is not an error');

echo "\n== IPTV line: terminate with Delete on terminate off\n";
[$svc2, $inv2] = order($keepProduct, $customer);
pay($inv2);
$keepId = (int) prop($svc2->refresh(), 'line_id');
TerminateJob::dispatch($svc2->fresh());
$k = lineOf($keepId);
ok(($k['status'] ?? '') === 'STATUS_SUCCESS' && ($k['data']['enabled'] ?? true) === false, 'the line is only disabled, not deleted (' . json_encode(array_intersect_key($k['data'] ?? [], array_flip(['status', 'enabled', 'admin_enabled']))) . ')');
// clean up the panel line we kept
Http::withHeaders(['X-API-Key' => $API_KEY])->asForm()->post($API_URL . '/reseller/v1', ['action' => 'delete_line', 'id' => $keepId]);

echo "\n== IPTV line: product without a package\n";
$noPkg = product("NoPkg $RUN", $server, $category, ['service_type' => 'line', 'trial' => '0', 'delete_on_terminate' => '1']);
[$svc3, $inv3] = order($noPkg, $customer);
$msg = '';
try {
    ExtensionHelper::createServer($svc3);
} catch (Exception $e) {
    $msg = $e->getMessage();
}
ok(str_contains($msg, 'No package is selected'), 'creating without a package gives a readable error');

// ---------------------------------------------------------------- sub-reseller
echo "\n== sub-reseller account: pay, create with starting credits\n";
[$sub, $subInv] = order($subProduct, $customer2);
pay($subInv);
$sub->refresh();
$userId = (string) prop($sub, 'user_id');
ok($userId !== '', "the account id is kept in the service properties ($userId)");
$u = userOf($userId);
ok(($u['status'] ?? '') === 'STATUS_SUCCESS' && (int) $u['data']['credits'] === 25, 'the account exists with the starting credits (25)');
ok($u['data']['email'] === $customer2->email, 'the account carries the customer email');
$username = prop($sub, 'username');
ok($username === $u['data']['username'] && preg_match('/^r' . $sub->id . '[a-z]{6}$/', $username) === 1, "generated username ($username)");
$secretRaw = prop($sub, 'secret');
$plain = Crypt::decryptString($secretRaw);
ok($secretRaw !== $plain && strlen($plain) === 14, 'the password is kept encrypted on the service');
$audited = DB::table('audits')->where('new_values', 'like', '%' . $plain . '%')->count();
ok($audited === 0, 'the password is not in Paymenter\'s audit log in clear');
$out = clientArea($sub);
ok(str_contains($out, $username) && str_contains($out, $plain) && str_contains($out, '25') && str_contains($out, 'Credit balance'), 'client area shows username, password and the credit balance');
ok(!str_contains($out, 'M3U') && !str_contains($out, 'Max connections'), 'client area shows no playlist for a sub-reseller');

$page = Livewire::actingAs($customer2)->test(\App\Livewire\Services\Show::class, ['service' => $sub->fresh()])->html();
ok(str_contains($page, 'Credit balance') && str_contains($page, htmlspecialchars($username)), 'the real client area page shows the sub-reseller account');

ExtensionHelper::createServer($sub->fresh());
ok((int) userOf($userId)['data']['credits'] === 25 && prop($sub, 'user_id') === $userId, 'creating again replays the account and the credit transfer (no second charge)');

echo "\n== sub-reseller account: suspend, unsuspend, renewal top-up\n";
SuspendJob::dispatch($sub->fresh());
$sub->update(['status' => 'suspended']);
ok(userOf($userId)['data']['status'] === 'disabled', 'suspend disables the account');
$renewal = renewalInvoice($sub->fresh());
pay($renewal);
ok(userOf($userId)['data']['status'] === 'active', 'paying the renewal unsuspends the account');
ok((int) userOf($userId)['data']['credits'] === 32, 'the renewal handed over 7 credits (32)');
ExtensionHelper::upgradeServer($sub->fresh());
ok((int) userOf($userId)['data']['credits'] === 32, 'a retry does not hand over credits twice');
$renewal = renewalInvoice($sub->fresh());
pay($renewal);
ok((int) userOf($userId)['data']['credits'] === 39, 'a second renewal on an active service hands over 7 more (39)');

echo "\n== sub-reseller account: terminate, create again\n";
TerminateJob::dispatch($sub->fresh());
ok(userOf($userId)['data']['status'] === 'disabled', 'terminate disables the account (it is not deleted)');
ok(prop($sub, 'user_id') === null && prop($sub, 'secret') === null && prop($sub, 'generation') === '1', 'the mapping is closed');
$sub->update(['status' => 'active']);
$msg = '';
try {
    ExtensionHelper::createServer($sub->fresh());
} catch (Exception $e) {
    $msg = $e->getMessage();
}
$again = prop($sub->fresh(), 'user_id');
ok(($again && $again !== $userId) || str_contains($msg, 'already taken'), 'creating again sells a new account or says the email is taken (' . ($again ? 'new account' : $msg) . ')');

// ---------------------------------------------------------------- hygiene
echo "\n== logging hygiene\n";
$log = '';
foreach (glob('/app/storage/logs/*.log') as $f) {
    $log .= file_get_contents($f);
}
ok(str_contains($log, 'Xtream UI Pro: create_line ok'), 'actions are logged');
ok(!str_contains($log, $API_KEY), 'the API key is not in the Paymenter log');
ok(!str_contains($log, $data['password']) && !str_contains($log, $plain), 'no line or account password is in the Paymenter log');
$leak = DB::table('audits')->where('new_values', 'like', '%' . $API_KEY . '%')->count();
ok($leak === 0, 'the API key is not in the audit log');

echo $failures === 0 ? "\nALL OK\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
