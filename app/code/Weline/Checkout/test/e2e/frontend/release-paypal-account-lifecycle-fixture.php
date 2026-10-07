<?php

declare(strict_types=1);

/**
 * Release PayPal ↔ account lifecycle fixture.
 *
 * stdin JSON actions:
 * - prepare: create (or reuse) e2e customer; return PDP tip + credentials
 * - inspect: read order / payment / fulfillment by order_uuid
 * - cleanup: remove fixture customer + tagged orders
 *
 * Does NOT forge payment success. Playwright must complete PayPal for real orders.
 */

use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionFactory;
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

    $pdpPath = trim((string)(getenv('WELINE_E2E_PDP_PATH') ?: getenv('CHECKOUT_PDP_PATH') ?: ''));
    if ($pdpPath === '') {
        $pdpPath = '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
    }
    if ($pdpPath[0] !== '/') {
        $pdpPath = '/' . $pdpPath;
    }

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
        $accounts->loginCustomer($customer);
        /** @var SessionFactory $factory */
        $factory = ObjectManager::getInstance()->get(SessionFactory::class);
        $session = $factory->createFrontendSession();
        $sessionId = trim((string)$session->getId());
        if ($sessionId === '') {
            rel_paypal_fail('mint_session_empty_id');
        }
        $cookieName = SessionCookieNameResolver::resolve(
            $host !== '' ? $host : null,
            'frontend',
        );
        if ($cookieName === '') {
            $cookieName = SessionCookieNameResolver::CUSTOMER_NAME;
        }
        rel_paypal_output([
            'ok' => true,
            'customer_id' => (int)$customer->getId(),
            'email' => $email,
            'session_id' => $sessionId,
            'cookie_name' => $cookieName,
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
