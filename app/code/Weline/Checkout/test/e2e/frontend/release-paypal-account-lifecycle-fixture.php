<?php

declare(strict_types=1);

/**
 * Release PayPal ↔ account lifecycle fixture.
 *
 * stdin JSON actions:
 * - prepare: create (or reuse) e2e customer; return PDP tip + credentials
 * - inspect: read order / payment / fulfillment by order_uuid
 * - inspect_transaction: read-only PaymentTransaction by transaction_no (paid/status)
 * - cleanup: remove fixture customer + tagged orders
 *
 * Does NOT forge payment success. Playwright must complete PayPal for real orders.
 */

use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\App\Env;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Strategy\WlsStrategy;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Payment\Model\PaymentTransaction;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const REL_PAYPAL_EMAIL_PREFIX = 'e2e.rel.paypal.';
const REL_PAYPAL_PASSWORD = 'RelPaypalE2e!234';

/** @return array<string, mixed> */
function rel_paypal_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && trim((string)$raw) !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function rel_paypal_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

function rel_paypal_fail(string $message): never
{
    rel_paypal_output(['ok' => false, 'error' => $message], 1);
}

/**
 * Prefer env tip; else probe known storefront PDP paths against local worker (no dialect SQL).
 * Hardcoded legacy slug may 301→/products/ after catalog churn — never trust it without HTTP check.
 */
