<?php
declare(strict_types=1);

/**
 * TEST-REFUND-02 夹具：准备 / 清理退款协调 harness。
 *
 * stdin JSON: { "action": "prepare"|"cleanup" }
 * stdout JSON only.
 */

use Weline\Order\Service\RefundCoordinatorHarnessCatalog;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * @return array<string, mixed>
 */
function refund02_read_input(): array
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
function refund02_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function refund02_fail(string $message, int $code = 1): never
{
    refund02_output(['ok' => false, 'error' => $message]);
    exit($code);
}

try {
    $input = refund02_read_input();
    $action = (string)($input['action'] ?? '');

    if ($action === 'prepare') {
        RefundCoordinatorHarnessCatalog::clear();
        // 空袋落盘，标记 harness 激活；真实订单由 Browser 经 refund.seedPaidOrder 写入。
        RefundCoordinatorHarnessCatalog::put([
            'orders' => [],
            'cases' => [],
            'payments' => [],
            'by_idem' => [],
            'outbox' => [],
            'ledger' => [],
            'urgent' => [],
            'frozen_orders' => [],
        ]);

        refund02_output([
            'ok' => true,
            'harness_active' => RefundCoordinatorHarnessCatalog::isActive(),
            'order_uuid' => 'ord-refund02-' . \bin2hex(\random_bytes(4)),
            'captured_amount_minor' => 10000,
            'currency' => 'CNY',
            'items' => [
                [
                    'item_uuid' => 'i1',
                    'qty_minor' => 2,
                    'unit_price_minor' => 4000,
                    'shipped' => false,
                ],
                [
                    'item_uuid' => 'i2',
                    'qty_minor' => 1,
                    'unit_price_minor' => 2000,
                    'shipped' => true,
                ],
            ],
            'refund_amount_minor' => 1000,
            'idempotency_key' => 'idem-refund02-' . \bin2hex(\random_bytes(4)),
        ]);
        exit(0);
    }

    if ($action === 'cleanup') {
        RefundCoordinatorHarnessCatalog::clear();
        refund02_output(['ok' => true, 'cleaned' => true]);
        exit(0);
    }

    refund02_fail('unknown action: ' . $action);
} catch (\Throwable $e) {
    refund02_fail($e->getMessage());
}
