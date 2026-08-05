<?php

declare(strict_types=1);

/**
 * TEST-P1D-03 / TEST-P1D-05 PostgreSQL fixture.
 *
 * stdin JSON: {"action":"prepare"|"cleanup","token":"...","origin":"https://127.0.0.1:9827"}
 * stdout JSON only.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Model\MaintenancePreviewToken;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\ScopeMaintenanceAudit;
use Weline\Websites\Model\ScopeMaintenanceState;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\MaintenancePreviewTokenService;
use Weline\Websites\Service\ScopeMaintenanceGate;
use Weline\Websites\Service\WebsiteCacheInvalidationService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P1D_GATE_PREFIX = 'e2e_p1dg_';

/** @return array<string,mixed> */
function p1d_gate_input(): array
{
    $raw = file_get_contents('php://stdin');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('stdin must be a JSON object');
    }
    return $data;
}

/** @param array<string,mixed> $payload */
function p1d_gate_output(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function p1d_gate_token(string $token): string
{
    $token = preg_replace('/[^a-z0-9]/i', '', $token) ?? '';
    if ($token === '') {
        $token = bin2hex(random_bytes(4));
    }
    return substr(strtolower($token), 0, 12);
}

function p1d_gate_code(string $token, string $side): string
{
    return P1D_GATE_PREFIX . $side . '_' . p1d_gate_token($token);
}

function p1d_gate_scope(string $storeCode, string $mode): ScopeIdentity
{
    return ScopeIdentity::channel(
        Website::ID_DEFAULT,
        Website::CODE_DEFAULT,
        $storeCode,
        SalesChannel::CODE_DEFAULT,
        $mode,
    );
}

function p1d_gate_origin(string $origin): string
{
    $origin = rtrim(trim($origin), '/');
    $parts = parse_url($origin);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $port = (int)($parts['port'] ?? 0);
    if (!in_array($scheme, ['http', 'https'], true)
        || (string)($parts['host'] ?? '') === ''
        || $port < 9502
        || $port > 65535
        || isset($parts['user'])
        || isset($parts['pass'])
        || (string)($parts['path'] ?? '') !== '') {
        throw new InvalidArgumentException('fixture requires a dedicated WLS origin with port >= 9502');
    }
    return $origin;
}

/** @return list<array<string,mixed>> */
function p1d_gate_store_rows(string $token): array
{
    /** @var Store $stores */
    $stores = clone ObjectManager::getInstance(Store::class);
    $rows = $stores->clearData()->clearQuery()
        ->where(Store::schema_fields_WEBSITE_ID, Website::ID_DEFAULT)
        ->where(Store::schema_fields_CODE, P1D_GATE_PREFIX . '%', 'like')
        ->select()
        ->fetchArray();
    $needle = '_' . p1d_gate_token($token);
    return array_values(array_filter($rows, static fn(mixed $row): bool =>
        is_array($row) && str_ends_with((string)($row[Store::schema_fields_CODE] ?? ''), $needle)
    ));
}

function p1d_gate_cleanup(string $token): void
{
    $rows = p1d_gate_store_rows($token);
    $om = ObjectManager::getInstance();
    foreach ($rows as $row) {
        $storeId = (int)($row[Store::schema_fields_ID] ?? 0);
        $storeCode = (string)($row[Store::schema_fields_CODE] ?? '');
        $mode = (string)($row[Store::schema_fields_STORE_MODE] ?? Store::MODE_NORMAL);
        if ($storeId <= 0 || $storeCode === '') {
            continue;
        }
        $scopeKey = p1d_gate_scope($storeCode, $mode)->canonicalKey();
        foreach ([ScopeMaintenanceAudit::class, MaintenancePreviewToken::class, ScopeMaintenanceState::class] as $class) {
            $field = match ($class) {
                ScopeMaintenanceAudit::class => ScopeMaintenanceAudit::schema_fields_SCOPE_KEY,
                MaintenancePreviewToken::class => MaintenancePreviewToken::schema_fields_SCOPE_KEY,
                default => ScopeMaintenanceState::schema_fields_SCOPE_KEY,
            };
            $model = clone $om->get($class);
            $model->clear()->where($field, $scopeKey)->delete()->fetch();
        }

        $connector = $om->get(Store::class)->getConnection()->getConnector();
        $connector->query(
            'DELETE FROM ' . $connector->formatTableName(SalesChannel::schema_table)
            . ' WHERE "' . SalesChannel::schema_fields_STORE_ID . '" = ' . $storeId,
        )->fetch();
        $connector->query(
            'DELETE FROM ' . $connector->formatTableName(Store::schema_table)
            . ' WHERE "' . Store::schema_fields_ID . '" = ' . $storeId,
        )->fetch();
    }

    try {
        /** @var Store $store */
        $store = $om->get(Store::class);
        $om->get(WebsiteCacheInvalidationService::class)->invalidateWebsite(
            $store->getConnection(),
            Website::ID_DEFAULT,
            ['catalog'],
        );
    } catch (Throwable) {
    }
}

/** @return array{store_id:int,code:string,path:string,mode:string} */
function p1d_gate_create_store(string $token, string $side, string $mode, string $origin): array
{
    $code = p1d_gate_code($token, $side);
    $path = $code;
    /** @var Store $store */
    $store = clone ObjectManager::getInstance(Store::class);
    $store->clearData()->clearQuery()
        ->setWebsiteId(Website::ID_DEFAULT)
        ->setCode($code)
        ->setName('P1D Gate ' . strtoupper($side))
        ->setStoreMode($mode)
        ->setIsDefault(false)
        ->setStatus(true)
        ->setUrl($origin . '/' . $path)
        ->save(true);
    $storeId = (int)$store->getStoreId();
    if ($storeId <= 0) {
        throw new RuntimeException('failed to create fixture Store ' . $side);
    }

    /** @var SalesChannel $channel */
    $channel = clone ObjectManager::getInstance(SalesChannel::class);
    $channel->clearData()->clearQuery()
        ->setWebsiteId(Website::ID_DEFAULT)
        ->setStoreId($storeId)
        ->setCode(SalesChannel::CODE_DEFAULT)
        ->setName('P1D Gate Default')
        ->setIsDefault(true)
        ->setStatus(true)
        ->save(true);

    return ['store_id' => $storeId, 'code' => $code, 'path' => $path, 'mode' => $mode];
}

/** @return array<string,mixed> */
function p1d_gate_prepare(string $token, string $origin): array
{
    $token = p1d_gate_token($token);
    $origin = p1d_gate_origin($origin);
    p1d_gate_cleanup($token);
    $a = p1d_gate_create_store($token, 'a', Store::MODE_TEST, $origin);
    $b = p1d_gate_create_store($token, 'b', Store::MODE_NORMAL, $origin);
    $scopeA = p1d_gate_scope($a['code'], $a['mode']);
    $scopeB = p1d_gate_scope($b['code'], $b['mode']);
    $now = time();
    /** @var ScopeMaintenanceGate $gate */
    $gate = ObjectManager::getInstance(ScopeMaintenanceGate::class);
    /** @var MaintenancePreviewTokenService $tokens */
    $tokens = ObjectManager::getInstance(MaintenancePreviewTokenService::class);
    $gate->enable($scopeA, 'TEST-P1D-05', $now, 'e2e');
    $preview = $tokens->issue($scopeA, 300, $now, 'e2e');
    $readonlyBlocked = false;
    try {
        $gate->assertWritable($scopeA, true);
    } catch (RuntimeException $exception) {
        $readonlyBlocked = $exception->getMessage() === 'scope_maintenance_preview_readonly';
    }

    return [
        'ok' => true,
        'action' => 'prepare',
        'token' => $token,
        'origin' => $origin,
        'store_a' => $a,
        'store_b' => $b,
        'preview_token' => $preview,
        'maintenance' => [
            'a_enabled' => $gate->isMaintenance($scopeA),
            'b_enabled' => $gate->isMaintenance($scopeB),
            'token_valid_a' => $tokens->verify($preview, $scopeA, $now),
            'token_valid_b' => $tokens->verify($preview, $scopeB, $now),
            'token_expired' => !$tokens->verify($preview, $scopeA, $now + 301),
            'readonly_write_blocked' => $readonlyBlocked,
        ],
    ];
}

try {
    $input = p1d_gate_input();
    $action = (string)($input['action'] ?? '');
    $token = p1d_gate_token((string)($input['token'] ?? ''));
    $result = match ($action) {
        'prepare' => p1d_gate_prepare($token, (string)($input['origin'] ?? '')),
        'cleanup' => (function () use ($token): array {
            p1d_gate_cleanup($token);
            return ['ok' => true, 'action' => 'cleanup', 'token' => $token];
        })(),
        default => throw new InvalidArgumentException('unknown action: ' . $action),
    };
    p1d_gate_output($result);
} catch (Throwable $throwable) {
    p1d_gate_output(['ok' => false, 'error' => $throwable->getMessage()]);
    exit(1);
}




