<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\MailDnsManagerInterface;
use Weline\Cdn\Model\Account;

/**
 * Cloudflare implementation of the mail-DNS command boundary.
 */
final class CloudflareMailDnsManager implements MailDnsManagerInterface
{
    public function __construct(
        private readonly AccountManager $accountManager,
        private readonly CloudflareOAuthService $oauth,
        private readonly CloudflareHttpClient $http,
        private readonly CloudflareMailDnsPlanner $planner,
    ) {
    }

    public function reconcile(
        string $domain,
        array $desiredRecords,
        array $dnsOnlyHosts,
        bool $apply = false,
    ): array {
        $account = $this->accountManager->getDefaultAccount('cloudflare');
        if (!$account instanceof Account) {
            throw new \DomainException((string)__('请先连接 Cloudflare 账户。'));
        }

        $credentials = $this->oauth->credentialsForAccount($account);
        $token = trim((string)($credentials['api_token'] ?? ''));
        if ($token === '') {
            throw new \DomainException((string)__('Cloudflare 账户未配置可用令牌。'));
        }

        $zoneId = $this->resolveZoneId($token, $domain);
        $existing = $this->listRecords($token, $zoneId);
        $internalPlan = $this->planner->buildPlan($domain, $existing, $desiredRecords, $dnsOnlyHosts);
        $publicPlan = $this->publicPlan($internalPlan);

        if (!$apply) {
            return array_merge($publicPlan, [
                'success' => true,
                'status' => 'preview',
                'message' => (string)__(
                    'Cloudflare DNS 预览完成：%{1} 项变更。',
                    (int)$publicPlan['operation_count'],
                ),
                'residual_changes' => [],
            ]);
        }

        if ((int)$internalPlan['operation_count'] === 0) {
            return array_merge($publicPlan, [
                'success' => true,
                'status' => 'unchanged',
                'message' => (string)__('Cloudflare DNS 无需变更。'),
                'applied_count' => 0,
                'verification_operation_count' => 0,
                'residual_changes' => [],
            ]);
        }

        $completed = [];
        $currentOperation = null;
        try {
            foreach ($internalPlan['operations'] as $operation) {
                $currentOperation = $operation;
                $completed[] = [
                    'operation' => $operation,
                    'result' => $this->execute($token, $zoneId, $operation),
                ];
                $currentOperation = null;
            }

            $verifiedRecords = $this->listRecords($token, $zoneId);
            $verification = $this->planner->buildPlan(
                $domain,
                $verifiedRecords,
                $desiredRecords,
                $dnsOnlyHosts,
            );
            if ((int)$verification['operation_count'] !== 0) {
                throw new \RuntimeException((string)__('Cloudflare DNS 写入后校验未收敛。'));
            }
        } catch (\Throwable) {
            $residual = $this->rollback($token, $zoneId, $completed);
            if (is_array($currentOperation)) {
                $record = (array)($currentOperation['record'] ?? $currentOperation['before'] ?? []);
                array_unshift($residual, [
                    'action' => (string)($currentOperation['action'] ?? ''),
                    'type' => (string)($record['type'] ?? ''),
                    'name' => (string)($record['name'] ?? ''),
                    'state' => 'unknown',
                ]);
            }

            return array_merge($publicPlan, [
                'success' => false,
                'status' => $residual === [] ? 'rolled_back' : 'rollback_incomplete',
                'message' => (string)__('Cloudflare DNS 写入失败，已尝试回滚。'),
                'applied_count' => count($completed),
                'verification_operation_count' => null,
                'residual_changes' => $residual,
            ]);
        }

        return array_merge($publicPlan, [
            'success' => true,
            'status' => 'applied',
            'message' => (string)__('Cloudflare DNS 已同步。'),
            'applied_count' => count($completed),
            'verification_operation_count' => 0,
            'residual_changes' => [],
        ]);
    }

