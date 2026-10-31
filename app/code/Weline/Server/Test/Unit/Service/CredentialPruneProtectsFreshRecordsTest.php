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
 * 回归：刚授权的 credential 记录不得在下一轮 prune 中被误删。
 *
 * 线上事故形态（changanhanfu，已取证）：
 *   00:09:49 worker 4→2（两个因内存回收退役）
 *   00:09:51 补位成功，4 个 worker 就绪
 *   00:09:54 账本里 4 条 worker 记录全部消失（进程仍存活）
 *   00:09:56 4 个 worker 全部退出
 *
 * 机制：补位调用 authorizeServicesWithIdentity 时会先 collectPrunableCredentialIds
 * 清理账本；刚拉起的新 worker 仍在 exec/bootstrap 窗口，observeProcessIdentity()
 * 返回 OWNER_MISSING，于是**健康 worker 的凭据被当作死进程记录删除**，
 * worker 下次自检读到 `not uniquely authorized for this PID` 并 exit(0)。
 *
 * 修复：PRUNE_MIN_RECORD_AGE_SEC 内的记录不参与 prune（只延后清理，不削弱孤儿检测）。
 */
final class CredentialPruneProtectsFreshRecordsTest extends TestCase
{
    /** @var list<string> */
    private array $instances = [];

    private float $now = 10_000.0;

    private string $bootId = '';

    /** @var array<int,bool> 进程存活表；测试显式控制，模拟“刚启动观测不到” */
    private array $processAlive = [];

    /** @var array<int,string> */
    private array $processStart = [];

    protected function setUp(): void
    {
        $this->instances = [];
        $this->now = 10_000.0;
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
     * 核心回归：刚授权（age=0）的记录，即使进程暂时观测不到，也不能被 prune 掉。
     */
    public function testFreshlyAuthorizedRecordSurvivesPruneEvenWhenUnobservable(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('prune-fresh');
        $slotId = ControlMessage::ROLE_WORKER . '#1';
        $pid = 5_100_001;
        // 授权时必须能捕获进程身份，故先标记存活。
        $this->processAlive[$pid] = true;
        $this->processStart[$pid] = 'fresh-worker-start';

        $child = [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => $slotId,
            'launch_id' => 'fresh-launch',
            'lease_id' => 'fresh-lease',
            'generation' => 1,
            'pid' => $pid,
        ]];
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, $child);

        $before = $this->records($instance);
        self::assertCount(1, $before, '首次授权应写入 1 条记录');

        // 模拟 exec/bootstrap 窗口：进程仍活着，但瞬时观测不到（返回 MISSING）。
        $this->processAlive[$pid] = false;

        // 紧接着再来一次授权（模拟补位风暴中另一个 slot 的授权），
        // 该轮会跑 collectPrunableCredentialIds。
        $this->now += 1.0; // 仅 1 秒后，仍远小于 PRUNE_MIN_RECORD_AGE_SEC
        $otherPid = 5_100_002;
        $this->processAlive[$otherPid] = true;
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#2',
            'launch_id' => 'other-launch',
            'lease_id' => 'other-lease',
            'generation' => 1,
            'pid' => $otherPid,
        ]]);

        $after = $this->records($instance);
        $slotIds = \array_map(static fn (array $r): string => (string)($r['slot_id'] ?? ''), $after);
        self::assertContains(
            $slotId,
            $slotIds,
            '刚授权 worker 的记录不得被 prune 删除，否则该 worker 会因凭据丢失自杀'
        );
        self::assertContains(ControlMessage::ROLE_WORKER . '#2', $slotIds);
    }

    /**
     * 安全边界：超过最小年龄且进程确实消失的记录，仍必须被清理（孤儿不能被永久保留）。
     */
    public function testStaleRecordIsStillPrunedAfterMinimumAge(): void
    {
        [$instance, $token, $store, $lease, $masterPid] = $this->fixture('prune-stale');
        $deadPid = 5_100_101;
        $this->processAlive[$deadPid] = true;
        $this->processStart[$deadPid] = 'dead-worker-start';

        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#1',
            'launch_id' => 'dead-launch',
            'lease_id' => 'dead-lease',
            'generation' => 1,
            'pid' => $deadPid,
        ]]);
        self::assertCount(1, $this->records($instance));

        // 授权完成后进程才真正消失。
        $this->processAlive[$deadPid] = false;

        // 推进到最小年龄之后，另一个 slot 授权会触发 prune，死记录应被清掉。
        $this->now += 60.0;
        $livePid = 5_100_102;
        $this->processAlive[$livePid] = true;
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#2',
            'launch_id' => 'live-launch',
            'lease_id' => 'live-lease',
            'generation' => 1,
            'pid' => $livePid,
        ]]);

        $slotIds = \array_map(
            static fn (array $r): string => (string)($r['slot_id'] ?? ''),
            $this->records($instance),
        );
        self::assertNotContains(
            ControlMessage::ROLE_WORKER . '#1',
            $slotIds,
            '超过最小年龄且进程已消失的记录必须被清理，孤儿检测不能被削弱'
        );
        self::assertContains(ControlMessage::ROLE_WORKER . '#2', $slotIds);
    }

    /**
     * @return array{0:string,1:string,2:MasterChildCredentialStore,3:string,4:int}
     */
    private function fixture(string $prefix): array
    {
        $instance = $prefix . '-' . \bin2hex(\random_bytes(5));
        $this->instances[] = $instance;
        $masterPid = (int)\getmypid();
        $this->processAlive[$masterPid] = true;
        $this->processStart[$masterPid] = 'master-process-start';

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