function rel_paypal_resolve_pdp_path(): string
{
    $forced = trim((string)(getenv('WELINE_E2E_PDP_PATH') ?: getenv('CHECKOUT_PDP_PATH') ?: ''));
    $candidates = [];
    if ($forced !== '') {
        $candidates[] = $forced;
    }
    $candidates[] = '/product/yue-ya-ni-shang-yue-ya-ni-shang-zhang-an-yi-yuan-chuang-zheng-pin-tang-zhi-4d375d6d/';
    $candidates[] = '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';

    foreach ($candidates as $raw) {
        $path = trim((string)$raw);
        if ($path === '') {
            continue;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (rel_paypal_pdp_responds_ok($path)) {
            return $path;
        }
    }

    $fallback = $candidates[0] ?? '/products/';
    if ($fallback !== '' && $fallback[0] !== '/') {
        $fallback = '/' . $fallback;
    }

    return $fallback;
}

function rel_paypal_pdp_responds_ok(string $path): bool
{
    $origin = trim((string)(getenv('WELINE_E2E_STOREFRONT_ORIGIN') ?: getenv('WELINE_E2E_BASE_URL') ?: 'https://p05113ef3.test.weline.com'));
    $origin = rtrim($origin, '/');
    $url = $origin . $path;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'ignore_errors' => true,
            'header' => "User-Agent: WelineE2EFixture/1.0\r\nAccept: text/html\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $headers = @get_headers($url, true, $ctx);
    if (!is_array($headers) || !isset($headers[0])) {
        return false;
    }
    $statusLine = (string)$headers[0];
    if (!preg_match('/\s(200)\s/', $statusLine)) {
        return false;
    }
    // Reject soft redirects that land on listing shell without product CTA.
    $location = '';
    if (isset($headers['Location'])) {
        $location = is_array($headers['Location'])
            ? (string)end($headers['Location'])
            : (string)$headers['Location'];
    }

    return $location === '' || !preg_match('#/products/?$#i', $location);
}

/**
 * @return array{customer_id:int,email:string,password:string,token:string,pdp_path:string}
 */
function rel_paypal_prepare(?string $token): array
{
    $om = ObjectManager::getInstance();
    $token = $token !== null && $token !== ''
        ? (string)preg_replace('/[^a-zA-Z0-9]/', '', $token)
        : '';
    if ($token === '') {
        $token = substr(bin2hex(random_bytes(6)), 0, 10);
    }
    $email = REL_PAYPAL_EMAIL_PREFIX . strtolower($token) . '@example.test';
    $password = REL_PAYPAL_PASSWORD;

    /** @var CustomerAccountService $accounts */
    $accounts = $om->get(CustomerAccountService::class);
    $existing = $accounts->findByEmail($email);
    if ($existing !== null && $existing->getId()) {
        rel_paypal_cleanup_customer((int)$existing->getId(), $email);
    }

    $registered = $accounts->register($email, $password, [
        'firstname' => 'Rel',
        'lastname' => 'PayPal',
    ]);
    /** @var \Weline\Customer\Model\Customer $customer */
    $customer = $registered['customer'];
    $customerId = (int)$customer->getId();
    if ($customerId <= 0) {
        rel_paypal_fail('customer_register_failed');
    }

    $pdpPath = rel_paypal_resolve_pdp_path();

    return [
        'customer_id' => $customerId,
        'email' => $email,
        'password' => $password,
        'token' => $token,
        'pdp_path' => $pdpPath,
        'guest_email' => REL_PAYPAL_EMAIL_PREFIX . 'guest.' . strtolower($token) . '@example.test',
    ];
}

/**
 * @return array<string, mixed>
 */
function rel_paypal_inspect(string $orderUuid): array
{
    $orderUuid = trim($orderUuid);
    if ($orderUuid === '') {
        rel_paypal_fail('order_uuid_required');
    }

    $om = ObjectManager::getInstance();
    /** @var Order $orderModel */
    $orderModel = $om->get(Order::class);
    $rows = (clone $orderModel)->clear()
        ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
        ->select()
        ->fetchArray();
    $row = is_array($rows[0] ?? null) ? $rows[0] : null;
    if ($row === null) {
        rel_paypal_fail('order_not_found:' . $orderUuid);
    }

    $orderId = (int)($row[Order::schema_fields_ID] ?? 0);
    /** @var OrderItem $itemModel */
    $itemModel = $om->get(OrderItem::class);
    $items = (clone $itemModel)->clear()
        ->where(OrderItem::schema_fields_ORDER_ID, $orderId)
        ->select()
        ->fetchArray();

    /** @var PaymentTransaction $txModel */
    $txModel = $om->get(PaymentTransaction::class);
    $txs = [];
    try {
        $txs = (clone $txModel)->clear()
            ->where(PaymentTransaction::schema_fields_ORDER_ID, $orderId)
            ->select()
            ->fetchArray();
    } catch (Throwable) {
        $txs = [];
    }
    $successTx = 0;
    foreach ($txs as $tx) {
        $st = strtolower(trim((string)($tx[PaymentTransaction::schema_fields_STATUS] ?? $tx['status'] ?? '')));
        if (in_array($st, ['success', 'succeeded', 'paid', 'captured', 'completed'], true)) {
            $successTx++;
        }
    }

    $paid = $successTx >= 1
        || in_array(
            strtolower(trim((string)($row[Order::schema_fields_PAYMENT_STATUS] ?? ''))),
            ['paid', 'success', 'captured', 'completed'],
            true
        );

    return [
        'order_id' => $orderId,
        'order_uuid' => $orderUuid,
        'order_number' => (string)($row[Order::schema_fields_ORDER_NUMBER] ?? ''),
        'status' => (string)($row[Order::schema_fields_STATUS] ?? ''),
        'payment_status' => (string)($row[Order::schema_fields_PAYMENT_STATUS] ?? ''),
        'fulfillment_status' => (string)($row[Order::schema_fields_FULFILLMENT_STATUS] ?? ''),
        'customer_id' => (int)($row[Order::schema_fields_CUSTOMER_ID] ?? 0),
        'customer_email' => (string)($row[Order::schema_fields_CUSTOMER_EMAIL] ?? ''),
        'grand_total' => (float)($row[Order::schema_fields_GRAND_TOTAL] ?? 0),
        'currency' => (string)($row[Order::schema_fields_CURRENCY] ?? ''),
        'payment_method' => (string)($row[Order::schema_fields_PAYMENT_METHOD] ?? ''),
        'checkout_group_uuid' => (string)($row[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? ''),
        'item_count' => is_array($items) ? count($items) : 0,
        'success_transaction_count' => $successTx,
        'transaction_count' => is_array($txs) ? count($txs) : 0,
        'paid' => $paid,
    ];
}

/**
 * Thin read-only PaymentTransaction probe for e2e shell authority (no status machine).
 *
 * @return array<string, mixed>
 */
function rel_paypal_inspect_transaction(string $transactionNo): array
{
    $transactionNo = trim($transactionNo);
    if ($transactionNo === '') {
        return [
            'found' => false,
            'paid' => false,
            'is_success' => false,
            'status' => '',
            'transaction_no' => '',
        ];
    }

    $om = ObjectManager::getInstance();
    /** @var PaymentTransaction $tx */
    $tx = $om->get(PaymentTransaction::class);
    $tx->clear()->reset()
        ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
        ->find()
        ->fetch();
    if (!$tx->getId()) {
        return [
            'found' => false,
            'paid' => false,
            'is_success' => false,
            'status' => '',
            'transaction_no' => $transactionNo,
        ];
    }

    $status = strtolower(trim((string)$tx->getData(PaymentTransaction::schema_fields_STATUS)));
    $isSuccess = method_exists($tx, 'isSuccess') ? (bool)$tx->isSuccess() : ($status === PaymentTransaction::STATUS_SUCCESS);
    $paid = $isSuccess || in_array($status, ['success', 'succeeded', 'paid', 'captured', 'completed'], true);

    return [
        'found' => true,
        'paid' => $paid,
        'is_success' => $isSuccess,
        'status' => $status,
        'transaction_no' => $transactionNo,
        'order_id' => (string)$tx->getData(PaymentTransaction::schema_fields_ORDER_ID),
    ];
}

function rel_paypal_cleanup_customer(int $customerId, string $email = ''): void
{
    $om = ObjectManager::getInstance();
    /** @var Order $orderModel */
    $orderModel = $om->get(Order::class);
    $orders = [];
    if ($customerId > 0) {
        $orders = (clone $orderModel)->clear()
            ->where(Order::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetchArray();
    }
    if ($email !== '') {
        $byEmail = (clone $orderModel)->clear()
            ->where(Order::schema_fields_CUSTOMER_EMAIL, $email)
            ->select()
            ->fetchArray();
        foreach ($byEmail as $row) {
            $orders[] = $row;
        }
    }

    $seen = [];
    foreach ($orders as $row) {
        if (!is_array($row)) {
            continue;
        }
        $orderId = (int)($row[Order::schema_fields_ID] ?? 0);
        if ($orderId <= 0 || isset($seen[$orderId])) {
            continue;
        }
        $seen[$orderId] = true;
        try {
            /** @var OrderItem $itemModel */
            $itemModel = $om->get(OrderItem::class);
            (clone $itemModel)->clear()
                ->where(OrderItem::schema_fields_ORDER_ID, $orderId)
                ->delete()
                ->fetch();
        } catch (Throwable) {
        }
        try {
            (clone $orderModel)->clear()
                ->where(Order::schema_fields_ID, $orderId)
                ->delete()
                ->fetch();
        } catch (Throwable) {
        }
    }

    if ($customerId > 0) {
        try {
            /** @var CustomerAccountService $accounts */
            $accounts = $om->get(CustomerAccountService::class);
            if (method_exists($accounts, 'deleteById')) {
                $accounts->deleteById($customerId);
            } else {
                $customer = $accounts->findById($customerId);
                if ($customer && method_exists($customer, 'delete')) {
                    $customer->delete();
                }
            }
        } catch (Throwable) {
            try {
                $customerModel = $om->get(\Weline\Customer\Model\Customer::class);
                (clone $customerModel)->clear()
                    ->where('customer_id', $customerId)
                    ->delete()
                    ->fetch();
            } catch (Throwable) {
            }
        }
    }
}

try {
    $input = rel_paypal_input();
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'prepare') {
        $fixture = rel_paypal_prepare(isset($input['token']) ? (string)$input['token'] : null);
        rel_paypal_output(['ok' => true, 'fixture' => $fixture]);
    }

    if ($action === 'inspect') {
        $data = rel_paypal_inspect((string)($input['order_uuid'] ?? ''));
        rel_paypal_output(['ok' => true, 'data' => $data]);
    }

    if ($action === 'inspect_transaction') {
        $data = rel_paypal_inspect_transaction((string)($input['transaction_no'] ?? ''));
        rel_paypal_output(['ok' => true, 'data' => $data]);
    }

    if ($action === 'promote_guest') {
        $email = trim((string)($input['email'] ?? ''));
        $password = trim((string)($input['password'] ?? REL_PAYPAL_PASSWORD));
        if ($email === '') {
            rel_paypal_fail('guest_email_required');
        }
        /** @var CustomerAccountService $accounts */
        $accounts = ObjectManager::getInstance()->get(CustomerAccountService::class);
        $customer = $accounts->findByEmail($email);
        if ($customer === null || !$customer->getId()) {
            rel_paypal_fail('guest_customer_not_found:' . $email);
        }
        $customerId = (int)$customer->getId();
        $customer->setPassword($password);
        if (method_exists($customer, 'setMustSetPassword')) {
            $customer->setMustSetPassword(false);
        } else {
            $customer->setData(\Weline\Customer\Model\Customer::schema_fields_must_set_password, 0);
        }
        if (method_exists($customer, 'setAnonymousAccount')) {
            $customer->setAnonymousAccount(false);
        } else {
            $customer->setData(\Weline\Customer\Model\Customer::schema_fields_is_anonymous, 0);
        }
        $customer->save();
        rel_paypal_output([
            'ok' => true,
            'customer_id' => $customerId,
            'email' => $email,
            'password' => $password,
        ]);
    }

    if ($action === 'mint_session') {
        $email = trim((string)($input['email'] ?? ''));
        $host = trim((string)($input['host'] ?? ''));
        if ($email === '') {
            rel_paypal_fail('mint_session_email_required');
        }
        /** @var CustomerAccountService $accounts */
        $accounts = ObjectManager::getInstance()->get(CustomerAccountService::class);
        $customer = $accounts->findByEmail($email);
        if ($customer === null || !$customer->getId()) {
            rel_paypal_fail('mint_session_customer_not_found:' . $email);
        }

        // Align with tests/e2e/framework/customer-session-bootstrap.php:
        // CLI FpmStrategy only mutates $_SESSION and never reaches WLS shared store,
        // so Playwright cookies look valid but account pages 302 to login.
        SessionFactory::getInstance()->resetRequestInstances();
        Session::resetRequestState();
        $sessionConfig = (array)Env::getInstance()->getConfig('session');
        $ttl = (int)($sessionConfig['lifetime'] ?? $sessionConfig['session_ttl'] ?? 3600);
        $factory = SessionFactory::getInstance();
        $storage = $factory->createStorage('wls');
        $strategy = new WlsStrategy($storage, [
            'lifetime' => $ttl,
            'cookie_path' => $sessionConfig['cookie_path'] ?? '/',
            'cookie_domain' => $sessionConfig['cookie_domain'] ?? '',
            'cookie_secure' => $sessionConfig['cookie_secure'] ?? null,
            'cookie_httponly' => $sessionConfig['cookie_httponly'] ?? true,
            'cookie_samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax',
            'cookie_lifetime' => (int)($sessionConfig['cookie_lifetime'] ?? 86400 * 30),
        ]);
        $raw = new Session($storage, $strategy, $ttl);
        $session = new AuthenticatedSession($raw, new AreaConfig('frontend'));
        $session->start(null);
        $session->login($customer);
        $sessionId = trim((string)$session->getId());
        if ($sessionId === '') {
            rel_paypal_fail('mint_session_empty_id');
        }
        $customer->setSessionId($sessionId)->setLoginIp('127.0.0.1')->save();
        $rawSession = $session->getSession();
        $rawSession->save();
        if ($rawSession instanceof Session) {
            $rawSession->getStrategy()->writeClose();
        }
        Session::flushRequestSessions();

        // CLI has no Website RequestContext → CookieScope suffix is empty, but the
        // live storefront jar is WELINE_CUSTOMER_SESSID_w{id}. Default e2e host is w0.
        $websiteId = (int)($input['website_id'] ?? 0);
        if ($websiteId < 0) {
            $websiteId = 0;
        }
        CookieScope::setPolicyResolverOverride(static function () use ($websiteId): array {
            return [
                'active' => true,
                'name_suffix' => '_w' . $websiteId,
                'name_suffix_pattern' => '/_w\d+$/',
                'mount_path' => '/',
                'expire_unscoped_aliases' => true,
                'revision' => 'e2e_mint_session|w' . $websiteId,
            ];
        });
        try {
            $cookieName = SessionCookieNameResolver::resolve(
                $host !== '' ? $host : null,
                'frontend',
            );
        } finally {
            CookieScope::setPolicyResolverOverride(null);
        }
        if ($cookieName === '') {
            $cookieName = SessionCookieNameResolver::CUSTOMER_NAME . '_w' . $websiteId;
        }
        $unscoped = SessionCookieNameResolver::CUSTOMER_NAME;
        rel_paypal_output([
            'ok' => true,
            'customer_id' => (int)$customer->getId(),
            'email' => $email,
            'session_id' => $sessionId,
            'cookie_name' => $cookieName,
            'cookie_names' => array_values(array_unique([$cookieName, $unscoped])),
            'website_id' => $websiteId,
            'storage' => 'wls',
        ]);
    }

    if ($action === 'cleanup') {
        $customerId = (int)($input['customer_id'] ?? 0);
        $email = trim((string)($input['email'] ?? ''));
        $guestEmail = trim((string)($input['guest_email'] ?? ''));
        if ($customerId > 0 || $email !== '') {
            rel_paypal_cleanup_customer($customerId, $email);
        }
        if ($guestEmail !== '') {
            rel_paypal_cleanup_customer(0, $guestEmail);
            try {
                /** @var CustomerAccountService $accounts */
                $accounts = ObjectManager::getInstance()->get(CustomerAccountService::class);
                $guest = $accounts->findByEmail($guestEmail);
                if ($guest && $guest->getId()) {
                    rel_paypal_cleanup_customer((int)$guest->getId(), $guestEmail);
                }
            } catch (Throwable) {
            }
        }
        rel_paypal_output(['ok' => true, 'cleaned' => true]);
    }

    rel_paypal_fail('unknown_action:' . $action);
} catch (Throwable $e) {
    rel_paypal_fail($e->getMessage());
}
