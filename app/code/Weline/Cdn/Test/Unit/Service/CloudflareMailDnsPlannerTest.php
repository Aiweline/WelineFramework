<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\CloudflareMailDnsPlanner;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class CloudflareMailDnsPlannerTest extends TestCase
{
    public function testPlanIsScopedDnsOnlyAndIdempotent(): void
    {
        $planner = new CloudflareMailDnsPlanner();
        $existing = [
            ['id' => '1', 'type' => 'A', 'name' => 'mail.example.com', 'content' => '203.0.113.10', 'ttl' => 1, 'proxied' => true],
            ['id' => '2', 'type' => 'CNAME', 'name' => 'smtp.example.com', 'content' => 'mail.example.com', 'ttl' => 1, 'proxied' => false],
            ['id' => '3', 'type' => 'MX', 'name' => 'example.com', 'content' => 'old.example.com', 'priority' => 20, 'ttl' => 1],
            ['id' => '4', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 ~all', 'ttl' => 1],
            ['id' => '5', 'type' => 'TXT', 'name' => 'default._domainkey.example.com', 'content' => 'v=DKIM1; k=rsa; p=' . str_repeat('A', 64), 'ttl' => 1],
            ['id' => '6', 'type' => 'TXT', 'name' => '_dmarc.example.com', 'content' => 'v=DMARC1; p=none', 'ttl' => 1],
            ['id' => '7', 'type' => 'TXT', 'name' => '_dmarc.example.com', 'content' => 'v=DMARC1; p=reject', 'ttl' => 1],
            ['id' => '8', 'type' => 'TXT', 'name' => 'unrelated.example.com', 'content' => 'keep-me', 'ttl' => 1],
        ];
        $desired = $this->desired();

        $first = $planner->buildPlan('example.com', $existing, $desired, ['mail.example.com']);
        self::assertGreaterThan(0, $first['operation_count']);

        $after = $this->applyPlan($existing, $first['operations']);
        $second = $planner->buildPlan('example.com', $after, $desired, ['mail.example.com']);

        self::assertSame(0, $second['operation_count']);
        self::assertNotNull($this->find($after, 'CNAME', 'smtp.example.com'));
        self::assertNotNull($this->find($after, 'TXT', 'unrelated.example.com'));
        self::assertFalse((bool)$this->find($after, 'A', 'mail.example.com')['proxied']);
        self::assertCount(1, array_filter(
            $after,
            static fn(array $record): bool =>
                $record['type'] === 'TXT' && $record['name'] === '_dmarc.example.com',
        ));
    }

    public function testReplacingOriginIpDoesNotLeaveTheOldMailAddress(): void
    {
        $planner = new CloudflareMailDnsPlanner();
        $existing = $this->desired();
        foreach ($existing as $index => &$record) {
            $record['id'] = (string)($index + 1);
        }
        unset($record);
        $existing[4]['content'] = '198.51.100.25';

        $plan = $planner->buildPlan(
            'example.com',
            $existing,
            $this->desired(),
            ['mail.example.com'],
        );
        $after = $this->applyPlan($existing, $plan['operations']);
        $mailAddresses = array_values(array_filter(
            $after,
            static fn(array $record): bool =>
                $record['type'] === 'A' && $record['name'] === 'mail.example.com',
        ));

        self::assertCount(1, $mailAddresses);
        self::assertSame('203.0.113.10', $mailAddresses[0]['content']);
        self::assertFalse((bool)$mailAddresses[0]['proxied']);
    }

    public function testLockedEmailRoutingRecordStopsBeforeWriting(): void
    {
        $planner = new CloudflareMailDnsPlanner();
        $existing = $this->desired();
        foreach ($existing as $index => &$record) {
            $record['id'] = (string)($index + 1);
        }
        unset($record);
        $existing[0]['content'] = 'routing.mx.cloudflare.net';
        $existing[0]['locked'] = true;

        $this->expectException(\DomainException::class);
        $planner->buildPlan('example.com', $existing, $this->desired(), ['mail.example.com']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function desired(): array
    {
        return [
            ['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com', 'priority' => 10, 'ttl' => 1],
            ['type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 mx -all', 'ttl' => 1],
            ['type' => 'TXT', 'name' => 'default._domainkey.example.com', 'content' => 'v=DKIM1; k=rsa; p=' . str_repeat('B', 64), 'ttl' => 1],
            ['type' => 'TXT', 'name' => '_dmarc.example.com', 'content' => 'v=DMARC1; p=quarantine', 'ttl' => 1],
            ['type' => 'A', 'name' => 'mail.example.com', 'content' => '203.0.113.10', 'ttl' => 1, 'proxied' => false],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array<string, mixed>> $operations
     * @return array<int, array<string, mixed>>
     */
    private function applyPlan(array $records, array $operations): array
    {
        $next = 100;
        foreach ($operations as $operation) {
            if ($operation['action'] === 'create') {
                $record = $operation['record'];
                $record['id'] = (string)$next++;
                $records[] = $record;
            } elseif ($operation['action'] === 'update') {
                foreach ($records as &$record) {
                    if ((string)($record['id'] ?? '') === (string)$operation['record_id']) {
                        $record = array_merge($record, $operation['record']);
                    }
                }
                unset($record);
            } elseif ($operation['action'] === 'delete') {
                $records = array_values(array_filter(
                    $records,
                    static fn(array $record): bool =>
                        (string)($record['id'] ?? '') !== (string)$operation['record_id'],
                ));
            }
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function find(array $records, string $type, string $name): ?array
    {
        foreach ($records as $record) {
            if (($record['type'] ?? '') === $type && ($record['name'] ?? '') === $name) {
                return $record;
            }
        }

        return null;
    }
}
