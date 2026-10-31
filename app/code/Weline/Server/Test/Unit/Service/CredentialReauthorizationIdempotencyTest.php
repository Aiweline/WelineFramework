<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterChildCredentialStore;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * 根治回归：credential 重新授权必须幂等，且不得替换仍由活进程持有的 slot。
 *
 * 线上事故机制（changanhanfu，1 秒粒度取证）：
 *   00:09:51 补位成功，4 个 worker READY
 *   00:09:54 账本里 4 条 worker 记录全部消失（进程仍存活）
 *   00:09:56 4 个 worker 全部 exit(0)
 *
 * 两条独立成因，本测试各钉一条：
 *  - I2：相同 launch tuple 重复授权会重建 nonce，使运行中的子进程持有的
 *        credential 立刻失效 → 下次自检 `not uniquely authorized` → 自杀。
 *  - I3：同 slot 已有 active 记录且旧进程仍 OWNER_MATCH 时仍被替换，等于
 *        删掉一个正在服务的 worker 的凭据。
 */
final class CredentialReauthorizationIdempotencyTest extends TestCase
{
    /** @var list<string> */
    private array $instances = [];

    private float $now = 20_000.0;

    private string $bootId = '';

    /** @var array<int,bool> */
    private array $processAlive = [];

    /** @var array<int,string> */
    private array $processStart = [];

