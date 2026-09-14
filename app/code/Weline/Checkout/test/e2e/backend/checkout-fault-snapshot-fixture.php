<?php

declare(strict_types=1);

/**
 * Seed one checkout session error snapshot for backend list e2e.
 *
 * stdin: {"action":"prepare"} | {"action":"cleanup","quote_token":"..."}
 */

use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Manager\ObjectManager;

$root = dirname(__DIR__, 7);
require $root . '/app/bootstrap.php';

$input = json_decode((string)stream_get_contents(STDIN), true);
$input = is_array($input) ? $input : [];
$action = trim((string)($input['action'] ?? 'prepare'));

/** @param array<string, mixed> $payload */
function fault_snapshot_out(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

try {
    /** @var CheckoutSession $model */
    $model = ObjectManager::getInstance(CheckoutSession::class);
    if ($action === 'cleanup') {
        $token = trim((string)($input['quote_token'] ?? ''));
        if ($token !== '') {
            $model->clear()->where(CheckoutSession::schema_fields_QUOTE_TOKEN, $token)->delete();
        }
        fault_snapshot_out(['ok' => true]);
    }

    $token = 'qt_e2e_fault_' . bin2hex(random_bytes(6));
    $now = gmdate('Y-m-d H:i:s');
    $snapshot = [
        'error_code' => 'missing_weight',
        'country_code' => 'US',
        'province' => 'NY',
        'city' => 'New York',
        'postal_code' => '10001',
        'lines' => [[
            'sku' => 'E2E-MISS-WT',
            'product_id' => 321,
            'weight_minor' => 0,
            'qty' => 1,
        ]],
        'quote_diagnostics' => ['missing_weight' => true],
        'empty_message' => '购物车商品缺少重量，无法计算运费。请联系客服协助处理后再试。',
    ];
    $row = $model->clear()->where(CheckoutSession::schema_fields_QUOTE_TOKEN, $token)->find()->fetch();
    if (!$row instanceof CheckoutSession || !$row->getId()) {
        $row = ObjectManager::getInstance(CheckoutSession::class);
    }
    $row->setData([
        CheckoutSession::schema_fields_QUOTE_TOKEN => $token,
        CheckoutSession::schema_fields_REQUEST_HASH => hash('sha256', $token),
        CheckoutSession::schema_fields_CURRENCY => 'USD',
        CheckoutSession::schema_fields_CONFIG_VERSION => '1',
        CheckoutSession::schema_fields_STATE => CheckoutSession::STATE_QUOTED,
        CheckoutSession::schema_fields_PAYLOAD_JSON => json_encode([
            'quote_token' => $token,
            'state' => CheckoutSession::STATE_QUOTED,
            'browse' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CheckoutSession::schema_fields_CART_FINGERPRINT => hash('sha256', 'e2e-fault'),
        CheckoutSession::schema_fields_ERROR_CODE => 'missing_weight',
        CheckoutSession::schema_fields_ERROR_MESSAGE => '购物车商品缺少重量，无法计算运费。请联系客服协助处理后再试。',
        CheckoutSession::schema_fields_ERROR_SNAPSHOT_JSON => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CheckoutSession::schema_fields_ERROR_AT => $now,
        CheckoutSession::schema_fields_CREATED_AT => $now,
        CheckoutSession::schema_fields_EXPIRES_AT => gmdate('Y-m-d H:i:s', time() + 1800),
    ])->save();

    fault_snapshot_out([
        'ok' => true,
        'quote_token' => $token,
        'error_code' => 'missing_weight',
    ]);
} catch (Throwable $e) {
    fault_snapshot_out(['ok' => false, 'error' => $e->getMessage()], 1);
}
