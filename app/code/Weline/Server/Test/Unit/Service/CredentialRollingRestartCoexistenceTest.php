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
 * 架构回归：滚动重启 / 热重载期间，新一代 worker 授权**不得**删除仍存活的
 * 老一代 worker 的账本记录。
 *
 * 线上事故（changanhanfu，1 秒粒度取证）：
 *   18:33:59 / 18:37:54 热重载（server:reload / server:start -r）
 *   18:39:xx 老一代 worker 仍在正常处理请求
 *   18:40:10 老一代 worker failed: credential 不被认可
 *   18:40:22 期望 4 当前 0（二十余秒整池归零）
 *
 * 根因：authorizeServicesWithIdentity 用 removeServiceSlotAndDescendants 按 slot
 * 整体删除，新一代授权时把老一代记录一并抹掉；老一代 worker 自检按 PID 找记录
 * 变成 0 条 → exit(0)。
 *
 * 修复：授权只删「同 slot 且同 PID」的重复记录，其他 PID 记录保留；校验侧把
 * `count !== 1` 放宽为 `count === 0` 才失败。
 */
final class CredentialRollingRestartCoexistenceTest extends TestCase
{
    /** @var list<string> */
    private array $instances = [];

    private float $now = 30_000.0;

    private string $bootId = '';

    /** @var array<int,bool> */
    private array $processAlive = [];

    /** @var array<int,string> */
    private array $processStart = [];

    protected function setUp(): void
    {
        $this->instances = [];
        $this->now = 30_000.0;
        $this->bootId = \str_repeat('9', 64);
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
     * 核心回归：新一代授权后，老一代（仍存活）记录与其 credential 必须原样保留。
     */
    public function testOldGenerationRecordSurvivesNewGenerationAuthorization(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('roll-survive');
        $slotId = ControlMessage::ROLE_WORKER . '#1';

        $oldPid = 7_100_001;
        $this->markAlive($oldPid, 'old-start');
        $oldOut = $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'old-launch',
            'lease_id' => 'old-lease',
            'generation' => 1,
            'pid' => $oldPid,
        ]]);
        $oldCred = (string)($oldOut[$slotId]['credential_id'] ?? '');
        self::assertNotSame('', $oldCred);

        // 滚动重启：新一代 worker（新 launch / 新 pid），老一代仍存活。
        $newPid = 7_100_002;
        $this->markAlive($newPid, 'new-start');
        $newOut = $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'new-launch',
            'lease_id' => 'new-lease',
            'generation' => 2,
            'pid' => $newPid,
        ]]);
        $newCred = (string)($newOut[$slotId]['credential_id'] ?? '');
        self::assertNotSame('', $newCred);

        $records = $this->records($instance);

        $oldRecords = \array_values(\array_filter(
            $records,
            static fn (array $r): bool => (int)($r['pid'] ?? 0) === $oldPid,
        ));
        self::assertCount(
            1,
            $oldRecords,
            '滚动重启时老一代 worker 的账本记录不得被删除——否则它自检变成 0 条会 exit(0)'
        );
        self::assertSame(
            $oldCred,
            (string)($oldRecords[0]['credential_id'] ?? ''),
            '老一代 credential 必须保持不变（凭据被重写 = 老 worker 立刻失效）'
        );
        self::assertSame('active', (string)($oldRecords[0]['lifecycle_state'] ?? ''));

        $newRecords = \array_values(\array_filter(
            $records,
            static fn (array $r): bool => (int)($r['pid'] ?? 0) === $newPid,
        ));
        self::assertCount(1, $newRecords, '新一代 worker 记录必须写入');
        self::assertSame($newCred, (string)($newRecords[0]['credential_id'] ?? ''));
    }

    /**
     * 同一 PID 用不同 launch 重复授权（PID 复用场景）→ 该 PID 只保留最新一条。
     */
    public function testSamePidReauthorizationKeepsSingleRecord(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('roll-same-pid');
        $slotId = ControlMessage::ROLE_WORKER . '#1';

        $pid = 7_100_101;
        $this->markAlive($pid, 'pid-start');
        $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'launch-1',
            'lease_id' => 'lease-1',
            'generation' => 1,
            'pid' => $pid,
        ]]);
        $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'launch-2',
            'lease_id' => 'lease-2',
            'generation' => 2,
            'pid' => $pid,
        ]]);

        $samePid = \array_values(\array_filter(
            $this->records($instance),
            static fn (array $r): bool => (int)($r['pid'] ?? 0) === $pid,
        ));
        self::assertCount(
            1,
            $samePid,
            '同一 PID 重复授权必须只保留一条（否则校验侧会看到同 PID 多条）'
        );
        self::assertSame(
            'launch-2',
            (string)($samePid[0]['launch_id'] ?? ''),
            '同 PID 重复授权应保留最新 launch'
        );
    }

    /**
     * 同一 launch tuple 重复授权 → 沿用既有 credential_id（I2 幂等，不轮换 nonce）。
     */
    public function testSameLaunchTupleIsIdempotent(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('roll-idem');
        $slotId = ControlMessage::ROLE_WORKER . '#1';

        $pid = 7_100_201;
        $this->markAlive($pid, 'idem-start');
        $child = [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'idem-launch',
            'lease_id' => 'idem-lease',
            'generation' => 1,
            'pid' => $pid,
        ]];
        $first = $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, $child);
        $firstId = (string)($first[$slotId]['credential_id'] ?? '');
        $second = $store->authorizeServicesWithIdentity($lease, $instance, $masterPid, 3, $token, $child);
        $secondId = (string)($second[$slotId]['credential_id'] ?? '');

        self::assertSame($firstId, $secondId, '相同 launch tuple 必须复用 credential，不重建 nonce');
        self::assertCount(1, $this->records($instance));
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
        $this->markAlive($masterPid, 'master-start');

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
