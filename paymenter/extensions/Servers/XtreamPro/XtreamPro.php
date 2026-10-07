<?php

namespace Paymenter\Extensions\Servers\XtreamPro;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Invoice\Paid as InvoicePaid;
use App\Events\Invoice\Updating as InvoiceUpdating;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Throwable;

require_once __DIR__ . '/Client.php';

/**
 * Xtream UI Pro - Paymenter server extension.
 *
 * Each Paymenter service is one IPTV line (or, with Service type "Sub-reseller
 * account", one sub-reseller account) created through the panel's Reseller
 * API. Create / renew are charged to the reseller's credits in the panel.
 *
 * Server setup in Paymenter: API URL = the panel's API address, API key = the
 * reseller API key.
 *
 * Where the panel ids live: in the service's properties (xtreampro_*).
 * Renewals: Paymenter has no "renew" call for server extensions, so a paid
 * renewal invoice is recognised through the Invoice events (see boot()).
 *
 * @version 1.1.0
 */
#[ExtensionMeta(
    name: 'Xtream UI Pro',
    description: 'Sell IPTV lines and sub-reseller accounts of an Xtream UI Pro panel through its Reseller API.',
    version: '1.1.0',
    author: 'Xtream UI Pro',
    url: '',
)]
class XtreamPro extends Server
{
    public const VERSION = '1.1.0';

    private const EXTENSION = 'XtreamPro';

    // ---------------------------------------------------------------------
    // Extension definition
    // ---------------------------------------------------------------------

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'API URL',
                'type' => 'text',
                'description' => 'Address of the panel API (cmd/api), for example https://api.example.com. Use a valid TLS certificate: it is always verified.',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'Reseller API key',
                'type' => 'password',
                'description' => 'The reseller\'s API key (panel dashboard, page "API key"). Only reseller accounts work; admin keys are refused.',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->client()->userInfo();
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function getProductConfig($values = []): array
    {
        $isReseller = ($values['service_type'] ?? 'line') === 'reseller';

        $packages = [];
        $packageHelp = 'Package of the line, loaded live from the panel. Only applies to IPTV lines.';
        try {
            foreach ($this->client()->packages() as $pkg) {
                // A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line.
                if (!is_array($pkg) || !isset($pkg['id']) || !Client::sellsLine($pkg)) {
                    continue;
                }
                $name = isset($pkg['name']) ? (string) $pkg['name'] : ('#' . $pkg['id']);
                if (!empty($pkg['is_official'])) {
                    $detail = ($pkg['official_credits'] ?? '?') . ' credits, '
                        . ($pkg['official_duration'] ?? '?') . ' ' . ($pkg['official_duration_in'] ?? '');
                } else {
                    $detail = 'trial only';
                }
                $packages[$pkg['id']] = $name . ' (' . trim($detail) . ')';
            }
        } catch (Exception $e) {
            $packageHelp = 'The packages could not be loaded: ' . $e->getMessage();
        }

        return [
            [
                'name' => 'service_type',
                'label' => 'Service type',
                'type' => 'select',
                'options' => ['line' => 'IPTV line', 'reseller' => 'Sub-reseller account'],
                'default' => 'line',
                'required' => true,
                'live' => true,
                'description' => 'What one service sells. Package, Trial line and Delete permanently on terminate only apply to IPTV lines.',
            ],
            [
                'name' => 'package_id',
                'label' => 'Package',
                'type' => 'select',
                'options' => $packages,
                'required' => !$isReseller,
                'disabled' => $isReseller,
                'description' => $packageHelp,
            ],
            [
                'name' => 'trial',
                'label' => 'Trial line',
                'type' => 'checkbox',
                'disabled' => $isReseller,
                'description' => 'Create the line as a trial. Only applies to IPTV lines.',
            ],
            [
                'name' => 'delete_on_terminate',
                'label' => 'Delete permanently on terminate',
                'type' => 'checkbox',
                'default' => false,
                'disabled' => $isReseller,
                'description' => 'Off (default): the line is only disabled when the service is terminated and can be enabled again on the panel. On: the line is deleted on the panel - deleting is final, nothing can be brought back.',
            ],
            [
                'name' => 'credits_on_creation',
                'label' => 'Credits on creation',
                'type' => 'number',
                'default' => 0,
                'min_value' => 0,
                'disabled' => !$isReseller,
                'description' => 'Whole number, 0 or more: credits given to the new sub-reseller account. Only applies to sub-reseller accounts.',
            ],
            [
                'name' => 'credits_per_renewal',
                'label' => 'Credits per renewal',
                'type' => 'number',
                'default' => 0,
                'min_value' => 0,
                'disabled' => !$isReseller,
                'description' => 'Whole number, 0 or more: credits given at every paid renewal invoice (0 = renewals do nothing on the panel). Only applies to sub-reseller accounts.',
            ],
        ];
    }

