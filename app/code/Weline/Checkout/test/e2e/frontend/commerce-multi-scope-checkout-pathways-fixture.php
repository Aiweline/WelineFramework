<?php

declare(strict_types=1);

/**
 * Multi-scope commerce pathway fixture.
 * - prepare: reuse release-paypal prepare (customer + pdp)
 * - prepare_wholesale: prepare + assign active B2B VIP0 membership (tob gate)
 */

use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * @return array<string, mixed>
 */
function multi_scope_input(): array
{
    $raw = file_get_contents('php://stdin');
    if ($raw === false || trim($raw) === '') {
        throw new InvalidArgumentException('empty stdin');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('stdin must be JSON object');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function multi_scope_output(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function multi_scope_fail(string $message, int $code = 1): never
{
    multi_scope_output(['ok' => false, 'error' => $message]);
    exit($code);
}

/**
 * Delegate to release-paypal prepare for customer minting.
 *
 * @return array<string, mixed>
 */
function multi_scope_prepare_customer(?string $token): array
{
    $fixturePhp = __DIR__ . '/release-paypal-account-lifecycle-fixture.php';
    $payload = json_encode(['action' => 'prepare', 'token' => $token], JSON_UNESCAPED_UNICODE);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(['php', $fixturePhp], $descriptors, $pipes, dirname(__DIR__, 7));
    if (!is_resource($proc)) {
        multi_scope_fail('prepare_spawn_failed');
    }
    fwrite($pipes[0], (string)$payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$stdout))));
    $parsed = json_decode((string)end($lines), true);
    if ($exit !== 0 || !is_array($parsed) || empty($parsed['ok'])) {
        multi_scope_fail('prepare_failed:' . ($parsed['error'] ?? $stderr ?: $stdout));
    }

    return is_array($parsed['fixture'] ?? null) ? $parsed['fixture'] : [];
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function multi_scope_assign_wholesale(array $fixture): array
{
    $customerId = (string)(int)($fixture['customer_id'] ?? 0);
    if ($customerId === '0' || $customerId === '') {
        multi_scope_fail('wholesale_customer_id_missing');
    }

    $groupId = SystemVipLadder::groupId(0);
    $store = ObjectManager::getInstance()->get(CustomerGroupStore::class);
    if (!$store instanceof CustomerGroupStore) {
        $store = new CustomerGroupStore();
    }
    $group = $store->get($groupId);
    if ($group === null) {
        multi_scope_fail('vip0_group_missing:' . $groupId);
    }
    $store->assignCustomer($customerId, $groupId);
    $assigned = $store->groupForCustomer($customerId, (int)$group->websiteId);

    return [
        'group_id' => $groupId,
        'website_id' => (int)$group->websiteId,
        'assigned' => $assigned !== null && $assigned->groupId === $groupId,
        'status' => $assigned?->status,
    ];
}

try {
    $input = multi_scope_input();
    $action = trim((string)($input['action'] ?? ''));
    $token = isset($input['token']) ? (string)$input['token'] : null;

    if ($action === 'prepare') {
        $fixture = multi_scope_prepare_customer($token);
        multi_scope_output(['ok' => true, 'fixture' => $fixture]);
        exit(0);
    }

    if ($action === 'prepare_wholesale') {
        $fixture = multi_scope_prepare_customer($token);
        $membership = multi_scope_assign_wholesale($fixture);
        if (empty($membership['assigned'])) {
            multi_scope_fail('wholesale_assign_failed:' . json_encode($membership));
        }
        $fixture['wholesale'] = $membership;
        multi_scope_output(['ok' => true, 'fixture' => $fixture]);
        exit(0);
    }

    multi_scope_fail('unknown_action:' . $action);
} catch (Throwable $e) {
    multi_scope_fail($e->getMessage());
}
