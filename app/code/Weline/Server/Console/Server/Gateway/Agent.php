<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\IPC\ChildControl\ChildProcessIdentity;
use Weline\Server\IPC\ChildControl\Handler\RedirectControlHandler;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Project-owned wls-edge/2 lease agent.
 */
final class Agent extends CommandAbstract
{
    private const TICK_MILLISECONDS = 1000;
    private const HEARTBEAT_SECONDS = 10;
    private const FALLBACK_AFTER_SECONDS = 90;
    private const RECOVERY_STABLE_SECONDS = 30;
    private const FALLBACK_DRAIN_SECONDS = 300;

    public function execute(array $args = [], array $data = []): int
    {
        if (!$this->enabled($args['daemon'] ?? false)) {
            $instance = $this->stringArgument($args, 'instance-name', 'default');
            $payload = (new GatewayHostManager())->heartbeat($instance);
            $this->printer->note(
                \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
            );
            return 0;
        }
        $instanceName = $this->stringArgument($args, 'instance-name');
        if ($instanceName === '') {
            throw new \RuntimeException('WLS Gateway Agent requires --instance-name.');
        }

        $shutdown = false;
        $this->registerSignals($shutdown);
        [$kernel, $guard] = $this->connectMaster($args, $shutdown);
        $gateway = new GatewayHostManager();
        $paths = new GatewayPaths();
        $builder = new GatewayRegistrationBuilder();
        $projectUuid = $builder->projectUuid();
        $lastHeartbeat = 0;
        $downSince = 0;
        $activeSince = 0;
        $fallbackStartedAt = 0;
        $fallbackDrainStartedAt = 0;
        $fallbackName = 'gateway-fallback-' . \substr(\hash('sha256', $instanceName), 0, 12);

        try {
            while (!$shutdown) {
                $kernel?->tick();
                $kernel?->flushWrites();
                if ($kernel !== null && !$kernel->isConnected()) {
                    $kernel->reconnect();
                }
                if ($guard?->shouldExit()) {
                    break;
                }
                $now = \time();
                $status = $gateway->status();
                $dataPlaneHealthy = ($status['ok'] ?? false)
                    && (bool)($status['data_plane']['running'] ?? false)
                    && (string)($status['state'] ?? '') !== 'DATA_PLANE_DOWN';
                if (!($status['ok'] ?? false)) {
                    // A control-plane outage is not a data-plane outage. Direct
                    // TCP reachability keeps public traffic authoritative.
                    $dataPlaneHealthy = $this->portReachable($paths->publicHttpPort())
                        && $this->portReachable($paths->publicHttpsPort());
                }

                if ($dataPlaneHealthy) {
                    $downSince = 0;
                } elseif ($downSince === 0) {
                    $downSince = $now;
                }
                if ($now - $lastHeartbeat >= self::HEARTBEAT_SECONDS && ($status['ok'] ?? false)) {
                    try {
                        $gateway->heartbeat($instanceName);
                    } catch (\Throwable) {
                        // Epoch changes and state rebuild require a full desired
                        // state replay. Other failures are retried next tick.
                        try {
                            $gateway->register($instanceName);
                        } catch (\Throwable) {
                        }
                    }
                    $lastHeartbeat = $now;
                }

                if ($downSince > 0
                    && $now - $downSince >= self::FALLBACK_AFTER_SECONDS
                    && !$this->instanceExists($fallbackName)
                ) {
                    $port = (new GatewayPortLeaseAllocator())->allocate($fallbackName);
                    $this->startFallback($fallbackName, $port);
                    $fallbackStartedAt = $now;
                }

                $routeActive = $this->projectRouteActive($gateway, $projectUuid);
                if ($routeActive) {
                    $activeSince = $activeSince > 0 ? $activeSince : $now;
                } else {
                    $activeSince = 0;
                    $fallbackDrainStartedAt = 0;
                }
                if ($this->instanceExists($fallbackName)
                    && $activeSince > 0
                    && $now - $activeSince >= self::RECOVERY_STABLE_SECONDS
                ) {
                    $fallbackDrainStartedAt = $fallbackDrainStartedAt > 0
                        ? $fallbackDrainStartedAt
                        : $now;
                    if ($now - $fallbackDrainStartedAt >= self::FALLBACK_DRAIN_SECONDS) {
                        $this->stopFallback($fallbackName);
                        $fallbackStartedAt = 0;
                        $fallbackDrainStartedAt = 0;
                    }
                }
                SchedulerSystem::yieldDelay(self::TICK_MILLISECONDS);
            }
        } finally {
            try {
                $gateway->drain($instanceName, 300);
            } catch (\Throwable) {
            }
            $kernel?->sendExited();
            $kernel?->close();
        }
        return 0;
    }