    /**
     * Called on every request for an installed server extension. Registers the
     * view namespace and the two invoice listeners that make renewals work.
     */
    public function boot()
    {
        View::addNamespace('xtreampro', __DIR__ . '/resources/views');

        // Paymenter never tells a server extension that an active service was
        // renewed (RenewServiceService only unsuspends or creates). A paid
        // invoice is the one reliable signal: just before it is saved as paid
        // the services on it still have their old status, which tells a renewal
        // from the first payment (service pending, nothing created yet).
        Event::listen(InvoiceUpdating::class, [self::class, 'markRenewals']);
        Event::listen(InvoicePaid::class, [self::class, 'renewPaid']);
    }

    // ---------------------------------------------------------------------
    // Provisioning
    // ---------------------------------------------------------------------

    public function createServer(Service $service, $settings, $properties)
    {
        if (self::isReseller($settings)) {
            return $this->createSubReseller($service, $settings, $properties);
        }

        $packageId = (int) ($settings['package_id'] ?? 0);
        if ($packageId <= 0) {
            throw new Exception('No package is selected in the product configuration.');
        }

        // Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
        // with the amounts and nothing is created.
        $this->client()->assertCanSellPackage($packageId, self::flag($settings['trial'] ?? null, false), true);

        // Blank values are generated by the panel. The reseller's group may also
        // ignore custom credentials, so the final ones are read back below.
        $data = $this->client()->createLine(
            $packageId,
            self::flag($settings['trial'] ?? null, false),
            trim((string) ($properties['username'] ?? '')),
            (string) ($properties['password'] ?? ''),
            // The generation changes after a termination, so that creating the
            // service again sells a new line instead of replaying the deleted one.
            $this->requestId('create', $service, (string) $this->generation($service))
        );

        $line = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : [];
        if (!isset($line['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        $this->setProp($service, 'line_id', (string) (int) $line['id'], 'Xtream UI Pro line ID');
        if (!empty($line['username'])) {
            $this->setProp($service, 'username', (string) $line['username'], 'Xtream UI Pro username');
        }
        $this->log('create_line', $service, ['line' => (int) $line['id']]);

        return ['username' => (string) ($line['username'] ?? '')];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $client = $this->client();
        if (self::isReseller($settings)) {
            $client->userAction('disable_user', $this->requireUserId($client, $service));
        } else {
            $client->lineAction('disable_line', $this->requireLineId($client, $service));
        }
        $this->log('suspend', $service);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $client = $this->client();
        if (self::isReseller($settings)) {
            $client->userAction('enable_user', $this->requireUserId($client, $service));
        } else {
            $client->lineAction('enable_line', $this->requireLineId($client, $service));
        }
        $this->log('unsuspend', $service);

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $client = $this->client();

        if (self::isReseller($settings)) {
            // Accounts are only disabled: delete_user is final and hands the
            // account's credits and lines to the reseller, which a billing
            // system must not do on its own.
            $userId = $this->resolveUserId($client, $service);
            if ($userId !== null) {
                try {
                    $client->userAction('disable_user', $userId);
                } catch (ApiException $e) {
                    if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                        throw $e;
                    }
                }
            }
        } else {
            $lineId = $this->resolveLineId($client, $service);
            // A line that no longer exists in the panel is already terminated.
            if ($lineId !== null) {
                try {
                    $client->lineAction(self::flag($settings['delete_on_terminate'] ?? null, false) ? 'delete_line' : 'disable_line', $lineId);
                } catch (ApiException $e) {
                    if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                        throw $e;
                    }
                }
            }
        }

        $this->closeMapping($service);
        $this->log('terminate', $service);

        return true;
    }

    /**
     * Paymenter calls this when a customer upgrades a service, or when an admin
     * picks "Upgrade server" under "Trigger Extension Action". It does two things:
     * it retries paid renewals that failed (for example for lack of credits), and,
     * for a line, sells the package of the service's (new) product on the line
     * (`change_package`, charged to the reseller). It is safe to repeat: renewals
     * carry a request id, the panel charges each only once, and a line already on
     * the product's package is left alone.
     *
     * Assumed (not read from Paymenter's code): $settings are those of the NEW
     * product when a customer upgrade runs this.
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        $this->processRenewals($service);
        if (!self::isReseller($settings)) {
            $this->changeLinePackage($service, $settings);
        }

        return true;
    }

    /**
     * Sells the product's package on the service's line. Asks the panel first (`pricing`,
     * `package_compatibility`): a package that is not on sale, one for boxes only or too few
     * credits fail before anything is sold; what the change does to the time left and what it
     * costs is logged. A panel that does not know `package_compatibility` is handled as before.
     */
    private function changeLinePackage(Service $service, $settings): void
    {
        $to = (int) ($settings['package_id'] ?? 0);
        if ($to <= 0) {
            return;
        }
        $client = $this->client();
        $lineId = $this->resolveLineId($client, $service);
        if ($lineId === null) {
            return; // nothing sold yet: Create will use the new package
        }
        $line = $client->getLine($lineId);
        if (is_array($line) && (int) ($line['package_id'] ?? 0) === $to) {
            return;
        }
        $client->assertCanSellPackage($to, false, true);
        $compat = $client->packageCompatibility($lineId, $to);
        $sentence = '';
        if ($compat !== null) {
            $sentence = Client::describeCompatibility($compat);
            if (empty($compat['can_afford'])) {
                throw new ApiException('INSUFFICIENT_CREDITS', 402, 'The change costs ' . (int) ($compat['price'] ?? 0) . ' credits.');
            }
        }
        // One request id per line state: a retry of the same change is not charged twice.
        $exp = is_array($line) ? (int) ($line['exp_date'] ?? 0) : 0;
        $client->changePackage($lineId, $to, $this->requestId('chg', $service, $to . '-' . $exp));
        $this->log('change_package', $service, ['package' => $to, 'panel' => $sentence]);
    }

    // ---------------------------------------------------------------------
    // Client area
    // ---------------------------------------------------------------------

    public function getActions(Service $service, $settings = [], $properties = []): array
    {
        return [
            [
                'type' => 'view',
                'name' => 'xtreampro',
                'label' => self::isReseller($settings) ? 'Account' : 'IPTV line',
                'function' => 'getView',
            ],
        ];
    }

    public function getView(Service $service, $settings, $properties, $view)
    {
        $data = ['error' => null, 'reseller' => self::isReseller($settings)];
        try {
            $client = $this->client();
            if ($data['reseller']) {
                $userId = $this->resolveUserId($client, $service);
                if ($userId === null) {
                    throw new Exception('Your account is not available yet.');
                }
                $user = $client->getUser($userId);
                $data += [
                    'username' => (string) ($user['username'] ?? $this->prop($service, 'username') ?? ''),
                    'password' => $this->secret($service),
                    'status' => (string) ($user['status'] ?? ''),
                    'credits' => isset($user['credits']) ? (string) $user['credits'] : '',
                ];
            } else {
                $lineId = $this->resolveLineId($client, $service);
                if ($lineId === null) {
                    throw new Exception('Your line is not available yet.');
                }
                $line = $client->getLine($lineId);
                $links = (isset($line['links']) && is_array($line['links'])) ? $line['links'] : [];
                $data += [
                    'username' => (string) ($line['username'] ?? ''),
                    'password' => (string) ($line['password'] ?? ''),
                    'status' => (string) ($line['status'] ?? ''),
                    'expiry' => self::formatExpiry($line['exp_date'] ?? null),
                    'maxConnections' => isset($line['max_connections']) ? (string) $line['max_connections'] : '',
                    // The panel builds the links (host, password included) itself.
                    'links' => array_filter([
                        'Server URL' => $links['server'] ?? null,
                        'M3U playlist' => $links['m3u'] ?? null,
                        'M3U playlist (HLS)' => $links['m3u_hls'] ?? null,
                        'XMLTV (EPG)' => $links['xmltv'] ?? null,
                        'Player API' => $links['player_api'] ?? null,
                        'Web player' => $links['web_player'] ?? null,
                    ], fn ($v) => is_string($v) && $v !== ''),
                ];
            }
        } catch (Throwable $e) {
            $this->logFailure('client_area', $service, $e);
            $data['error'] = $e instanceof ApiException || $e instanceof Exception
                ? $e->getMessage() : 'The service could not be loaded.';
        }

        return view('xtreampro::service', $data);
    }

    // ---------------------------------------------------------------------
    // Renewals (see boot())
    // ---------------------------------------------------------------------

    /** Listener: remember which services a paid invoice renews. Never throws. */
    public static function markRenewals(InvoiceUpdating $event): void
    {
        try {
            $invoice = $event->invoice;
            if (!$invoice->isDirty('status') || $invoice->status !== 'paid') {
                return;
            }
            foreach ($invoice->items as $item) {
                $service = $item->reference_type === Service::class ? $item->reference : null;
                if (!$service instanceof Service || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                    continue;
                }
                $ext = self::forService($service);
                // No panel object yet = first payment (or nothing created): not a renewal.
                if ($ext === null || ($ext->prop($service, 'line_id') === null && $ext->prop($service, 'user_id') === null)) {
                    continue;
                }
                $ids = $ext->renewalList($service);
                if (!in_array((string) $invoice->id, $ids, true)) {
                    $ids[] = (string) $invoice->id;
                    $ext->setProp($service, 'renew_invoices', implode(',', $ids), 'Xtream UI Pro renewals to apply');
                }
            }
        } catch (Throwable $e) {
            Log::error('Xtream UI Pro: could not mark the renewals of invoice ' . ($event->invoice->id ?? '?') . ': ' . $e->getMessage());
        }
    }

    /** Listener: apply the renewals marked above once the invoice is paid. Never throws. */
    public static function renewPaid(InvoicePaid $event): void
    {
        try {
            foreach ($event->invoice->items as $item) {
                $service = $item->reference_type === Service::class ? $item->reference : null;
                if (!$service instanceof Service) {
                    continue;
                }
                $ext = self::forService($service);
                if ($ext === null || !in_array((string) $event->invoice->id, $ext->renewalList($service), true)) {
                    continue;
                }
                try {
                    $ext->processRenewals($service);
                } catch (Throwable $e) {
                    // Already recorded on the service and logged by processRenewals().
                }
            }
        } catch (Throwable $e) {
            Log::error('Xtream UI Pro: renewal of invoice ' . ($event->invoice->id ?? '?') . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Apply every pending renewal of a service, oldest first. A failure is kept
     * on the service (property xtreampro_renew_error), logged, and rethrown;
     * what was applied is not repeated.
     */
    public function processRenewals(Service $service): void
    {
        $ids = $this->renewalList($service);
        if ($ids === []) {
            return;
        }
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        $client = $this->client();

        foreach ($ids as $invoiceId) {
            try {
                if (self::isReseller($settings)) {
                    $credits = self::credits($settings['credits_per_renewal'] ?? 0, 'Credits per renewal');
                    if ($credits > 0) {
                        $client->assertCanGiveCredits($credits);
                        $client->adjustCredits(
                            $this->requireUserId($client, $service),
                            $credits,
                            'Paymenter invoice #' . $invoiceId . ', service #' . $service->id,
                            $this->requestId('subr', $service, (string) $invoiceId)
                        );
                    }
                } else {
                    // Costs reseller credits; the request id makes a retry free. Fails with the amounts when the
                    // balance cannot pay the period, before anything is sold.
                    if ((int) ($settings['package_id'] ?? 0) > 0) {
                        $client->assertCanSellPackage((int) $settings['package_id'], false, false);
                    }
                    $client->renewLine($this->requireLineId($client, $service), $this->requestId('renew', $service, (string) $invoiceId));
                }
            } catch (Throwable $e) {
                $this->setProp($service, 'renew_error', 'Invoice #' . $invoiceId . ': ' . $e->getMessage(), 'Xtream UI Pro last renewal error');
                $this->logFailure('renew', $service, $e);
                throw $e;
            }
            $ids = array_values(array_diff($ids, [$invoiceId]));
            $this->setRenewalList($service, $ids);
            $this->log('renew', $service, ['invoice' => $invoiceId]);
        }
        $service->properties()->where('key', 'xtreampro_renew_error')->delete();
    }

    private function renewalList(Service $service): array
    {
        $value = (string) $this->prop($service, 'renew_invoices');

        return $value === '' ? [] : array_values(array_filter(explode(',', $value), 'ctype_digit'));
    }

    private function setRenewalList(Service $service, array $ids): void
    {
        if ($ids === []) {
            $service->properties()->where('key', 'xtreampro_renew_invoices')->delete();

            return;
        }
        $this->setProp($service, 'renew_invoices', implode(',', $ids), 'Xtream UI Pro renewals to apply');
    }

    // ---------------------------------------------------------------------
    // Sub-reseller accounts
    // ---------------------------------------------------------------------

    private function createSubReseller(Service $service, array $settings, array $properties): array
    {
        $generation = $this->generation($service);
        $creditsOnCreate = self::credits($settings['credits_on_creation'] ?? 0, 'Credits on creation');

        // Credentials kept from an earlier attempt win: the panel stores only a
        // hash, so a repeated call must send the very same values.
        $username = (string) ($this->prop($service, 'username') ?? '');
        $password = $this->secret($service);
        if ($username === '') {
            $username = trim((string) ($properties['username'] ?? ''));
        }
        if ($username === '') {
            $username = 'r' . $service->id . self::random(6, 'abcdefghijklmnopqrstuvwxyz');
        }
        // Panel rule: letters, digits, "_ . -", 3 to 32 characters. A longer name is cut.
        $username = substr($username, 0, 32);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            throw new Exception('The username "' . $username . '" is not valid for a sub-reseller: use 3 to 32 letters, digits, "_", "." or "-".');
        }
        if ($password === '') {
            $password = (string) ($properties['password'] ?? '');
        }
        if ($password === '') {
            $password = self::random(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        }
        // Saved BEFORE the panel is called (see above).
        $this->setProp($service, 'username', $username, 'Xtream UI Pro username');
        $this->setSecret($service, $password);

        $user = $service->user;
        $client = $this->client();
        if (($this->prop($service, 'user_id') ?? '') === '') {
            // The price of the account plus the credits to hand over: checked before the account exists.
            $client->assertCanCreateSubUser($creditsOnCreate);
        }
        $data = $client->createUser($username, $password, (string) $user->email, trim((string) $user->name), $this->requestId('sub', $service, (string) $generation));
        $account = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : [];
        if (!isset($account['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        $userId = (string) $account['id'];
        $this->setProp($service, 'user_id', $userId, 'Xtream UI Pro account ID');
        // The panel may answer with other credentials (a replayed request).
        if (!empty($account['username'])) {
            $this->setProp($service, 'username', (string) $account['username'], 'Xtream UI Pro username');
        }
        if (!empty($data['password'])) {
            $this->setSecret($service, (string) $data['password']);
        }

        if ($creditsOnCreate > 0) {
            // If this fails the account stays mapped: creating again retries the
            // transfer only, the request ids keep it from being charged twice.
            $client->adjustCredits($userId, $creditsOnCreate, 'Paymenter service #' . $service->id, $this->requestId('subc', $service, (string) $generation));
        }
        $this->log('create_user', $service, ['user' => $userId]);

        return ['username' => $username];
    }

    private function resolveUserId(Client $client, Service $service): ?string
    {
        $id = $this->prop($service, 'user_id');
        if ($id !== null && $id !== '') {
            return $id;
        }
        $username = (string) ($this->prop($service, 'username') ?? '');
        if ($username === '') {
            return null;
        }
        $user = $client->findUserByUsername($username);
        if ($user === null || !isset($user['id'])) {
            return null;
        }
        $this->setProp($service, 'user_id', (string) $user['id'], 'Xtream UI Pro account ID');

        return (string) $user['id'];
    }

    private function requireUserId(Client $client, Service $service): string
    {
        return $this->resolveUserId($client, $service) ?? throw new ApiException('RESOURCE_NOT_FOUND');
    }

    // ---------------------------------------------------------------------
    // Lines
    // ---------------------------------------------------------------------

    private function resolveLineId(Client $client, Service $service): ?int
    {
        $id = $this->prop($service, 'line_id');
        if ($id !== null && (int) $id > 0) {
            return (int) $id;
        }
        $username = (string) ($this->prop($service, 'username') ?? '');
        if ($username === '') {
            return null;
        }
        $line = $client->findLineByUsername($username);
        if ($line === null || !isset($line['id'])) {
            return null;
        }
        $this->setProp($service, 'line_id', (string) (int) $line['id'], 'Xtream UI Pro line ID');

        return (int) $line['id'];
    }

    private function requireLineId(Client $client, Service $service): int
    {
        return $this->resolveLineId($client, $service) ?? throw new ApiException('RESOURCE_NOT_FOUND');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** The extension instance of the server a service's product is bound to, or null if it is not ours. */
    private static function forService(Service $service): ?self
    {
        $server = $service->product?->server;
        if ($server === null || $server->extension !== self::EXTENSION) {
            return null;
        }
        $ext = ExtensionHelper::getExtension('server', $server->extension, $server->settings);

        return $ext instanceof self ? $ext : null;
    }

    private function client(): Client
    {
        // $this->config is filled by Paymenter from the server's settings.
        return new Client($this->config['host'] ?? '', $this->config['api_key'] ?? '');
    }

    private static function isReseller($settings): bool
    {
        return is_array($settings) && ($settings['service_type'] ?? 'line') === 'reseller';
    }

    /** Checkbox values come back as "1" / "0" / "" / true; a missing value is the default. */
    private static function flag($value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Whole number >= 0 from a product setting. */
    private static function credits($value, string $label): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        if (!ctype_digit($value) || strlen($value) > 9) {
            throw new Exception($label . ' in the product configuration must be a whole number of 0 or more.');
        }

        return (int) $value;
    }

    private static function formatExpiry($exp): string
    {
        if ($exp === null || $exp === '' || (int) $exp <= 0) {
            return 'Never expires';
        }

        return gmdate('Y-m-d H:i', (int) $exp) . ' UTC';
    }

    /** Random string from a fixed alphabet, using random_int. */
    private static function random(int $length, string $alphabet): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    // ---- service properties ----------------------------------------------

    private function prop(Service $service, string $key): ?string
    {
        $value = $service->properties()->where('key', 'xtreampro_' . $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function setProp(Service $service, string $key, string $value, string $name): void
    {
        $service->properties()->updateOrCreate(['key' => 'xtreampro_' . $key], ['name' => $name, 'value' => $value]);
    }

    /**
     * The password of a sub-reseller account is kept encrypted (Laravel Crypt):
     * property values end up in Paymenter's audit log in clear otherwise.
     */
    private function setSecret(Service $service, string $password): void
    {
        $this->setProp($service, 'secret', Crypt::encryptString($password), 'Xtream UI Pro password (encrypted)');
    }

    private function secret(Service $service): string
    {
        $value = $this->prop($service, 'secret');
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (Throwable $e) {
            return '';
        }
    }

    /** How many times the panel object of this service was terminated: part of the create request id. */
    private function generation(Service $service): int
    {
        return (int) $this->prop($service, 'generation');
    }

    /**
     * Forget the panel object of a terminated service. The generation goes up,
     * so a later Create is a new request for the panel.
     */
    private function closeMapping(Service $service): void
    {
        $generation = $this->generation($service) + 1;
        $service->properties()->whereIn('key', [
            'xtreampro_line_id', 'xtreampro_user_id', 'xtreampro_username', 'xtreampro_secret',
            'xtreampro_renew_invoices', 'xtreampro_renew_error',
        ])->delete();
        $this->setProp($service, 'generation', (string) $generation, 'Xtream UI Pro generation');
    }

    /**
     * Idempotency key for the panel (64 characters at most): what it is, which
     * Paymenter installation, which service, and a counter (generation or
     * invoice). The installation part keeps a re-installed Paymenter, whose
     * service numbers start at 1 again, from replaying the old requests.
     */
    private function requestId(string $what, Service $service, string $counter): string
    {
        return 'pm-' . substr(hash('sha256', 'xtreampro|' . config('app.key')), 0, 8) . '-' . $what . '-' . $service->id . '-' . $counter;
    }

    // ---- logging (never the API key, never a password) ---------------------

    private function log(string $action, Service $service, array $context = []): void
    {
        Log::info('Xtream UI Pro: ' . $action . ' ok', ['service' => $service->id] + $context);
    }

    private function logFailure(string $action, Service $service, Throwable $e): void
    {
        $context = ['service' => $service->id, 'error' => $e->getMessage()];
        if ($e instanceof ApiException) {
            $context += ['code' => $e->getErrorCode(), 'http' => $e->getHttpStatus()];
        }
        Log::warning('Xtream UI Pro: ' . $action . ' failed', $context);
    }
}