    protected function setUp(): void
    {
        $this->instances = [];
        $this->now = 20_000.0;
        $this->bootId = \str_repeat('7', 64);
        $this->processAlive = [];
        $this->processStart = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->instances as $instance) {
            $lease = MasterLeaseManager::pathForInstance($instance);
            @\unlink(MasterChildCredentialStore::pathForInstance($instance));
            @\unlink(MasterChildCredentialStore::lockPathForInstance($instance));
            @\unlink($lease);
            @\unlink(MasterLeaseManager::lockPathForInstance($instance));
            @\rmdir(\dirname($lease));
        }
    }

    /**
     * I2：同一 launch tuple 重复授权必须返回同一个 credential_id（不轮换 nonce）。
     */
    public function testDuplicateAuthorizationOfSameLaunchKeepsCredentialStable(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('idem-same-tuple');
        $pid = 6_200_001;
        $this->markAlive($pid, 'worker-a-start');

        $child = [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#1',
            'launch_id' => 'idem-launch',
            'lease_id' => 'idem-lease',
            'generation' => 1,
            'pid' => $pid,
        ]];

        $first = $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, $child);
        $firstId = (string)($first[ControlMessage::ROLE_WORKER . '#1']['credential_id'] ?? '');

        // 完全相同的 tuple 再来一次（模拟批量路径 + 单实例路径重复授权）。
        $second = $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, $child);
        $secondId = (string)($second[ControlMessage::ROLE_WORKER . '#1']['credential_id'] ?? '');

        self::assertNotSame('', $firstId);
        self::assertSame(
            $firstId,
            $secondId,
            '相同 launch tuple 重复授权必须复用既有 credential_id，'
            . '轮换 nonce 会让运行中子进程持有的旧 credential 立刻失效并自杀'
        );
        self::assertCount(
            1,
            $this->records($instance),
            '幂等重授权不得在账本里产生第二条记录'
        );
    }

    /**
     * I3：同 slot 旧记录对应的进程仍存活时，必须拒绝为新 PID 替换（保留旧记录）。
     */
    public function testReplacementRefusedWhileOldProcessOwnsSlot(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('idem-live-owner');
        $slotId = ControlMessage::ROLE_WORKER . '#1';

        $oldPid = 6_200_101;
        $this->markAlive($oldPid, 'old-worker-start');
        $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'old-launch',
            'lease_id' => 'old-lease',
            'generation' => 1,
            'pid' => $oldPid,
        ]]);
        self::assertSame($oldPid, $this->recordPid($instance, $slotId));

        // 旧进程仍活着，Master 误以为它死了并试图补位到新 PID。
        $newPid = 6_200_102;
        $this->markAlive($newPid, 'new-worker-start');
        $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'new-launch',
            'lease_id' => 'new-lease',
            'generation' => 2,
            'pid' => $newPid,
        ]]);

        self::assertSame(
            $oldPid,
            $this->recordPid($instance, $slotId),
            '旧进程仍 OWNER_MATCH 时不得替换 slot 凭据——否则正在服务的 worker 会因凭据被删而自杀'
        );
    }

    /**
     * I3 反向：旧进程确认已退出后，必须允许替换（不能让死 slot 永久占位）。
     */
    public function testReplacementAllowedAfterOldProcessVerifiedGone(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('idem-dead-owner');
        $slotId = ControlMessage::ROLE_WORKER . '#1';

        $oldPid = 6_200_201;
        $this->markAlive($oldPid, 'old-worker-start');
        $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'old-launch',
            'lease_id' => 'old-lease',
            'generation' => 1,
            'pid' => $oldPid,
        ]]);

        // 旧进程真正退出。
        $this->processAlive[$oldPid] = false;
        // 越过 prune 最小年龄，避免被「刚授权保护」影响判定（这里考察 I3）。
        // 同时刷新一次 Master lease heartbeat，否则推进时钟会让 lease 判定 stale。
        $this->now += 60.0;
        $lease = $this->rewriteLease($lease, $instance, $masterPid, $token);

        $newPid = 6_200_202;
        $this->markAlive($newPid, 'new-worker-start');
        $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'new-launch',
            'lease_id' => 'new-lease',
            'generation' => 2,
            'pid' => $newPid,
        ]]);

        self::assertSame(
            $newPid,
            $this->recordPid($instance, $slotId),
            '旧进程确认退出后必须允许替换，否则死 slot 会永久占用凭据'
        );
    }

    private function rewriteLease(string $leaseFile, string $instance, int $masterPid, string $token): string
    {
        // 用同一 identity 重新发布 running lease，把 heartbeat 推到当前 $now。
        $identity = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: fn (): string => $this->bootId,
            monotonicClock: fn (): float => $this->now,
            processInfoResolver: function (int $pid): array {
                $alive = $this->processAlive[$pid] ?? false;
                return [
                    'exists' => $alive,
                    'name' => $alive ? 'php' : '',
                    'command' => $alive ? 'php managed-child-' . $pid : '',
                    'start_time' => $alive ? ($this->processStart[$pid] ?? '') : '',
                ];
            },
            managedProcessVerifier: fn (int $pid, string $name): bool => $this->processAlive[$pid] ?? false,
            pidNamespaceResolver: fn (int $pid): ?string => ($this->processAlive[$pid] ?? false)
                ? 'pid:[4026531999]'
                : null,
        );

        return (new MasterLeaseManager($identity))->writeRunning($instance, $masterPid, 19221, 3, $token);
    }

    private function markAlive(int $pid, string $start): void
    {
        $this->processAlive[$pid] = true;
        $this->processStart[$pid] = $start;
    }

    /** @return array{0:string,1:string,2:MasterChildCredentialStore,3:string,4:int} */
    private function fixture(string $prefix): array
    {
        $instance = $prefix . '-' . \bin2hex(\random_bytes(5));
        $this->instances[] = $instance;
        $masterPid = (int)\getmypid();
        $this->markAlive($masterPid, 'master-process-start');

        $identity = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: fn (): string => $this->bootId,
            monotonicClock: fn (): float => $this->now,
            processInfoResolver: function (int $pid): array {
                $alive = $this->processAlive[$pid] ?? false;
                return [
                    'exists' => $alive,
                    'name' => $alive ? 'php' : '',
                    'command' => $alive ? 'php managed-child-' . $pid : '',
                    'start_time' => $alive ? ($this->processStart[$pid] ?? '') : '',
                ];
            },
            managedProcessVerifier: fn (int $pid, string $name): bool => $this->processAlive[$pid] ?? false,
            pidNamespaceResolver: fn (int $pid): ?string => ($this->processAlive[$pid] ?? false)
                ? 'pid:[4026531999]'
                : null,
        );

        $manager = new MasterLeaseManager($identity);
        $store = new MasterChildCredentialStore($manager, $identity);
        $token = \hash('sha256', $instance . '-root-token');
        $lease = $manager->writeRunning($instance, $masterPid, 19221, 3, $token);

        return [$instance, $token, $store, $lease, $masterPid];
    }

    private function recordPid(string $instance, string $slotId): int
    {
        foreach ($this->records($instance) as $record) {
            if (($record['slot_id'] ?? '') === $slotId) {
                return (int)($record['pid'] ?? 0);
            }
        }

        return 0;
    }

    /** @return list<array<string,mixed>> */
    private function records(string $instance): array
    {
        $raw = @\file_get_contents(MasterChildCredentialStore::pathForInstance($instance));
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }
        $records = $decoded['records'] ?? [];

        return \is_array($records) ? \array_values($records) : [];
    }
}
