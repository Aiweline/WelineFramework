<?php

declare(strict_types=1);

namespace Weline\Server\Console\Runtime\Task;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Service\Runtime\ResumableTaskWatchdogHeartbeat;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\IPC\ChildControl\ChildProcessIdentity;
use Weline\Server\IPC\ChildControl\Handler\RedirectControlHandler;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Runtime\Resumable\RuntimeTaskWatchdog;

/** Dedicated supervisor process for resumable task Runners. */
final class Watch extends CommandAbstract
{
    private const TICK_MILLISECONDS = 1_000;
    private const SHUTDOWN_DRAIN_SECONDS = 30;

    public function __construct(
        private readonly RuntimeTaskWatchdog $watchdog,
        private readonly ResumableTaskWatchdogHeartbeat $heartbeat,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $daemon = $this->enabled($args['daemon'] ?? false) && !$this->enabled($args['once'] ?? false);
        if (!$daemon) {
            $report = $this->watchdog->tick();
            $this->printer->note(json_encode(get_object_vars($report), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
            return;
        }

        $instanceName = trim($this->stringArgument($args, 'instance-name'));
        if ($instanceName === '') {
            throw new \RuntimeException('Runtime Watchdog requires a WLS instance name.');
        }

        $ownerId = 'runtime-watchdog-' . (getmypid() ?: 0) . '-' . bin2hex(random_bytes(8));
        $shutdown = false;
        $drainStartedAt = null;
        $this->registerSignalHandlers($shutdown);
        [$kernel, $masterGuard] = $this->connectToWlsMaster($args, $shutdown);

        try {
            while (true) {
                if ($kernel !== null) {
                    $kernel->tick();
                    $kernel->flushWrites();
                    if (!$kernel->isConnected() && !$shutdown) {
                        $kernel->reconnect();
                    }
                }
                if ($masterGuard !== null && $masterGuard->shouldExit()) {
                    return;
                }
                if ($shutdown && $drainStartedAt === null) {
                    $this->watchdog->beginShutdownDrain();
                    $drainStartedAt = microtime(true);
                }

                $this->heartbeat->beat($ownerId, $instanceName);
                try {
                    $this->watchdog->tick();
                } catch (\Throwable $throwable) {
                    WlsLogger::error_(
                        'Runtime Watchdog tick failed: '
                        . mb_substr($throwable->getMessage(), 0, 512)
                    );
                }

                if ($drainStartedAt !== null
                    && microtime(true) >= $drainStartedAt + self::SHUTDOWN_DRAIN_SECONDS) {
                    return;
                }
                SchedulerSystem::yieldDelay(self::TICK_MILLISECONDS);
            }
        } finally {
            // Avoid a close-triggered reconnect while this command is exiting.
            $shutdown = true;
            $kernel?->sendExited();
            $kernel?->close();
            $this->heartbeat->clearIfOwner($ownerId, $instanceName);
        }
    }

    public function tip(): string
    {
        return __('监督可恢复后台任务 Runner、租约与崩溃恢复');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'runtime:task:watch --daemon|--once',
            $this->tip(),
            ['--daemon' => __('作为持久 Watchdog 进程运行'), '--once' => __('仅执行一次检查')],
            [],
            [],
        );
    }

    private function registerSignalHandlers(bool &$shutdown): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_signal')) {
            return;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        $stop = static function () use (&$shutdown): void {
            $shutdown = true;
        };
        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $stop);
        }
        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $stop);
        }
    }

    /**
     * Attach the daemon to the WLS control plane when WLS launched it.
     *
     * Standalone `runtime:task:watch --daemon` remains valid: no control-port
     * means no IPC handshake is attempted. WLS passes the identity arguments
     * below, which lets the generic CLI command participate in register/READY
     * and receive a cooperative SHUTDOWN instead of becoming an invisible
     * child process.
     *
     * @return array{0:?SubprocessControlKernel,1:?ChildMasterGuard}
     */
    private function connectToWlsMaster(array $args, bool &$shutdown): array
    {
        $controlPort = $this->integerArgument($args, 'control-port');
        if ($controlPort <= 0) {
            return [null, null];
        }

        $instanceName = $this->stringArgument($args, 'instance-name', 'default');
        $epoch = $this->integerArgument($args, 'epoch');
        $launchId = $this->stringArgument($args, 'launch-id');
        $workerId = max(1, $this->integerArgument($args, 'worker-id', 1));
        $masterPid = $this->integerArgument($args, 'master-pid');
        $masterLeaseFile = $this->stringArgument($args, 'master-lease-file');
        $masterToken = $this->stringArgument($args, 'master-token');
        $controlPort = SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
        if ($controlPort <= 0) {
            throw new \RuntimeException('Runtime Watchdog cannot resolve the WLS control endpoint.');
        }

        $identity = new ChildProcessIdentity(
            role: 'runtime_watchdog',
            pid: getmypid() ?: 0,
            port: 0,
            workerId: $workerId,
            epoch: $epoch,
            launchId: $launchId,
        );
        $handler = new RedirectControlHandler(static function (bool $requested) use (&$shutdown): void {
            if ($requested) {
                $shutdown = true;
            }
        });
        $kernel = new SubprocessControlKernel(
            identity: $identity,
            handler: $handler,
            selfTag: 'RuntimeTaskWatchdog',
            instanceCode: $instanceName,
        );
        // Register first, then drain the READY frame with a bounded writable
        // wait. `ControlClient::sendReady()` is intentionally non-blocking;
        // treating one initial non-writable poll as a failed launch made this
        // small daemon exit after REGISTER but before READY under WLS startup
        // concurrency.
        if (!$kernel->connectAndRegister($controlPort, false)) {
            throw new \RuntimeException('Runtime Watchdog cannot register with the WLS Master.');
        }
        if (!$this->sendWlsReady($kernel)) {
            throw new \RuntimeException('Runtime Watchdog cannot publish READY to the WLS Master.');
        }
        WlsLogger::info_("Runtime Watchdog connected to Master IPC on port {$controlPort}");

        return [
            $kernel,
            new ChildMasterGuard(
                masterPid: $masterPid,
                leaseFile: $masterLeaseFile,
                masterToken: $masterToken,
                selfTag: 'RuntimeTaskWatchdog',
                instance: $instanceName,
                masterEpoch: $epoch,
            ),
        ];
    }

    private function sendWlsReady(SubprocessControlKernel $kernel): bool
    {
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if ($kernel->sendReady()) {
                return true;
            }
            if (!$kernel->isConnected()) {
                return false;
            }
            $kernel->flushWrites();
            $kernel->tick();
            if ($kernel->isConnected() && !$kernel->hasPendingWrites()) {
                // sendReady() already queued and drained within this window.
                return true;
            }
            SchedulerSystem::usleep(10_000);
        }
        return false;
    }

    private function integerArgument(array $args, string $name, int $default = 0): int
    {
        return (int)$this->stringArgument($args, $name, (string)$default);
    }

    private function stringArgument(array $args, string $name, string $default = ''): string
    {
        foreach ([$name, str_replace('-', '_', $name)] as $key) {
            $value = $args[$key] ?? null;
            if (is_array($value)) {
                $value = end($value);
            }
            if (is_scalar($value)) {
                $value = trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return $default;
    }

    private function enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
