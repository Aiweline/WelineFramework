<?php
declare(strict_types=1);

/**
 * TEST-P2E-02 fixture：顾客账号 + harness Offer。
 *
 * stdin JSON: { "action": "prepare"|"seed_guest"|"inspect"|"cleanup", "token"?: string,
 *   "customer_id"?: int, "offer_uuid"?: string, "guest_token"?: string }
 * stdout JSON only.
 */

use Weline\Cart\Service\CartV2CacheStore;
use Weline\Cart\Service\CartV2HarnessCatalog;
use Weline\Cart\Service\CartV2Service;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P2E02_EMAIL_PREFIX = 'e2e.p2e02.';
const P2E02_PASSWORD = 'P2e02MergePass9';
const P2E02_STOCK = 5;

/**
 * @return array<string, mixed>
 */
function p2e02_read_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $payload
 */
function p2e02_output(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function p2e02_fail(string $message): never
{
    p2e02_output(['ok' => false, 'error' => $message]);
}

function p2e02_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * @return array<string, mixed>
 */
function p2e02_prepare(?string $token): array
{
    $om = ObjectManager::getInstance();
    $token = $token !== null && $token !== '' ? preg_replace('/[^a-zA-Z0-9]/', '', $token) : '';
    if ($token === null || $token === '') {
        $token = substr(bin2hex(random_bytes(6)), 0, 10);
    }
    $email = P2E02_EMAIL_PREFIX . strtolower($token) . '@example.test';
    $password = P2E02_PASSWORD;
    $offerUuid = p2e02_uuid();

    /** @var CustomerAccountService $accounts */
    $accounts = $om->get(CustomerAccountService::class);
    $existing = $accounts->findByEmail($email);
    if ($existing !== null && $existing->getId()) {
        p2e02_cleanup((int)$existing->getId(), null, null);
    }

    $registered = $accounts->register($email, $password, [
        'firstname' => 'E2E',
        'lastname' => 'P2E02',
    ]);
    $customer = is_array($registered) ? ($registered['customer'] ?? $registered['user'] ?? null) : $registered;
    if (!is_object($customer) || !method_exists($customer, 'getId') || (int)$customer->getId() <= 0) {
        // try find after register
        $customer = $accounts->findByEmail($email);
    }
    if (!is_object($customer) || !method_exists($customer, 'getId') || (int)$customer->getId() <= 0) {
        throw new RuntimeException('failed to register customer for p2e02');
    }
    $customerId = (int)$customer->getId();

    CartV2HarnessCatalog::put($offerUuid, [
        'name' => 'P2E02 Limited ' . $token,
        'sku' => 'p2e02-' . $token,
        'currency' => 'CNY',
        'unit_price_minor' => 1000,
        'stock' => P2E02_STOCK,
        'sellable' => true,
        'found' => true,
        'product_type' => 'simple',
    ]);
    return [
        'token' => $token,
        'customer_id' => $customerId,
        'email' => $email,
        'password' => $password,
        'offer_uuid' => $offerUuid,
        'provider_code' => 'product',
        'stock' => P2E02_STOCK,
        'website_id' => 0,
        'website_code' => 'default',
        'store_code' => 'default',
        'channel_code' => 'default',
        'store_mode' => ScopeIdentity::MODE_NORMAL,
        'guest_qty' => 4,
        'customer_pre_qty' => 2,
        'expected_merged_qty' => P2E02_STOCK,
    ];
}

/** @return array<string, mixed> */
function p2e02_inspect(int $customerId, string $guestToken): array
{
    $scope = ScopeIdentity::channel(
        0,
        'default',
        'default',
        'default',
        ScopeIdentity::MODE_NORMAL,
    );
    /** @var CartV2Service $cartV2 */
    $cartV2 = ObjectManager::getInstance()->get(CartV2Service::class);
    return [
        'customer_cart' => $cartV2->getCart($scope, customerId: $customerId),
        'guest_cart' => $cartV2->getCart($scope, $guestToken),
    ];
}

/** @return array<string, mixed> */
function p2e02_seed_guest(string $offerUuid, int $qty): array
{
    if (trim($offerUuid) === '') {
        throw new InvalidArgumentException('offer_uuid is required');
    }
    $scope = ScopeIdentity::channel(
        0,
        'default',
        'default',
        'default',
        ScopeIdentity::MODE_NORMAL,
    );
    /** @var CartV2Service $cartV2 */
    $cartV2 = ObjectManager::getInstance()->get(CartV2Service::class);
    $guestToken = $cartV2->issueGuestToken();
    $cart = $cartV2->add(
        $scope,
        new OfferIdentity('product', $offerUuid),
        ['color' => 'red'],
        max(1, $qty),
        $guestToken,
    );

    return [
        'guest_token' => $guestToken,
        'guest_cart' => $cart,
    ];
}

function p2e02_cleanup(int $customerId, ?string $offerUuid, ?string $guestToken): void
{
    if ($offerUuid !== null && $offerUuid !== '') {
        CartV2HarnessCatalog::delete($offerUuid);
    }
    $scopes = [
        ScopeIdentity::website(0, 'default'),
        ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL),
        ScopeIdentity::channel(0, 'default', 'store-a', 'web', ScopeIdentity::MODE_NORMAL),
        ScopeIdentity::channel(0, 'default', 'store-b', 'app', ScopeIdentity::MODE_NORMAL),
    ];
    try {
        /** @var CartV2CacheStore $store */
        $store = ObjectManager::getInstance()->get(CartV2CacheStore::class);
        foreach ($scopes as $scope) {
            if ($customerId > 0) {
                $store->delete($scope->canonicalKey() . '|customer:' . $customerId);
            }
            if ($guestToken !== null && trim($guestToken) !== '') {
                $store->delete($scope->canonicalKey() . '|guest:' . trim($guestToken));
            }
        }
    } catch (Throwable) {
        // best-effort cache cleanup
    }
    if ($customerId <= 0) {
        return;
    }
    try {
        /** @var \Weline\Customer\Model\Customer $customer */
        $customer = ObjectManager::getInstance()->get(\Weline\Customer\Model\Customer::class);
        $customer = clone $customer;
        $customer->clear()->load($customerId);
        if ((int)$customer->getId() > 0 && method_exists($customer, 'delete')) {
            $customer->delete();
        }
    } catch (Throwable) {
        // best-effort
    }
}

try {
    $input = p2e02_read_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        $prepared = p2e02_prepare(isset($input['token']) ? (string)$input['token'] : null);
        p2e02_output(['ok' => true, 'action' => 'prepare'] + $prepared);
    }
    if ($action === 'inspect') {
        $inspected = p2e02_inspect(
            (int)($input['customer_id'] ?? 0),
            (string)($input['guest_token'] ?? ''),
        );
        p2e02_output(['ok' => true, 'action' => 'inspect'] + $inspected);
    }
    if ($action === 'seed_guest') {
        $seeded = p2e02_seed_guest(
            (string)($input['offer_uuid'] ?? ''),
            (int)($input['qty'] ?? 1),
        );
        p2e02_output(['ok' => true, 'action' => 'seed_guest'] + $seeded);
    }
    if ($action === 'cleanup') {
        p2e02_cleanup(
            (int)($input['customer_id'] ?? 0),
            isset($input['offer_uuid']) ? (string)$input['offer_uuid'] : null,
            isset($input['guest_token']) ? (string)$input['guest_token'] : null,
        );
        p2e02_output(['ok' => true, 'action' => 'cleanup']);
    }
    throw new InvalidArgumentException('unknown action: ' . $action);
} catch (Throwable $e) {
    p2e02_fail($e->getMessage());
}
