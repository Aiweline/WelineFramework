<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Unit/bootstrap.php';

use Weline\CustomerAsset\Service\CustomerAssetConflictException;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\CommerceRolloutGate;

$database = trim((string) getenv('WELINE_CUSTOMER_ASSET_TEST_DATABASE'));
if ($database === '') {
    throw new RuntimeException('customer_asset_test_database_missing');
}
$env = include BP . '/app/etc/env.php';
$db = is_array($env) ? ($env['db']['master'] ?? $env['db'] ?? []) : [];
if (!is_array($db)) {
    throw new RuntimeException('customer_asset_master_database_config_unavailable');
}
$db['database'] = $database;
ObjectManager::clearInstances();
ObjectManager::getInstance(MigrationTargetBinder::class)->bindIsolated($db);

$lockPath = (string) ($argv[1] ?? '');
$customerId = (string) ($argv[2] ?? '');
$eventId = (string) ($argv[3] ?? '');
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock)) {
    throw new RuntimeException('customer_asset_worker_lock_unavailable');
}
flock($lock, LOCK_SH);
flock($lock, LOCK_UN);
fclose($lock);

$gate = new CommerceRolloutGate();
$gate->setMode(
    CustomerAssetService::CAPABILITY,
    CommerceRolloutGate::MODE_ALLOWLIST,
    ['website:0'],
);
$service = new CustomerAssetService(rolloutGate: $gate);

try {
    $result = $service->reserve([
        'customer_id' => $customerId,
        'website_id' => 0,
        'asset_code' => 'credit',
        'amount_minor' => 1000,
        'event_id' => $eventId,
    ]);
    echo json_encode([
        'status' => 'reserved',
        'reservation_id' => $result['reservation']['reservation_id'],
    ], JSON_THROW_ON_ERROR);
} catch (CustomerAssetConflictException $exception) {
    echo json_encode([
        'status' => 'conflict',
        'error_code' => $exception->errorCode,
    ], JSON_THROW_ON_ERROR);
}
