<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Native\DarwinProcessProbe;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * Darwin 上「僵尸」必须被算作**已释放**，且全程不调用 `ps`。
 *
 * 修复前的实测（2026-09-26，macOS arm64 / PHP 8.4）：
 *
 * ```text
 * terminate={"released":false,"terminated":true,
 *            "reason":"darwin_posix_termination_unverified","owner_state":"unknown"}
 * posix_kill(0)=1   // SIGKILL 已发出、进程已成尸体，却被判定为「未释放」
 * ```
 *
 * 根因：libproc 读不到尸体 ⇒ `observeProcessIdentity()` 只能给出 `OWNER_UNKNOWN`，
 * 而终止分支只接受 `OWNER_MISSING` / `OWNER_MISMATCH`。
 * 后果是失败回滚**拒绝回收自己刚启动的** Master / 托管 Nginx master，留下孤儿。
 */
final class MasterLeaseRuntimeIdentityDarwinZombieTest extends TestCase
{
    /** @var list<resource> */
    private array $children = [];
    /** @var list<int> */
    private array $childPids = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || !DarwinProcessProbe::available()) {
            self::markTestSkipped('Requires a Darwin host with the ps-free probe available.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $process) {
            if (\is_resource($process)) {
                @\proc_terminate($process, 9);
                @\proc_close($process);
            }
        }
        $this->children = [];
        foreach ($this->childPids as $pid) {
            @\posix_kill($pid, 9);
            @\pcntl_waitpid($pid, $status);
        }
        $this->childPids = [];
    }

    public function testAZombieIsDefinitelyMissingWithoutPs(): void
    {
        $pid = $this->forkImmediateExitChild();
        $runtime = new MasterLeaseRuntimeIdentity();

        self::assertSame(
            DarwinProcessProbe::LIVENESS_ZOMBIE,
            DarwinProcessProbe::liveness($pid),
            '前置条件：该 PID 必须是真实僵尸。',
        );
        self::assertTrue(
            $runtime->isProcessDefinitelyMissing($pid),
            '僵尸的活动身份已消失，必须被判为 definitely missing。',
        );
    }

    public function testAReapedPidIsDefinitelyMissing(): void
    {
        $pid = $this->forkImmediateExitChild();
        @\pcntl_waitpid($pid, $status);
        $this->childPids = \array_values(\array_filter(
            $this->childPids,
            static fn (int $candidate): bool => $candidate !== $pid,
        ));

        self::assertTrue((new MasterLeaseRuntimeIdentity())->isProcessDefinitelyMissing($pid));
    }

    public function testALiveChildIsNotDefinitelyMissing(): void
    {
        $pid = $this->startChild(['sleep', '60']);

        self::assertFalse((new MasterLeaseRuntimeIdentity())->isProcessDefinitelyMissing($pid));
    }

    /**
     * ★ 缺陷 D2 的直接回归：对真实子进程的终止必须闭环。
     */
    public function testTerminatingARealChildReportsTheLeaseReleased(): void
    {
        $pid = $this->startChild(['sleep', '60']);
        $runtime = new MasterLeaseRuntimeIdentity();
        $identity = $runtime->captureProcessIdentity($pid);

        self::assertSame(
            MasterLeaseRuntimeIdentity::OWNER_MATCH,
            $runtime->observeProcessIdentity(
                $pid,
                (string)$identity['birth'],
                (string)$identity['pid_namespace_id'],
            ),
            '前置条件：终止前必须仍能证明是同一个进程。',
        );

        $result = $runtime->terminateExactProcessIdentity(
            $pid,
            (string)$identity['birth'],
            (string)$identity['pid_namespace_id'],
            2.0,
        );

        self::assertTrue($result['released'], '终止必须闭环，不得停在 unverified。');
        self::assertContains($result['reason'], [
            'darwin_posix_term_released',
            'darwin_posix_kill_released',
        ]);
        self::assertSame(MasterLeaseRuntimeIdentity::OWNER_MISSING, $result['owner_state']);
        self::assertSame($pid, $result['pid']);
    }

    /**
     * 反例护栏：身份不符时**不得**发信号。
     * 与 Darwin 修复相邻，防止「僵尸放宽」顺带放宽了出生身份围栏。
     */
    public function testAMismatchedBirthIsReleasedWithoutSignalling(): void
    {
        $pid = $this->startChild(['sleep', '60']);

        $result = (new MasterLeaseRuntimeIdentity())->terminateExactProcessIdentity(
            $pid,
            \str_repeat('a', 64),
            '',
            0.25,
        );

        self::assertTrue($result['released']);
        self::assertFalse($result['terminated']);
        self::assertSame(MasterLeaseRuntimeIdentity::OWNER_MISMATCH, $result['owner_state']);
        self::assertSame('process_identity_released_without_signal', $result['reason']);
        // 进程必须还活着。
        self::assertSame(1, (int)@\posix_kill($pid, 0));
    }

    private function startChild(array $command): int
    {
        $process = \proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pid = (int)\proc_get_status($process)['pid'];
        self::assertGreaterThan(0, $pid);
        $this->children[] = $process;
        \usleep(150_000);

        return $pid;
    }

    private function forkImmediateExitChild(): int
    {
        $pid = \pcntl_fork();
        self::assertIsInt($pid);
        if ($pid === 0) {
            exit(0);
        }
        self::assertGreaterThan(0, $pid);
        $this->childPids[] = $pid;
        \usleep(250_000);

        return $pid;
    }
}