    public function tip(): string
    {
        return __('维护项目到 WLS 2.0 网关的租约、重注册和纯 WLS 降级');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:agent --daemon --instance-name=<name>',
            $this->tip(),
            ['--daemon' => __('作为 WLS 子进程持续运行')],
            [],
            [],
        );
    }

    /**
     * @return array{0:?SubprocessControlKernel,1:?ChildMasterGuard}
     */
    private function connectMaster(array $args, bool &$shutdown): array
    {
        $controlPort = $this->integerArgument($args, 'control-port');
        if ($controlPort <= 0) {
            return [null, null];
        }
        $instanceName = $this->stringArgument($args, 'instance-name', 'default');
        $epoch = $this->integerArgument($args, 'epoch');
        $launchId = $this->stringArgument($args, 'launch-id');
        $workerId = \max(1, $this->integerArgument($args, 'worker-id', 1));
        $masterPid = $this->integerArgument($args, 'master-pid');
        $leaseFile = $this->stringArgument($args, 'master-lease-file');
        $masterToken = $this->stringArgument($args, 'master-token');
        $controlPort = SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
        $identity = new ChildProcessIdentity(
            role: 'gateway_agent',
            pid: \getmypid() ?: 0,
            port: 0,
            workerId: $workerId,
            epoch: $epoch,
            launchId: $launchId,
        );
        $handler = new RedirectControlHandler(static function (bool $requested) use (&$shutdown): void {
            $shutdown = $shutdown || $requested;
        });
        $kernel = new SubprocessControlKernel(
            identity: $identity,
            handler: $handler,
            selfTag: 'WlsGatewayAgent',
            instanceCode: $instanceName,
        );
        if (!$kernel->connectAndRegister($controlPort, false)) {
            throw new \RuntimeException('WLS Gateway Agent cannot register with Master.');
        }
        $deadline = \microtime(true) + 3.0;
        while (\microtime(true) < $deadline) {
            if ($kernel->sendReady()) {
                break;
            }
            $kernel->tick();
            $kernel->flushWrites();
            SchedulerSystem::usleep(10000);
        }
        return [
            $kernel,
            new ChildMasterGuard(
                masterPid: $masterPid,
                leaseFile: $leaseFile,
                masterToken: $masterToken,
                selfTag: 'WlsGatewayAgent',
                instance: $instanceName,
                masterEpoch: $epoch,
            ),
        ];
    }

    private function projectRouteActive(GatewayHostManager $gateway, string $projectUuid): bool
    {
        try {
            $response = $gateway->request('routes');
            foreach ((array)($response['payload']['routes'] ?? []) as $route) {
                if (\is_array($route)
                    && (string)($route['project_uuid'] ?? '') === $projectUuid
                    && (string)($route['status'] ?? '') === 'ACTIVE'
                ) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private function startFallback(string $name, int $port): void
    {
        Processer::createDetachedPhpArgv(
            [\PHP_BINARY, BP . 'bin' . DS . 'w', 'server:start', $name, '-p', (string)$port, '--edge=wls'],
            (string)BP,
            'weline-gateway-fallback-start --name=' . $name,
            true,
        );
    }

    private function stopFallback(string $name): void
    {
        Processer::createDetachedPhpArgv(
            [\PHP_BINARY, BP . 'bin' . DS . 'w', 'server:stop', $name],
            (string)BP,
            'weline-gateway-fallback-stop --name=' . $name,
            true,
        );
    }

    private function instanceExists(string $name): bool
    {
        return (new ServerInstanceManager())->hasInstance($name);
    }

    private function portReachable(int $port): bool
    {
        $socket = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.5);
        if (!\is_resource($socket)) {
            return false;
        }
        @\fclose($socket);
        return true;
    }

    private function registerSignals(bool &$shutdown): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('pcntl_signal')) {
            return;
        }
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
        }
        $handler = static function () use (&$shutdown): void {
            $shutdown = true;
        };
        if (\defined('SIGINT')) {
            \pcntl_signal(SIGINT, $handler);
        }
        if (\defined('SIGTERM')) {
            \pcntl_signal(SIGTERM, $handler);
        }
    }

    private function integerArgument(array $args, string $name, int $default = 0): int
    {
        return (int)$this->stringArgument($args, $name, (string)$default);
    }

    private function stringArgument(array $args, string $name, string $default = ''): string
    {
        foreach ([$name, \str_replace('-', '_', $name)] as $key) {
            $value = $args[$key] ?? null;
            if (\is_array($value)) {
                $value = \end($value);
            }
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                return \trim((string)$value);
            }
        }
        return $default;
    }

    private function enabled(mixed $value): bool
    {
        return \is_bool($value)
            ? $value
            : \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
