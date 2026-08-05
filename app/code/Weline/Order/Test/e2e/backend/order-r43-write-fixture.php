<?php

declare(strict_types=1);

use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\OrderStatus;
use Weline\Order\Model\OrderStatusTranslation;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function order_r43_require_isolated_clone(): string
{
    if ((string)getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('R4.3 order fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $database = (string)($env['db']['master']['database'] ?? '');
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('R4.3 order fixture refuses non-PostgreSQL clone');
    }
    $probe = ObjectManager::getInstance(OrderStatus::class, [], false);
    if (!$probe->getConnection()->getConnector() instanceof PgsqlConnector) {
        throw new RuntimeException('R4.3 order fixture refuses non-PostgreSQL connector');
    }
    return $database;
}

/** @return array<string, mixed> */
function order_r43_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function order_r43_output(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

function order_r43_code(array $input): string
{
    $token = strtolower((string)($input['token'] ?? ''));
    $token = preg_replace('/[^a-z0-9]/', '', $token) ?: substr(bin2hex(random_bytes(8)), 0, 12);
    $letters = strtr($token, [
        '0' => 'a', '1' => 'b', '2' => 'c', '3' => 'd', '4' => 'e',
        '5' => 'f', '6' => 'g', '7' => 'h', '8' => 'i', '9' => 'j',
    ]);

    return 'r_ui_status_' . substr($letters, 0, 30);
}

function order_r43_find(string $code): ?OrderStatus
{
    /** @var OrderStatus $status */
    $status = ObjectManager::getInstance(OrderStatus::class, [], false);
    $status = $status->clear()
        ->where(OrderStatus::schema_fields_CODE, $code)
        ->find()
        ->fetch();

    return $status->getId() ? $status : null;
}

function order_r43_cleanup(string $code): int
{
    if (!str_starts_with($code, 'r_ui_status_')) {
        throw new RuntimeException('refusing cleanup outside the R43 order-status namespace');
    }
    $status = order_r43_find($code);
    if ($status === null) {
        return 0;
    }
    if ($status->isSystem()) {
        throw new RuntimeException('refusing cleanup of system order status');
    }
    /** @var OrderStatusTranslation $translations */
    $translations = clone ObjectManager::getInstance()->get(OrderStatusTranslation::class);
    $translations->clearData()->clearQuery()
        ->where(OrderStatusTranslation::schema_fields_STATUS_CODE, $code)
        ->delete()
        ->fetch();
    /** @var OrderStatus $statusDelete */
    $statusDelete = ObjectManager::getInstance(OrderStatus::class, [], false);
    $statusDelete->clearData()->clearQuery()
        ->where(OrderStatus::schema_fields_CODE, $code)
        ->delete()
        ->fetch();
    if (order_r43_find($code) !== null) {
        throw new RuntimeException('task-owned order status remains after cleanup');
    }

    return 1;
}

try {
    order_r43_require_isolated_clone();
    $input = order_r43_input();
    $action = trim((string)($input['action'] ?? ''));
    $code = order_r43_code($input);
    $name = '自动化验收状态 ' . substr($code, -8);

    if ($action === 'prepare') {
        order_r43_cleanup($code);
        order_r43_output(['ok' => true, 'code' => $code, 'name' => $name]);
    }
    if ($action === 'inspect') {
        $status = order_r43_find($code);
        order_r43_output([
            'ok' => true,
            'rows' => $status === null ? [] : [[
                'status_id' => (int)$status->getId(),
                'code' => (string)$status->getData(OrderStatus::schema_fields_CODE),
                'name' => (string)$status->getData(OrderStatus::schema_fields_NAME),
                'color' => (string)$status->getData(OrderStatus::schema_fields_COLOR),
                'is_active' => (int)$status->getData(OrderStatus::schema_fields_IS_ACTIVE),
                'sort_order' => (int)$status->getData(OrderStatus::schema_fields_SORT_ORDER),
            ]],
        ]);
    }
    if ($action === 'cleanup') {
        order_r43_output(['ok' => true, 'deleted' => order_r43_cleanup($code)]);
    }
    throw new InvalidArgumentException('unsupported action: ' . $action);
} catch (Throwable $throwable) {
    order_r43_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
