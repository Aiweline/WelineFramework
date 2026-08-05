<?php

declare(strict_types=1);

/**
 * TASK-P4D-002 account-assets fixture: real Customer + PostgreSQL CustomerAsset rows.
 *
 * stdin JSON: {"action":"prepare"|"cleanup","token"?:string,"customer_id"?:int}
 * stdout JSON: ok + credentials / expected projection
 */

use Weline\Customer\Service\CustomerAccountService;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Strategy\WlsStrategy;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Service\CommerceRolloutGate;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P4D2_ASSET_EMAIL_PREFIX = 'e2e.p4d2.asset.';
const P4D2_ASSET_PASSWORD = 'P4d2AssetPass9';

/** @return array<string, mixed> */
function p4d2_asset_read_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function p4d2_asset_output(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function p4d2_asset_fail(string $message): never
{
    p4d2_asset_output(['ok' => false, 'error' => $message]);
}

/** @return array<string, mixed> */
function p4d2_asset_bootstrap_wls_session(
    \Weline\Customer\Model\Customer $customer,
): array {
    $config = (array)Env::getInstance()->getConfig('session');
    $ttl = (int)($config['lifetime'] ?? $config['session_ttl'] ?? 3600);
    $strategyConfig = [
        'lifetime' => $ttl,
        'cookie_path' => $config['cookie_path'] ?? '/',
        'cookie_domain' => $config['cookie_domain'] ?? '',
        'cookie_secure' => $config['cookie_secure'] ?? null,
        'cookie_httponly' => $config['cookie_httponly'] ?? true,
        'cookie_samesite' => $config['cookie_samesite'] ?? 'Lax',
        'cookie_partitioned' => $config['cookie_partitioned'] ?? null,
        'cookie_lifetime' => (int)($config['cookie_lifetime'] ?? 86400 * 30),
    ];

    $factory = SessionFactory::getInstance();
    $factory->resetRequestInstances();
    Session::resetRequestState();
    $storage = $factory->createStorage('wls');
    $strategy = new WlsStrategy($storage, $strategyConfig);
    $rawSession = new Session($storage, $strategy, $ttl);
    $session = new AuthenticatedSession($rawSession, new AreaConfig('frontend'));
    $session->start(null);
    $session->login($customer);
    $sessionId = $session->getId();
    if ($sessionId === '') {
        throw new \RuntimeException('frontend WLS session id is empty');
    }

    $customer->setSessionId($sessionId)
        ->setLoginIp('127.0.0.1')
        ->resetAttemptTimes()
        ->save();
    $rawSession->save();
    $strategy->writeClose();
    Session::flushRequestSessions();

    return [
        'name' => WlsStrategy::SESSION_NAME,
        'id' => $sessionId,
        'cookie_path' => (string)($config['cookie_path'] ?? '/'),
        'cookie_lifetime' => (int)($config['cookie_lifetime'] ?? 86400 * 30),
    ];
}

/** @return array<string, mixed> */
function p4d2_asset_prepare(?string $token): array
{
    $om = ObjectManager::getInstance();
    $token = $token !== null && $token !== ''
        ? preg_replace('/[^a-zA-Z0-9]/', '', $token)
        : '';
    if ($token === null || $token === '') {
        $token = substr(bin2hex(random_bytes(6)), 0, 10);
    }

    $email = P4D2_ASSET_EMAIL_PREFIX . strtolower($token) . '@example.test';
    $password = P4D2_ASSET_PASSWORD;
    /** @var CustomerAccountService $accounts */
    $accounts = $om->get(CustomerAccountService::class);
    $existing = $accounts->findByEmail($email);
    if ($existing !== null && $existing->getId()) {
        p4d2_asset_cleanup((int)$existing->getId());
    }

    $registered = $accounts->register($email, $password, [
        'firstname' => 'E2E',
        'lastname' => 'P4D2Asset',
    ]);
    /** @var \Weline\Customer\Model\Customer $customer */
    $customer = $registered['customer'];
    $customerId = (int)$customer->getId();
    if ($customerId <= 0) {
        p4d2_asset_fail('customer register failed');
    }

    $gate = new CommerceRolloutGate();
    $gate->setMode(
        CustomerAssetService::CAPABILITY,
        CommerceRolloutGateInterface::MODE_ALLOWLIST,
        ['website:0'],
    );
    $assets = new CustomerAssetService(rolloutGate: $gate);
    $eventPrefix = 'e2e-p4d2-asset-' . $token;
    $credit = $assets->credit([
        'customer_id' => $customerId,
        'website_id' => 0,
        'asset_code' => 'credit',
        'amount_minor' => 1200,
        'event_id' => $eventPrefix . ':credit',
    ]);
    $reserved = $assets->reserve([
        'customer_id' => $customerId,
        'website_id' => 0,
        'asset_code' => 'credit',
        'amount_minor' => 300,
        'event_id' => $eventPrefix . ':reserve',
    ]);

    if (($credit['ok'] ?? false) !== true || ($reserved['ok'] ?? false) !== true) {
        p4d2_asset_cleanup($customerId);
        p4d2_asset_fail('customer asset fixture write failed');
    }
    $session = p4d2_asset_bootstrap_wls_session($customer);

    return [
        'ok' => true,
        'token' => $token,
        'customer_id' => $customerId,
        'email' => $email,
        'password' => $password,
        'website_id' => 0,
        'asset_code' => 'credit',
        'session' => $session,
        'expected' => [
            'available_minor' => 1200,
            'reserved_minor' => 300,
            'reservable_minor' => 900,
            'event_types' => [
                AssetLedger::TYPE_CREDIT,
                AssetLedger::TYPE_RESERVE,
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function p4d2_asset_cleanup(int $customerId, string $sessionId = ''): array
{
    if ($sessionId !== '') {
        SessionFactory::getInstance()->createStorage('wls')->destroy($sessionId);
    }
    if ($customerId <= 1) {
        return ['ok' => true, 'cleaned_customer_id' => 0];
    }

    $om = ObjectManager::getInstance();
    /** @var AssetReservation $reservation */
    $reservation = clone $om->get(AssetReservation::class);
    $reservation->reset()
        ->where(AssetReservation::schema_fields_CUSTOMER_ID, (string)$customerId)
        ->delete()
        ->fetch();

    /** @var AssetLedger $ledger */
    $ledger = clone $om->get(AssetLedger::class);
    $ledger->reset()
        ->where(AssetLedger::schema_fields_CUSTOMER_ID, (string)$customerId)
        ->delete()
        ->fetch();

    /** @var AssetAccount $assetAccount */
    $assetAccount = clone $om->get(AssetAccount::class);
    $assetAccount->reset()
        ->where(AssetAccount::schema_fields_CUSTOMER_ID, (string)$customerId)
        ->delete()
        ->fetch();

    /** @var \Weline\Customer\Model\Customer $customer */
    $customer = clone $om->get(\Weline\Customer\Model\Customer::class);
    $customer->reset()
        ->where(\Weline\Customer\Model\Customer::schema_fields_ID, $customerId)
        ->delete()
        ->fetch();

    return ['ok' => true, 'cleaned_customer_id' => $customerId];
}

$input = p4d2_asset_read_input();
$action = (string)($input['action'] ?? '');
try {
    if ($action === 'prepare') {
        p4d2_asset_output(p4d2_asset_prepare(
            isset($input['token']) ? (string)$input['token'] : null,
        ));
    }
    if ($action === 'cleanup') {
        p4d2_asset_output(p4d2_asset_cleanup(
            (int)($input['customer_id'] ?? 0),
            (string)($input['session_id'] ?? ''),
        ));
    }
    p4d2_asset_fail('unknown action');
} catch (Throwable $throwable) {
    p4d2_asset_fail($throwable->getMessage());
}
