<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process\Driver;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Driver\DarwinProcessDriver;
use Weline\Framework\System\Process\Driver\LinuxProcessDriver;
use Weline\Framework\System\Process\Driver\ProcessDriverFactory;
use Weline\Framework\System\Process\Native\DarwinProcessProbe;

/**
 * Darwin 驱动契约。
 *
 * 关键设计：用例用一个把 {@see AbstractProcessDriver::executeCommand()} 打成
 * 永久失败的匿名子类来**模拟 `ps` 不可用**。这样即使 CI/开发机 `ps` 正常，
 * 断言依然有判别力 —— 它证明驱动**没有**走外部命令通路。
 */
final class DarwinProcessDriverTest extends TestCase
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

    public function testSupportsOnlyDarwin(): void
    {
        $driver = new DarwinProcessDriver();

        self::assertSame(\PHP_OS_FAMILY === 'Darwin', $driver->supports());
        self::assertSame('Darwin', $driver->getOsName());
    }

    /**
     * 注册顺序是语义：`LinuxProcessDriver::supports()` 对所有非 Windows 返回 true，
     * Darwin 驱动若排在它之后将永远不会被选中。
     */
    public function testTheFactoryRegistersDarwinAheadOfLinux(): void
    {
        $drivers = ProcessDriverFactory::getRegisteredDrivers();
        $darwin = \array_search(DarwinProcessDriver::class, $drivers, true);
        $linux = \array_search(LinuxProcessDriver::class, $drivers, true);

        self::assertIsInt($darwin);
        self::assertIsInt($linux);
        self::assertLessThan($linux, $darwin);
        if (\PHP_OS_FAMILY === 'Darwin') {
            self::assertInstanceOf(DarwinProcessDriver::class, ProcessDriverFactory::getDriver());
        } else {
            self::assertNotInstanceOf(DarwinProcessDriver::class, ProcessDriverFactory::getDriver());
        }
    }

    /** ★ 缺陷 D1 的直接回归：`ps` 不可用时命令行**仍必须**可得。 */
    public function testCommandLineSurvivesAnUnusablePs(): void
    {
        $this->requireProbe();
        $pid = $this->startChild(['sleep', '60']);

        $command = $this->driverWithUnusableExternalCommands()->getProcessCommandLine($pid);

        self::assertNotSame('', $command);
        self::assertStringContainsString('sleep', $command);
    }

    public function testInvalidPidsReturnAnEmptyCommandLine(): void
    {
        $driver = $this->driverWithUnusableExternalCommands();

        self::assertSame('', $driver->getProcessCommandLine(0));
        self::assertSame('', $driver->getProcessCommandLine(-1));
    }

    /**
     * ★ 缺陷 D2 在驱动层的回归：僵尸必须判为 EXITED。
     *
     * 注意 `parent::probeProcessState()` 在 `ps` 不可用时只能得到
     * `posix_kill` 的「在」⇒ RUNNING，因此本断言只有在原生通路生效时才成立。
     */
    public function testAZombieIsExitedEvenWithAnUnusablePs(): void
    {
        $this->requireProbe();
        $pid = $this->forkImmediateExitChild();

        self::assertSame(
            LinuxProcessDriver::PROCESS_STATE_EXITED,
            $this->driverWithUnusableExternalCommands()->probeProcessState($pid, true),
        );

        @\pcntl_waitpid($pid, $status);
    }

    public function testALiveProcessIsRunningEvenWithAnUnusablePs(): void
    {
        $this->requireProbe();
        $pid = $this->startChild(['sleep', '60']);

        self::assertSame(
            LinuxProcessDriver::PROCESS_STATE_RUNNING,
            $this->driverWithUnusableExternalCommands()->probeProcessState($pid, true),
        );
    }

    /**
     * `getProcessInfo()` 的策略是「`parent` 优先、原生只补空缺」
     * ⇒ `ps` 不可用时字段必须由内核补齐，而不是留空。
     */
    public function testProcessInfoIsCompletedFromTheKernelWhenPsIsUnusable(): void
    {
        $this->requireProbe();
        $pid = $this->startChild(['sleep', '60']);

        $info = $this->driverWithUnusableExternalCommands()->getProcessInfo($pid);

        self::assertTrue($info['exists']);
        self::assertNotSame('', $info['name']);
        self::assertStringContainsString('sleep', $info['command']);
        // 与 `ps -o lstart=` 同格式：NginxProcessIdentity::normalizeProcessStartTime()
        // 的两条正则都必须能命中。
        self::assertMatchesRegularExpression(
            '/\A(?:[A-Za-z]{3}\s+){2}\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4}\z/D',
            (string)$info['start_time'],
        );
    }

    public function testProcessInfoForAnAbsentPidStaysEmpty(): void
    {
        $info = $this->driverWithUnusableExternalCommands()->getProcessInfo(999_999);

        self::assertFalse($info['exists']);
        self::assertSame('', $info['command']);
    }

    private function requireProbe(): void
    {
        if (!DarwinProcessProbe::available()) {
            self::markTestSkipped('Darwin ps-free probe is unavailable here.');
        }
    }

    /** @return DarwinProcessDriver 外部命令永久失败的驱动（等价于 `ps` 被禁用）。 */
    private function driverWithUnusableExternalCommands(): DarwinProcessDriver
    {
        return new class extends DarwinProcessDriver {
            protected function executeCommand(
                string $command,
                array &$output = [],
                int &$exitCode = 0,
            ): bool {
                unset($command);
                $output = [];
                $exitCode = 127;

                return false;
            }
        };
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
