<?php
declare(strict_types=1);

/**
 * TEST-P2A-07 夹具：激活 / 清理 Product Media harness 标记。
 *
 * stdin JSON: { "action": "prepare"|"cleanup", "run_id"?: string, "website_id"?: int }
 * stdout JSON only.
 */

use Weline\Product\Service\ProductMediaQueryHarnessCatalog;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * @return array<string, mixed>
 */
function p2a07_fixture_read(): array
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
function p2a07_fixture_out(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function p2a07_fixture_fail(string $message, int $code = 1): never
{
    p2a07_fixture_out(['ok' => false, 'error' => $message]);
    exit($code);
}

try {
    $input = p2a07_fixture_read();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        $runId = trim((string)($input['run_id'] ?? ''));
        if ($runId === '') {
            $runId = 'p2a07-' . bin2hex(random_bytes(6));
        }
        $websiteId = (int)($input['website_id'] ?? 0);
        ProductMediaQueryHarnessCatalog::put([
            'run_id' => $runId,
            'website_id' => $websiteId,
            'product_ids' => [],
            'media_ids' => [],
        ]);
        p2a07_fixture_out([
            'ok' => true,
            'harness_active' => ProductMediaQueryHarnessCatalog::isActive(),
            'run_id' => $runId,
            'website_id' => $websiteId,
        ]);
        exit(0);
    }
    if ($action === 'cleanup') {
        ProductMediaQueryHarnessCatalog::clear();
        p2a07_fixture_out(['ok' => true, 'cleaned' => true]);
        exit(0);
    }
    p2a07_fixture_fail('unknown action: ' . $action);
} catch (\Throwable $e) {
    p2a07_fixture_fail($e->getMessage());
}