    private function resolveZoneId(string $token, string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $response = $this->http->api($token, 'GET', '/zones', [
            'name' => $domain,
            'status' => 'active',
            'per_page' => 50,
        ]);

        $matches = array_values(array_filter(
            is_array($response['result'] ?? null) ? $response['result'] : [],
            static fn(mixed $zone): bool =>
                is_array($zone)
                && strtolower(rtrim((string)($zone['name'] ?? ''), '.')) === $domain,
        ));
        if (count($matches) !== 1 || trim((string)($matches[0]['id'] ?? '')) === '') {
            throw new \DomainException(
                (string)__('Cloudflare 账户中找不到唯一的活动域名 %{1}。', $domain)
            );
        }

        return (string)$matches[0]['id'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listRecords(string $token, string $zoneId): array
    {
        $records = [];
        $page = 1;
        do {
            $response = $this->http->api(
                $token,
                'GET',
                '/zones/' . rawurlencode($zoneId) . '/dns_records',
                ['page' => $page, 'per_page' => 5000],
            );
            $result = $response['result'] ?? [];
            if (!is_array($result)) {
                throw new \RuntimeException((string)__('Cloudflare DNS 列表响应无效。'));
            }
            foreach ($result as $record) {
                if (is_array($record)) {
                    $records[] = $record;
                }
            }
            $pages = max(1, (int)($response['result_info']['total_pages'] ?? 1));
            $page++;
            if ($page > 50) {
                throw new \RuntimeException((string)__('Cloudflare DNS 记录分页超过安全上限。'));
            }
        } while ($page <= $pages);

        return $records;
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function execute(string $token, string $zoneId, array $operation): array
    {
        $base = '/zones/' . rawurlencode($zoneId) . '/dns_records';
        $record = $this->apiPayload((array)($operation['record'] ?? []));

        return match ((string)$operation['action']) {
            'create' => $this->http->api($token, 'POST', $base, [], $record),
            'update' => $this->http->api(
                $token,
                'PATCH',
                $base . '/' . rawurlencode((string)$operation['record_id']),
                [],
                $record,
            ),
            'delete' => $this->http->api(
                $token,
                'DELETE',
                $base . '/' . rawurlencode((string)$operation['record_id']),
            ),
            default => throw new \LogicException('Unknown mail DNS operation.'),
        };
    }

    /**
     * @param array<int, array{operation: array<string, mixed>, result: array<string, mixed>}> $completed
     * @return array<int, array<string, string>>
     */
    private function rollback(string $token, string $zoneId, array $completed): array
    {
        $residual = [];
        $base = '/zones/' . rawurlencode($zoneId) . '/dns_records';

        foreach (array_reverse($completed) as $entry) {
            $operation = $entry['operation'];
            try {
                if ($operation['action'] === 'create') {
                    $createdId = (string)($entry['result']['result']['id'] ?? '');
                    if ($createdId === '') {
                        throw new \RuntimeException('Missing created record id.');
                    }
                    $this->http->api($token, 'DELETE', $base . '/' . rawurlencode($createdId));
                } elseif ($operation['action'] === 'update') {
                    $this->http->api(
                        $token,
                        'PATCH',
                        $base . '/' . rawurlencode((string)$operation['record_id']),
                        [],
                        $this->apiPayload((array)$operation['before']),
                    );
                } elseif ($operation['action'] === 'delete') {
                    $this->http->api(
                        $token,
                        'POST',
                        $base,
                        [],
                        $this->apiPayload((array)$operation['before']),
                    );
                }
            } catch (\Throwable) {
                $record = (array)($operation['record'] ?? $operation['before'] ?? []);
                $residual[] = [
                    'action' => (string)$operation['action'],
                    'type' => (string)($record['type'] ?? ''),
                    'name' => (string)($record['name'] ?? ''),
                    'state' => 'not_rolled_back',
                ];
            }
        }

        return $residual;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function apiPayload(array $record): array
    {
        unset($record['locked']);
        $allowed = ['type', 'name', 'content', 'ttl', 'priority', 'proxied', 'comment', 'tags', 'settings'];

        return array_intersect_key($record, array_flip($allowed));
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function publicPlan(array $plan): array
    {
        $public = $plan;
        $public['operations'] = array_map(
            function (array $operation): array {
                foreach (['record', 'before'] as $side) {
                    if (!isset($operation[$side]) || !is_array($operation[$side])) {
                        continue;
                    }
                    $operation[$side] = array_intersect_key(
                        $operation[$side],
                        array_flip(['type', 'name', 'content', 'ttl', 'priority', 'proxied', 'locked']),
                    );
                }
                return $operation;
            },
            (array)($plan['operations'] ?? []),
        );

        return $public;
    }
}
