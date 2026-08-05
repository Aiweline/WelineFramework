<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\ProductCopyOperation;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function p2c_copy_assert_isolated_database(): string
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_store_copy_requires_isolated_database_flag');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $database = trim((string)($env['db']['master']['database'] ?? ''));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_store_copy_requires_mig_clone_database:' . $database);
    }
    return $database;
}

function p2c_copy_assert_pgsql(): string
{
    $model = ObjectManager::getInstance(ProductCopyOperation::class, [], false);
    $connector = get_class($model->getConnection()->getConnector());
    $driver = strtolower($connector);
    if (!str_contains($driver, 'pgsql') && !str_contains($driver, 'postgres')) {
        throw new RuntimeException('r43_store_copy_requires_postgresql:' . $connector);
    }
    return $connector;
}

/** @return array<string, mixed> */
function p2c_copy_read_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function p2c_copy_output(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

/** @return list<string> */
function p2c_copy_draft_ids(array $input): array
{
    $raw = $input['draft_ids'] ?? null;
    if (!is_array($raw) || $raw === [] || count($raw) > 20) {
        throw new InvalidArgumentException('draft_ids must contain 1..20 task-owned IDs');
    }
    $ids = [];
    foreach ($raw as $candidate) {
        $id = trim((string)$candidate);
        if (!preg_match('/^draft-[a-f0-9]{16,64}$/', $id)) {
            throw new InvalidArgumentException('invalid task-owned draft ID: ' . $id);
        }
        $ids[$id] = true;
    }
    return array_keys($ids);
}

/** @return list<array{draft_id:string,state:string}> */
function p2c_copy_inspect(array $draftIds): array
{
    $rows = [];
    foreach ($draftIds as $draftId) {
        $model = new ProductCopyOperation();
        $model->clear()
            ->where(ProductCopyOperation::schema_fields_DRAFT_UUID, $draftId)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            continue;
        }
        $rows[] = [
            'draft_id' => (string)$model->getData(ProductCopyOperation::schema_fields_DRAFT_UUID),
            'state' => (string)$model->getData(ProductCopyOperation::schema_fields_STATE),
        ];
    }
    return $rows;
}

try {
    p2c_copy_assert_isolated_database();
    p2c_copy_assert_pgsql();
    $input = p2c_copy_read_input();
    $action = trim((string)($input['action'] ?? ''));
    $draftIds = p2c_copy_draft_ids($input);
    if ($action === 'inspect') {
        p2c_copy_output(['ok' => true, 'rows' => p2c_copy_inspect($draftIds)]);
    }
    if ($action === 'cleanup') {
        $before = p2c_copy_inspect($draftIds);
        foreach ($before as $row) {
            (new ProductCopyOperation())
                ->where(ProductCopyOperation::schema_fields_DRAFT_UUID, $row['draft_id'])
                ->delete()
                ->fetch();
        }
        $after = p2c_copy_inspect($draftIds);
        if ($after !== []) {
            throw new RuntimeException('task-owned Store Copy rows remain after cleanup');
        }
        p2c_copy_output(['ok' => true, 'deleted' => count($before), 'remaining' => 0]);
    }
    throw new InvalidArgumentException('unsupported action: ' . $action);
} catch (Throwable $throwable) {
    p2c_copy_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
