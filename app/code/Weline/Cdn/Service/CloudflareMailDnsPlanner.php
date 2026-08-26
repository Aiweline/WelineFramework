<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

/**
 * Pure, deterministic planner for records owned by one mail domain.
 */
final class CloudflareMailDnsPlanner
{
    /**
     * @param array<int, array<string, mixed>> $existingRecords
     * @param array<int, array<string, mixed>> $desiredRecords
     * @param array<int, string> $dnsOnlyHosts
     * @return array<string, mixed>
     */
    public function buildPlan(
        string $domain,
        array $existingRecords,
        array $desiredRecords,
        array $dnsOnlyHosts,
    ): array {
        $domain = $this->name($domain);
        if ($domain === '') {
            throw new \InvalidArgumentException((string)__('邮箱域名不能为空。'));
        }

        $existing = array_map(fn(array $record): array => $this->normalize($record), $existingRecords);
        $desired = array_map(fn(array $record): array => $this->normalize($record, true), $desiredRecords);
        usort($existing, fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

        $operations = [];
        foreach (array_values(array_unique(array_map([$this, 'name'], $dnsOnlyHosts))) as $host) {
            if ($host === '' || !($host === $domain || str_ends_with($host, '.' . $domain))) {
                throw new \DomainException((string)__('DNS-only 主机名不属于当前邮箱域名。'));
            }
            $this->reconcileAddressHost($host, $existing, $desired, $operations);
        }

        $specifications = [
            [
                'label' => 'MX',
                'matches' => static fn(array $record): bool =>
                    $record['type'] === 'MX' && $record['name'] === $domain,
            ],
            [
                'label' => 'SPF',
                'matches' => static fn(array $record): bool =>
                    $record['type'] === 'TXT'
                    && $record['name'] === $domain
                    && str_starts_with(strtolower($record['content']), 'v=spf1'),
            ],
            [
                'label' => 'DKIM',
                'matches' => static fn(array $record): bool =>
                    $record['type'] === 'TXT'
                    && str_ends_with($record['name'], '._domainkey.' . $domain)
                    && str_starts_with(strtolower($record['content']), 'v=dkim1'),
            ],
            [
                'label' => 'DMARC',
                'matches' => static fn(array $record): bool =>
                    $record['type'] === 'TXT'
                    && $record['name'] === '_dmarc.' . $domain
                    && str_starts_with(strtolower($record['content']), 'v=dmarc1'),
            ],
        ];

        foreach ($specifications as $specification) {
            $matches = $specification['matches'];
            $wanted = array_values(array_filter($desired, $matches));
            if (count($wanted) !== 1) {
                throw new \DomainException(
                    (string)__('邮箱 DNS 计划必须包含且仅包含一条 %{1} 记录。', $specification['label'])
                );
            }

            $current = array_values(array_filter($existing, $matches));
            if ($specification['label'] === 'DKIM') {
                $wantedName = $wanted[0]['name'];
                $current = array_values(array_filter(
                    $current,
                    static fn(array $record): bool => $record['name'] === $wantedName,
                ));
            }
            $this->reconcileSingleton(
                (string)$specification['label'],
                $current,
                $wanted[0],
                $operations,
            );
        }

        return [
            'domain' => $domain,
            'operation_count' => count($operations),
            'changed' => $operations !== [],
            'operations' => $operations,
            'warnings' => [
                (string)__('邮件 A/AAAA 必须保持 DNS-only；Cloudflare 代理不支持 SMTP、IMAP 或 POP。'),
                (string)__('PTR/rDNS 必须在服务器或云厂商控制台配置，Cloudflare DNS 不能代配。'),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $desired
     * @param array<int, array<string, mixed>> $operations
     */
    private function reconcileAddressHost(
        string $host,
        array $existing,
        array $desired,
        array &$operations,
    ): void {
        $currentAddresses = array_values(array_filter(
            $existing,
            static fn(array $record): bool =>
                in_array($record['type'], ['A', 'AAAA'], true) && $record['name'] === $host,
        ));
        $wantedAddresses = array_values(array_filter(
            $desired,
            static fn(array $record): bool =>
                in_array($record['type'], ['A', 'AAAA'], true) && $record['name'] === $host,
        ));
        $cnameExists = array_filter(
            $existing,
            static fn(array $record): bool => $record['type'] === 'CNAME' && $record['name'] === $host,
        ) !== [];

        if ($currentAddresses === [] && $wantedAddresses === []) {
            if ($cnameExists) {
                throw new \DomainException(
                    (string)__('邮件主机 %{1} 当前是 CNAME；请先人工确认后改为 A/AAAA。', $host)
                );
            }
            throw new \DomainException(
                (string)__('邮件主机 %{1} 没有 A/AAAA；请输入公网源站 IP 后再预览。', $host)
            );
        }
        if ($cnameExists && $wantedAddresses !== []) {
            throw new \DomainException(
                (string)__('邮件主机 %{1} 的 CNAME 与 A/AAAA 冲突，自动配置已停止。', $host)
            );
        }

        usort(
            $currentAddresses,
            static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']),
        );

        if ($wantedAddresses === []) {
            foreach ($currentAddresses as $record) {
                if (($record['proxied'] ?? false) !== true) {
                    continue;
                }
                $replacement = $this->payload($record);
                $replacement['proxied'] = false;
                $this->appendOperation($operations, [
                    'action' => 'update',
                    'record_id' => $record['id'],
                    'before' => $this->payload($record),
                    'record' => $replacement,
                ]);
            }
            return;
        }

        $uniqueWanted = [];
        foreach ($wantedAddresses as $wanted) {
            $key = $wanted['type'] . "\0" . $wanted['content'];
            $uniqueWanted[$key] = $wanted;
        }
        $wantedAddresses = array_values($uniqueWanted);
        usort(
            $wantedAddresses,
            static fn(array $a, array $b): int =>
                [$a['type'], $a['content']] <=> [$b['type'], $b['content']],
        );

        $usedCurrent = [];
        foreach ($wantedAddresses as $wanted) {
            $wanted['proxied'] = false;
            $matchIndex = null;

            foreach ($currentAddresses as $index => $current) {
                if (
                    isset($usedCurrent[$index])
                    || $current['type'] !== $wanted['type']
                    || $current['content'] !== $wanted['content']
                ) {
                    continue;
                }
                $matchIndex = $index;
                break;
            }
            if ($matchIndex === null) {
                foreach ($currentAddresses as $index => $current) {
                    if (isset($usedCurrent[$index]) || $current['type'] !== $wanted['type']) {
                        continue;
                    }
                    $matchIndex = $index;
                    break;
                }
            }

            if ($matchIndex === null) {
                $this->appendOperation($operations, [
                    'action' => 'create',
                    'record' => $this->payload($wanted),
                ]);
                continue;
            }

            $usedCurrent[$matchIndex] = true;
            $current = $currentAddresses[$matchIndex];
            if (!$this->equivalent($current, $wanted)) {
                $this->appendOperation($operations, [
                    'action' => 'update',
                    'record_id' => $current['id'],
                    'before' => $this->payload($current),
                    'record' => $this->payload($wanted),
                ]);
            }
        }

        foreach ($currentAddresses as $index => $record) {
            if (isset($usedCurrent[$index])) {
                continue;
            }
            $this->appendOperation($operations, [
                'action' => 'delete',
                'record_id' => $record['id'],
                'before' => $this->payload($record),
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $operations
     */
    private function reconcileSingleton(
        string $label,
        array $current,
        array $wanted,
        array &$operations,
    ): void {
        usort($current, fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
        $keeper = null;
        foreach ($current as $record) {
            if ($this->equivalent($record, $wanted)) {
                $keeper = $record;
                break;
            }
        }
        $keeper ??= $current[0] ?? null;

        if ($keeper === null) {
            $this->appendOperation($operations, [
                'action' => 'create',
                'record' => $this->payload($wanted),
                'category' => $label,
            ]);
            return;
        }

        if (!$this->equivalent($keeper, $wanted)) {
            $this->appendOperation($operations, [
                'action' => 'update',
                'record_id' => $keeper['id'],
                'before' => $this->payload($keeper),
                'record' => $this->payload($wanted),
                'category' => $label,
            ]);
        }

        foreach ($current as $record) {
            if ($record['id'] === $keeper['id']) {
                continue;
            }
            $this->appendOperation($operations, [
                'action' => 'delete',
                'record_id' => $record['id'],
                'before' => $this->payload($record),
                'category' => $label,
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @param array<string, mixed> $operation
     */
    private function appendOperation(array &$operations, array $operation): void
    {
        if (
            in_array($operation['action'], ['update', 'delete'], true)
            && (($operation['before']['locked'] ?? false) === true)
        ) {
            $before = $operation['before'];
            throw new \DomainException(
                (string)__(
                    'Cloudflare Email Routing 已锁定 %{1} %{2}；请先在 Cloudflare 中解除托管，再执行一键配置。',
                    (string)($before['type'] ?? ''),
                    (string)($before['name'] ?? ''),
                )
            );
        }
        $operations[] = $operation;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalize(array $record, bool $desired = false): array
    {
        $type = strtoupper(trim((string)($record['type'] ?? '')));
        $name = $this->name((string)($record['name'] ?? ''));
        $content = trim((string)($record['content'] ?? ''));
        if ($type === 'MX') {
            $content = $this->name($content);
        }
        if ($desired && !in_array($type, ['A', 'AAAA', 'MX', 'TXT'], true)) {
            throw new \DomainException((string)__('邮箱 DNS 计划包含不支持的记录类型：%{1}', $type));
        }
        if ($type === '' || $name === '' || $content === '') {
            throw new \DomainException((string)__('邮箱 DNS 记录缺少 type、name 或 content。'));
        }

        $normalized = [
            'id' => (string)($record['id'] ?? ''),
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => max(1, (int)($record['ttl'] ?? 1)),
            'locked' => (bool)($record['locked'] ?? false),
        ];
        if ($type === 'MX') {
            $normalized['priority'] = (int)($record['priority'] ?? 10);
        }
        if (in_array($type, ['A', 'AAAA'], true)) {
            $normalized['proxied'] = (bool)($record['proxied'] ?? false);
        }
        if (array_key_exists('comment', $record)) {
            $normalized['comment'] = (string)$record['comment'];
        }
        if (isset($record['tags']) && is_array($record['tags'])) {
            $normalized['tags'] = array_values($record['tags']);
        }
        if (isset($record['settings']) && is_array($record['settings'])) {
            $normalized['settings'] = $record['settings'];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function payload(array $record): array
    {
        $payload = [
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'ttl' => $record['ttl'] ?? 1,
            'locked' => (bool)($record['locked'] ?? false),
        ];
        foreach (['priority', 'proxied', 'comment', 'tags', 'settings'] as $key) {
            if (array_key_exists($key, $record)) {
                $payload[$key] = $record[$key];
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $wanted
     */
    private function equivalent(array $current, array $wanted): bool
    {
        foreach (['type', 'name', 'content', 'ttl'] as $key) {
            if (($current[$key] ?? null) !== ($wanted[$key] ?? null)) {
                return false;
            }
        }
        if ($wanted['type'] === 'MX' && ($current['priority'] ?? null) !== ($wanted['priority'] ?? null)) {
            return false;
        }
        if (
            in_array($wanted['type'], ['A', 'AAAA'], true)
            && ($current['proxied'] ?? false) !== ($wanted['proxied'] ?? false)
        ) {
            return false;
        }

        return true;
    }

    private function name(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }
}
