<?php
declare(strict_types=1);

/**
 * TEST-P4C-01 夹具：B2B harness — dealer 组价目 + shadow mode。
 *
 * stdin JSON: { "action": "prepare"|"cleanup" }
 * stdout JSON only.
 */

use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Service\B2BQueryHarnessCatalog;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * @return array<string, mixed>
 */
function p4c01_read_input(): array
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
function p4c01_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function p4c01_fail(string $message, int $code = 1): never
{
    p4c01_output(['ok' => false, 'error' => $message]);
    exit($code);
}

try {
    $input = p4c01_read_input();
    $action = (string)($input['action'] ?? '');

    if ($action === 'prepare') {
        B2BQueryHarnessCatalog::clear();
        B2BQueryHarnessCatalog::put([
            'groups' => [
                [
                    'group_id' => 'g-dealer',
                    'website_id' => 0,
                    'code' => 'dealer',
                    'status' => CustomerGroup::STATUS_ACTIVE,
                ],
            ],
            'membership' => [
                'cust-b2b' => 'g-dealer',
            ],
            'price_lists' => [
                [
                    'list_id' => 'pl-dealer-v1',
                    'group_id' => 'g-dealer',
                    'website_id' => 0,
                    'version' => 1,
                    'sku_amounts' => [
                        'SKU-A' => 800,
                        'SKU-B' => 1200,
                    ],
                    'channel_id' => null,
                    'active' => true,
                ],
            ],
            'rollout_mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'rollout_allowlist' => ['website:0'],
        ]);

        p4c01_output([
            'ok' => true,
            'harness_active' => B2BQueryHarnessCatalog::isActive(),
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
            'b2b_customer_id' => 'cust-b2b',
            'retail_customer_id' => 'cust-retail',
            'expected_b2b_source' => 'b2b_website',
            'expected_b2b_amount_minor' => 800,
            'expected_b2b_price_list_id' => 'pl-dealer-v1',
            'expected_retail_source' => 'retail',
            'expected_retail_amount_minor' => 1000,
            'expected_mode_off_source' => 'b2b_closed',
        ]);
        exit(0);
    }

    if ($action === 'cleanup') {
        B2BQueryHarnessCatalog::clear();
        p4c01_output(['ok' => true, 'cleaned' => true]);
        exit(0);
    }

    if ($action === 'set_mode') {
        $state = B2BQueryHarnessCatalog::load();
        if ($state === null) {
            p4c01_fail('harness inactive');
        }
        $mode = (string)($input['rollout_mode'] ?? '');
        if (!\in_array($mode, [
            CommerceRolloutGateInterface::MODE_OFF,
            CommerceRolloutGateInterface::MODE_SHADOW,
        ], true)) {
            p4c01_fail('unsupported fixture mode');
        }
        $state['rollout_mode'] = $mode;
        B2BQueryHarnessCatalog::put($state);
        p4c01_output(['ok' => true, 'rollout_mode' => $mode]);
        exit(0);
    }

    p4c01_fail('unknown action: ' . $action);
} catch (\Throwable $e) {
    p4c01_fail($e->getMessage());
}
