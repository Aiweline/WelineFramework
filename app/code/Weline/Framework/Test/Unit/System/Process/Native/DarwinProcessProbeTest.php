<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process\Native;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Native\DarwinProcessProbe;

/**
 * ps-free Darwin 进程原语的行为契约。
 *
 * 这些用例的价值在于：它们**不依赖 `ps`**。修复前 Darwin 上所有进程观察通路
 * 都落到外部 `ps`，一旦 `ps` 被运行环境禁用（agent 沙箱实测
 * `operation not permitted: ps`），身份门禁就整体失效。
 */
final class DarwinProcessProbeTest extends TestCase
{
    /** @var list<resource> */
    private array $children = [];
    /** @var list<int> */
    private array $childPids = [];

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
        }
        $this->childPids = [];
    }

    public function testAvailabilityIsAGatedBoolean(): void
    {
        $available = DarwinProcessProbe::available();

        self::assertIsBool($available);
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::assertFalse($available, '非 Darwin 必须不可用。');
        }
    }

    public function testALiveProcessIsRunningAndExposesItsArguments(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
        $pid = $this->startChild(['sleep', '60']);

        self::assertSame(DarwinProcessProbe::LIVENESS_RUNNING, DarwinProcessProbe::liveness($pid));

        $info = DarwinProcessProbe::bsdInfo($pid);
        self::assertIsArray($info);
        self::assertSame($pid, $info['pid']);
        self::assertGreaterThan(0, $info['start_tvsec']);
        self::assertGreaterThanOrEqual(0, $info['start_tvusec']);

        $command = DarwinProcessProbe::commandLine($pid);
        self::assertIsString($command);
        // argv[0] 必须是真实可执行路径，且裸空格拼接（与 ps / /proc 呈现一致）。
        self::assertStringContainsString('sleep', $command);
        self::assertStringContainsString('60', $command);
    }

    /**
     * ★ 核心：僵尸必须能被识别，且**完全不调用 `ps`**。
     *
     * 实测不变量：libproc 读不到尸体（`proc_pidinfo` 返回 `read=0`），
     * 唯一判据是 `posix_kill(pid, 0)` 仍成功。
     */
    public function testAZombieIsIdentifiedWithoutPs(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
        $pid = $this->forkImmediateExitChild();

        self::assertSame(
            DarwinProcessProbe::LIVENESS_ZOMBIE,
            DarwinProcessProbe::liveness($pid),
            '僵尸进程必须被判为 zombie，而不是 running 或 unknown。',
        );
        // 僵尸的尸体仍占着 PID：libproc 读不到，但信号可达。
        self::assertNull(DarwinProcessProbe::bsdInfo($pid));
        self::assertSame(1, (int)@\posix_kill($pid, 0));

        @\pcntl_waitpid($pid, $status);
    }

    public function testAReapedPidIsExited(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
        $pid = $this->forkImmediateExitChild();
        @\pcntl_waitpid($pid, $status);

        self::assertSame(DarwinProcessProbe::LIVENESS_EXITED, DarwinProcessProbe::liveness($pid));
    }

    /**
     * ★ fail-closed 护栏：`EPERM`（进程存在但属他人）**绝不能**被当成僵尸。
     *
     * 这正是「读不到 + 可发信号 ⇒ 僵尸」这条推断的唯一边界。
     */
    public function testAnUnreadableForeignProcessIsUnknownNotZombie(): void
    {
        if (!DarwinProcessProbe::available() || \function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Requires a non-root Darwin host.');
        }
        // PID 1 属于 root：libproc 读不到，posix_kill 报 EPERM。
        self::assertNull(DarwinProcessProbe::bsdInfo(1));
        self::assertSame(
            DarwinProcessProbe::LIVENESS_UNKNOWN,
            DarwinProcessProbe::liveness(1),
            'EPERM 只能得出 unknown，不得推断为 zombie。',
        );
    }

    public function testAnAbsentPidIsExited(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
        self::assertSame(DarwinProcessProbe::LIVENESS_EXITED, DarwinProcessProbe::liveness(999_999));
    }

    public function testNonPositivePidsAreExitedWithoutThrowing(): void
    {
        foreach ([0, -1] as $pid) {
            self::assertSame(DarwinProcessProbe::LIVENESS_EXITED, DarwinProcessProbe::liveness($pid));
            self::assertNull(DarwinProcessProbe::bsdInfo($pid));
            self::assertNull(DarwinProcessProbe::commandLine($pid));
        }
    }

    public function testCommandLineIsNullWhenTheProcessCannotBeRead(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
        self::assertNull(DarwinProcessProbe::commandLine(999_999));
    }

    public function testLivenessOnlyEverReturnsTheClosedVocabulary(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
        $vocabulary = [
            DarwinProcessProbe::LIVENESS_RUNNING,
            DarwinProcessProbe::LIVENESS_ZOMBIE,
            DarwinProcessProbe::LIVENESS_EXITED,
            DarwinProcessProbe::LIVENESS_UNKNOWN,
        ];
        $pid = $this->startChild(['sleep', '60']);

        foreach ([(int)\getmypid(), $pid, 1, 999_999, 0] as $candidate) {
            self::assertContains(DarwinProcessProbe::liveness($candidate), $vocabulary);
        }
    }

    public function testClearCacheKeepsTheProbeUsable(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
        $pid = (int)\getmypid();
        $before = DarwinProcessProbe::liveness($pid);

        DarwinProcessProbe::clearCacheForTests();

        self::assertSame($before, DarwinProcessProbe::liveness($pid));
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
        // 等它真正起来，避免在 exec 之前观察。
        \usleep(150_000);

        return $pid;
    }

    /** fork 一个立刻 exit 的子进程，且**不回收** ⇒ 稳定造出真实僵尸。 */
    private function forkImmediateExitChild(): int
    {
        self::assertTrue(\function_exists('pcntl_fork'), '需要 pcntl 才能造出真实僵尸。');
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
