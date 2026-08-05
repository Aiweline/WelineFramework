<?php
declare(strict_types=1);

/**
 * TEST-P2C-RENDER-01/02/03 夹具：配置 / 清理 Product Scene harness。
 *
 * stdin JSON: { "action": "prepare"|"cleanup", ... }
 * stdout JSON only.
 */

use Weline\Product\Service\ProductSceneQueryHarnessCatalog;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * @return array<string, mixed>
 */
function render_fixture_read(): array
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
function render_fixture_out(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function render_fixture_fail(string $message, int $code = 1): never
{
    render_fixture_out(['ok' => false, 'error' => $message]);
    exit($code);
}

try {
    $input = render_fixture_read();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        ProductSceneQueryHarnessCatalog::put([
            'providers' => [
                [
                    'code' => 'gift-ext',
                    'type' => 'gift',
                    'enabled' => true,
                    'renderer_mode' => ProductSceneQueryHarnessCatalog::MODE_MISSING_CLASS,
                ],
                [
                    'code' => 'empty-bug',
                    'type' => 'empty_bug',
                    'enabled' => true,
                    'renderer_mode' => ProductSceneQueryHarnessCatalog::MODE_EMPTY_BUG,
                ],
                [
                    'code' => 'empty-ok',
                    'type' => 'empty_ok',
                    'enabled' => true,
                    'renderer_mode' => ProductSceneQueryHarnessCatalog::MODE_HANDLED_EMPTY,
                ],
                [
                    'code' => 'boom',
                    'type' => 'boom',
                    'enabled' => true,
                    'renderer_mode' => ProductSceneQueryHarnessCatalog::MODE_THROW,
                ],
                [
                    'code' => 'injected',
                    'type' => 'injected',
                    'enabled' => true,
                    'renderer_mode' => ProductSceneQueryHarnessCatalog::MODE_INJECTED,
                ],
                [
                    'code' => 'disabled-gift',
                    'type' => 'disabled_gift',
                    'enabled' => false,
                    'renderer_mode' => ProductSceneQueryHarnessCatalog::MODE_NONE,
                ],
            ],
            'product' => [
                'name' => 'Harness Demo',
                'sku' => 'HARNESS-1',
                'description' => 'Harness product',
                'price_label' => '¥10',
            ],
        ]);
        render_fixture_out([
            'ok' => true,
            'harness_active' => ProductSceneQueryHarnessCatalog::isActive(),
            'xss_product' => [
                'name' => '<script>alert(1)</script>',
                'sku' => '"onload="',
                'description' => '<img src=x onerror=alert(1)>',
            ],
            'scenes' => ['list', 'detail', 'order_snapshot'],
        ]);
        exit(0);
    }
    if ($action === 'cleanup') {
        ProductSceneQueryHarnessCatalog::clear();
        render_fixture_out(['ok' => true, 'cleaned' => true]);
        exit(0);
    }
    render_fixture_fail('unknown action: ' . $action);
} catch (\Throwable $e) {
    render_fixture_fail($e->getMessage());
}
