<?php

declare(strict_types=1);

use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Captcha\Model\CaptchaResult;
use Weline\Framework\Manager\ObjectManager;
use Weline\SessionManager\Model\AuthenticatedDevice;
use Weline\SessionManager\Model\RememberedDeviceCredential;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const DEVICE_E2E_PASSWORD = 'DeviceManagerPass9';
const DEVICE_E2E_CAPTCHA_ANSWER = 'A2B3C4';

/** @return array<string,mixed> */
function device_e2e_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $payload */
function device_e2e_output(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function device_e2e_cleanup(int $customerId): void
{
    if ($customerId <= 0) {
        return;
    }
    $om = ObjectManager::getInstance();
    /** @var AuthenticatedDevice $deviceModel */
    $deviceModel = clone $om->get(AuthenticatedDevice::class);
    $devices = $deviceModel->reset()
        ->where(AuthenticatedDevice::schema_fields_AUTH_AREA, 'frontend')
        ->where(AuthenticatedDevice::schema_fields_PRINCIPAL_ID, (string)$customerId)
        ->select()
        ->fetchArray();
    $deviceIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row[AuthenticatedDevice::schema_fields_ID] ?? 0),
        $devices,
    )));
    if ($deviceIds !== []) {
        /** @var RememberedDeviceCredential $credential */
        $credential = clone $om->get(RememberedDeviceCredential::class);
        $credential->reset()
            ->where(RememberedDeviceCredential::schema_fields_DEVICE_ID, $deviceIds, 'IN')
            ->delete()
            ->fetch();
        $deviceModel->reset()
            ->where(AuthenticatedDevice::schema_fields_ID, $deviceIds, 'IN')
            ->delete()
            ->fetch();
    }
    /** @var CustomerToken $legacyToken */
    $legacyToken = clone $om->get(CustomerToken::class);
    $legacyToken->reset()
        ->where(CustomerToken::schema_fields_user_id, $customerId)
        ->delete()
        ->fetch();
    if ($customerId > 1) {
        /** @var Customer $customer */
        $customer = clone $om->get(Customer::class);
        $customer->reset()->where(Customer::schema_fields_ID, $customerId)->delete()->fetch();
    }
}

function device_e2e_prepare_captcha(string $token): string
{
    if (preg_match('/\A[a-f0-9]{48}\z/D', $token) !== 1) {
        throw new InvalidArgumentException('invalid captcha challenge');
    }

    /** @var CaptchaResult $result */
    $result = clone ObjectManager::getInstance(CaptchaResult::class);
    $result->clearData()->clearQuery()
        ->where(CaptchaResult::schema_fields_TOKEN, $token)
        ->where(CaptchaResult::schema_fields_TYPE, 'local_image')
        ->find()
        ->fetch();
    if (!$result->getId()) {
        throw new RuntimeException('captcha challenge was not found');
    }

    $result
        ->setData(CaptchaResult::schema_fields_CODE, password_hash(DEVICE_E2E_CAPTCHA_ANSWER, PASSWORD_DEFAULT))
        ->setData(CaptchaResult::schema_fields_EXPIRES_AT, date('Y-m-d H:i:s', time() + 300))
        ->save();

    return DEVICE_E2E_CAPTCHA_ANSWER;
}

/** @return array{device_count:int,distinct_session_count:int,credential_count:int,device_ids:list<string>} */
function device_e2e_inspect(int $customerId): array
{
    if ($customerId <= 0) {
        throw new InvalidArgumentException('invalid customer');
    }

    $om = ObjectManager::getInstance();
    /** @var AuthenticatedDevice $deviceModel */
    $deviceModel = clone $om->get(AuthenticatedDevice::class);
    $devices = $deviceModel->reset()
        ->where(AuthenticatedDevice::schema_fields_AUTH_AREA, 'frontend')
        ->where(AuthenticatedDevice::schema_fields_PRINCIPAL_ID, (string)$customerId)
        ->select()
        ->fetchArray();
    $deviceIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row[AuthenticatedDevice::schema_fields_ID] ?? 0),
        $devices,
    )));
    $credentialCount = 0;
    if ($deviceIds !== []) {
        /** @var RememberedDeviceCredential $credential */
        $credential = clone $om->get(RememberedDeviceCredential::class);
        $credentialCount = count($credential->reset()
            ->where(RememberedDeviceCredential::schema_fields_DEVICE_ID, $deviceIds, 'IN')
            ->select()
            ->fetchArray());
    }

    return [
        'device_count' => count($devices),
        'distinct_session_count' => count(array_unique(array_map(
            static fn(array $row): string => (string)($row[AuthenticatedDevice::schema_fields_SESSION_DIGEST] ?? ''),
            $devices,
        ))),
        'credential_count' => $credentialCount,
        'device_ids' => array_values(array_map(
            static fn(array $row): string => (string)($row[AuthenticatedDevice::schema_fields_PUBLIC_ID] ?? ''),
            $devices,
        )),
    ];
}

$input = device_e2e_input();
$action = (string)($input['action'] ?? '');
try {
    if ($action === 'prepare') {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', (string)($input['token'] ?? '')) ?: bin2hex(random_bytes(5));
        $email = 'e2e.device.' . strtolower($token) . '@example.test';
        /** @var CustomerAccountService $accounts */
        $accounts = ObjectManager::getInstance(CustomerAccountService::class);
        $existing = $accounts->findByEmail($email);
        if ($existing !== null && $existing->getId()) {
            device_e2e_cleanup((int)$existing->getId());
        }
        $result = $accounts->register($email, DEVICE_E2E_PASSWORD, [
            'firstname' => 'Device',
            'lastname' => 'E2E',
        ]);
        $customer = $result['customer'] ?? null;
        $customerId = is_object($customer) && method_exists($customer, 'getId') ? (int)$customer->getId() : 0;
        if ($customerId <= 0) {
            throw new RuntimeException('customer registration failed');
        }
        device_e2e_output([
            'ok' => true,
            'customer_id' => $customerId,
            'email' => $email,
            'password' => DEVICE_E2E_PASSWORD,
        ]);
    }
    if ($action === 'cleanup') {
        device_e2e_cleanup((int)($input['customer_id'] ?? 0));
        device_e2e_output(['ok' => true]);
    }
    if ($action === 'prepare_captcha') {
        device_e2e_output([
            'ok' => true,
            'answer' => device_e2e_prepare_captcha(trim((string)($input['captcha_token'] ?? ''))),
        ]);
    }
    if ($action === 'inspect') {
        device_e2e_output([
            'ok' => true,
            ...device_e2e_inspect((int)($input['customer_id'] ?? 0)),
        ]);
    }
    throw new RuntimeException('unknown action');
} catch (Throwable $exception) {
    device_e2e_output(['ok' => false, 'error' => $exception->getMessage()]);
}
