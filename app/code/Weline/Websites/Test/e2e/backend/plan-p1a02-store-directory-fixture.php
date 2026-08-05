<?php
declare(strict_types=1);

/**
 * TEST-P1A-02 夹具：website_id=0 下创建 normal/dev/test 非默认 Store，并断言 mode 不可变 / 默认店不可删。
 *
 * stdin JSON: { "action": "prepare"|"cleanup", "token"?: string, "stores"?: list<{store_id,code}> }
 * stdout JSON only.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P1A02_PREFIX = 'e2e_p1a02_';

/**
 * @return array<string, mixed>
 */
function p1a02_read_input(): array
{
    $raw = \file_get_contents('php://stdin');
    if ($raw === false || \trim($raw) === '') {
        throw new \InvalidArgumentException('empty stdin');
    }
    $data = \json_decode($raw, true);
    if (!\is_array($data)) {
        throw new \InvalidArgumentException('stdin must be JSON object');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function p1a02_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function p1a02_fail(string $message, int $code = 1): never
{
    p1a02_output(['ok' => false, 'error' => $message]);
    exit($code);
}

function p1a02_assert_contains(string $haystack, string $needle, string $label): void
{
    if (!\str_contains($haystack, $needle)) {
        throw new \RuntimeException($label . ': expected message containing [' . $needle . '], got [' . $haystack . ']');
    }
}

/**
 * @return Store
 */
function p1a02_create_store(string $code, string $mode): Store
{
    $om = ObjectManager::getInstance();
    /** @var Store $store */
    $store = clone $om->get(Store::class);
    $store->clear()
        ->setWebsiteId(Website::ID_DEFAULT)
        ->setCode($code)
        ->setName('P1A02 ' . $code)
        ->setStoreMode($mode)
        ->setIsDefault(false)
        ->setStatus(true)
        ->save(true);
    $storeId = (int)$store->getStoreId();
    if ($storeId <= 0) {
        // reload by code
        $store = clone $om->get(Store::class);
        $store->clear()
            ->where(Store::schema_fields_WEBSITE_ID, Website::ID_DEFAULT)
            ->where(Store::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        $storeId = (int)$store->getStoreId();
    }
    if ($storeId <= 0) {
        throw new \RuntimeException('failed to create store: ' . $code);
    }
    if ($store->isDefault()) {
        throw new \RuntimeException('fixture store must not be default: ' . $code);
    }
    if ($store->getStoreMode() !== $mode) {
        throw new \RuntimeException('store mode mismatch for ' . $code);
    }

    return $store;
}

/**
 * @return array<string, mixed>
 */
function p1a02_prepare(?string $token): array
{
    $token = $token !== null && $token !== ''
        ? \preg_replace('/[^a-zA-Z0-9_-]/', '', $token) ?? ''
        : '';
    if ($token === '') {
        $token = \bin2hex(\random_bytes(3)) . (string)\getmypid();
    }
    // codes must stay short & unique
    $modes = [
        Store::MODE_NORMAL => P1A02_PREFIX . 'n_' . $token,
        Store::MODE_DEV => P1A02_PREFIX . 'd_' . $token,
        Store::MODE_TEST => P1A02_PREFIX . 't_' . $token,
    ];

    // cleanup leftover same-token stores first
    p1a02_cleanup_by_token($token);

    $stores = [];
    foreach ($modes as $mode => $code) {
        $created = p1a02_create_store($code, $mode);
        $stores[] = [
            'store_id' => (int)$created->getStoreId(),
            'code' => $code,
            'mode' => $mode,
            'name' => (string)$created->getName(),
        ];
    }

    // 不变量：店铺模式创建后不可变更
    $probe = $stores[0];
    $om = ObjectManager::getInstance();
    /** @var Store $toMutate */
    $toMutate = clone $om->get(Store::class);
    $toMutate->clear()->where(Store::schema_fields_ID, $probe['store_id'])->find()->fetch();
    $fromMode = (string)$toMutate->getStoreMode();
    $toMode = $fromMode === Store::MODE_NORMAL ? Store::MODE_DEV : Store::MODE_NORMAL;
    $modeBlocked = false;
    $modeMessage = '';
    try {
        $toMutate->setStoreMode($toMode)->save(true);
    } catch (\Throwable $e) {
        $modeBlocked = true;
        $modeMessage = $e->getMessage();
    }
    if (!$modeBlocked) {
        throw new \RuntimeException('expected store mode mutation to be rejected');
    }
    p1a02_assert_contains($modeMessage, '不可变更', 'mode_immutable');

    // 不变量：默认店铺不允许删除
    /** @var Store $defaultStore */
    $defaultStore = clone $om->get(Store::class);
    $defaultStore->clear()
        ->where(Store::schema_fields_WEBSITE_ID, Website::ID_DEFAULT)
        ->where(Store::schema_fields_CODE, Store::CODE_DEFAULT)
        ->find()
        ->fetch();
    if ((int)$defaultStore->getStoreId() <= 0) {
        throw new \RuntimeException('default store missing on website_id=0');
    }
    $defaultBlocked = false;
    $defaultMessage = '';
    try {
        $defaultStore->delete();
    } catch (\Throwable $e) {
        $defaultBlocked = true;
        $defaultMessage = $e->getMessage();
    }
    if (!$defaultBlocked) {
        throw new \RuntimeException('expected default store delete to be rejected');
    }
    p1a02_assert_contains($defaultMessage, '默认店铺不允许删除', 'default_undeletable');

    return [
        'token' => $token,
        'website_id' => Website::ID_DEFAULT,
        'stores' => $stores,
        'invariants' => [
            'mode_immutable' => true,
            'mode_message' => $modeMessage,
            'default_undeletable' => true,
            'default_message' => $defaultMessage,
        ],
        'list_route' => 'websites/admin/website',
    ];
}

function p1a02_cleanup_by_token(string $token): void
{
    if ($token === '') {
        return;
    }
    $om = ObjectManager::getInstance();
    /** @var Store $model */
    $model = clone $om->get(Store::class);
    $rows = $model->clear()
        ->where(Store::schema_fields_WEBSITE_ID, Website::ID_DEFAULT)
        ->where(Store::schema_fields_CODE, P1A02_PREFIX . '%', 'like')
        ->select()
        ->fetchArray();
    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }
        $code = (string)($row[Store::schema_fields_CODE] ?? '');
        if (!\str_contains($code, $token)) {
            continue;
        }
        $storeId = (int)($row[Store::schema_fields_ID] ?? 0);
        if ($storeId <= 0) {
            continue;
        }
        if (!empty($row[Store::schema_fields_IS_DEFAULT])) {
            continue;
        }
        /** @var Store $store */
        $store = clone $om->get(Store::class);
        $store->clear()->where(Store::schema_fields_ID, $storeId)->find()->fetch();
        if ((int)$store->getStoreId() <= 0 || $store->isDefault()) {
            continue;
        }
        if ($store->isTombstoned()) {
            continue;
        }
        try {
            $store->delete();
        } catch (\Throwable) {
            // best-effort
        }
    }
}

/**
 * @param list<array<string, mixed>> $stores
 */
function p1a02_cleanup(array $stores, string $token = ''): void
{
    $om = ObjectManager::getInstance();
    foreach ($stores as $item) {
        if (!\is_array($item)) {
            continue;
        }
        $storeId = (int)($item['store_id'] ?? 0);
        if ($storeId <= 0) {
            continue;
        }
        /** @var Store $store */
        $store = clone $om->get(Store::class);
        $store->clear()->where(Store::schema_fields_ID, $storeId)->find()->fetch();
        if ((int)$store->getStoreId() <= 0 || $store->isDefault()) {
            continue;
        }
        if ($store->isTombstoned()) {
            continue;
        }
        try {
            $store->delete();
        } catch (\Throwable $e) {
            throw new \RuntimeException('cleanup tombstone failed for store_id=' . $storeId . ': ' . $e->getMessage());
        }
    }
    if ($token !== '') {
        p1a02_cleanup_by_token($token);
    }
}

try {
    $input = p1a02_read_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        $prepared = p1a02_prepare(isset($input['token']) ? (string)$input['token'] : null);
        p1a02_output(['ok' => true, 'action' => 'prepare'] + $prepared);
        exit(0);
    }
    if ($action === 'cleanup') {
        $stores = $input['stores'] ?? [];
        if (!\is_array($stores)) {
            $stores = [];
        }
        p1a02_cleanup($stores, (string)($input['token'] ?? ''));
        p1a02_output(['ok' => true, 'action' => 'cleanup']);
        exit(0);
    }
    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $e) {
    p1a02_fail($e->getMessage());
}
