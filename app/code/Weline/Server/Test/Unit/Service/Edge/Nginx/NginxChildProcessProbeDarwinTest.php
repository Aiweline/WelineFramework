<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Native\DarwinProcessProbe;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxChildProcessProbe;

/**
 * D6 回归：Darwin 上的 Nginx 子进程探测**不得**依赖外部 `ps`。
 *
 * ## 被修复的缺陷
 *
 * `NginxChildProcessProbe` 在 macOS 上落到「通用 Unix」分支，即
 * `pgrep -P` + `ps -p … -o pid=,ppid=,command=`。`ps` 一旦不可用
 * （沙箱 / 加固运行时 / 受限 PATH），`workerPids()` 返回 `null`，
 * 于是 `ManagedNginxTlsSessionResumptionVerifier::detectEffectiveWorkerCount()`
 * 得到 0，托管 Nginx 启动整体失败于：
 *
 * ```text
 * managed nginx TLS session resumption verification failed:
 * managed nginx effective worker count could not be verified; candidate process stopped
 * ```
 *
 * ## 为什么这些断言有判别力
 *
 * 判别点不是「返回了正确的 worker 列表」（那需要真起一个 nginx），
 * 而是 **`workerPids()` 对一个活着的非 nginx 父进程必须返回 `[]` 而不是 `null`**：
 * `[]` = 「原生探针查到了，只是没有 worker」；`null` = 「问不出来」。
 * 修复前在 `ps` 不可用的机器上必然是 `null`。
 *
 * 断言用**真实子进程**（`proc_open(['/bin/sleep', …])`，数组形式不经过 shell，
 * 因此 `sleep` 就是当前进程的直接子进程），不用任何 mock。
 */
final class NginxChildProcessProbeDarwinTest extends TestCase
{
    /** SIGKILL：不依赖 pcntl 是否加载（`posix` 不提供该常量）。 */
    private const SIGKILL_SIGNAL = 9;

    /** @var list<resource> */
    private array $children = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Darwin-only ps-free Nginx process probing.');
        }
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('DarwinProcessProbe is unavailable (FFI disabled).');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (\is_resource($child)) {
                @\proc_terminate($child, self::SIGKILL_SIGNAL);
                @\proc_close($child);
            }
        }
        $this->children = [];
    }

    /**
     * 起一个真实子进程。数组形式的 `proc_open` 不经过 shell，
     * 所以返回的 PID 就是**当前进程的直接子进程**。
     */
    private function spawnSleep(int $seconds = 30): int
    {
        $pipes = [];
        $handle = \proc_open(
            ['/bin/sleep', (string)$seconds],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($handle, 'the fixture child process must start');
        $this->children[] = $handle;
        $pid = (int)(\proc_get_status($handle)['pid'] ?? 0);
        self::assertGreaterThan(0, $pid, 'the fixture child process must expose its PID');

        return $pid;
    }

    public function testChildPidsEnumeratesDirectChildrenWithoutAnyExternalCommand(): void
    {
        $first = $this->spawnSleep();
        $second = $this->spawnSleep();

        $children = DarwinProcessProbe::childPids((int)\getmypid());

        self::assertIsArray($children);
        self::assertContains($first, $children);
        self::assertContains($second, $children);
    }

    public function testChildPidsSeparatesEmptyFromUnknownAndRejectsInvalidParents(): void
    {
        // 不存在的 PID ⇒ 内核确认没有子进程（不是「问不出来」）。
        self::assertSame([], DarwinProcessProbe::childPids(999_999));
        // ★ 非正 PID 必须是 null：实测 ppid=0 返回的是垃圾数据。
        self::assertNull(DarwinProcessProbe::childPids(0));
        self::assertNull(DarwinProcessProbe::childPids(-1));
    }

    public function testChildPidsIsEmptyForAProcessWithoutChildren(): void
    {
        self::assertSame([], DarwinProcessProbe::childPids((int)\getmypid()));
    }

    /**
     * ★ D6 的核心断言：活着的、有子进程但都不是 nginx worker 的父进程
     * 必须得到 `[]`（原生探针可用），而不是 `null`（探针不可用）。
     */
    public function testWorkerPidsReturnsEmptyInsteadOfUnknownForALiveNonNginxParent(): void
    {
        $this->spawnSleep();

        $workers = NginxChildProcessProbe::workerPids((int)\getmypid());

        self::assertIsArray(
            $workers,
            'Darwin worker enumeration must not depend on the external ps command.',
        );
        self::assertSame([], $workers, 'a sleep child is not an nginx worker');
    }

    public function testWorkerPidsRejectsNonPositiveMasterPid(): void
    {
        self::assertNull(NginxChildProcessProbe::workerPids(0));
        self::assertNull(NginxChildProcessProbe::workerPids(-1));
    }

    public function testWorkerPidsReturnsEmptyForAMasterWithoutChildren(): void
    {
        self::assertSame([], NginxChildProcessProbe::workerPids(999_999));
    }

    public function testProcessIsRunningUsesNativeLiveness(): void
    {
        self::assertTrue(NginxChildProcessProbe::processIsRunning((int)\getmypid()));
        self::assertFalse(NginxChildProcessProbe::processIsRunning(999_999));
    }

    public function testProcessIsRunningReportsATerminatedAndReapedChildAsStopped(): void
    {
        $pid = $this->spawnSleep();
        self::assertTrue(NginxChildProcessProbe::processIsRunning($pid));

        $handle = \array_pop($this->children);
        self::assertIsResource($handle);
        @\proc_terminate($handle, self::SIGKILL_SIGNAL);
        \proc_close($handle);

        self::assertFalse(NginxChildProcessProbe::processIsRunning($pid));
    }
}
