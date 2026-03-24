<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Shared\Client\SharedStateClient;

class SharedStateServiceManager
{
    private const DEFAULT_ENSURE_TIMEOUT_SEC = 6.0;
    private const DEFAULT_ENSURE_POLL_INTERVAL_MS = 100;

    private ?SharedStateServiceRegistry $registry = null;
    private ?SharedSidecarInspector $inspector = null;

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array{
     *   session: array<string, mixed>,
     *   memory: array<string, mixed>
     * }
     */
    public function ensureRuntime(string $requesterInstanceName, array $config, array $envConfig = []): array
    {
        $session = $this->buildServiceDefinition(
            ControlMessage::ROLE_SESSION_SERVER,
            $requesterInstanceName,
            $config,
            $envConfig
        );
        $memory = $this->buildServiceDefinition(
            ControlMessage::ROLE_MEMORY_SERVER,
            $requesterInstanceName,
            $config,
            $envConfig
        );

        if ((int) $session['port'] === (int) $memory['port']) {
            throw new \RuntimeException(
                \sprintf(
                    'Shared Session and Memory services cannot use the same port %d for instance [%s].',
                    (int) $session['port'],
                    $requesterInstanceName
                )
            );
        }

        return [
            'session' => $this->ensureService($session, $requesterInstanceName),
            'memory' => $this->ensureService($memory, $requesterInstanceName),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function ensureService(array $definition, string $requesterInstanceName): array
    {
        $role = (string) $definition['role'];

        return $this->getRegistry()->withRoleLock($role, function () use ($definition, $requesterInstanceName): array {
            $running = $this->resolveRunningService($definition, $requesterInstanceName);
            if ($running !== null) {
                return $running;
            }

            $this->launchSharedServiceProcess($definition, $requesterInstanceName);

            return $this->waitUntilServiceReady($definition, $requesterInstanceName);
        });
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    protected function resolveRunningService(array $definition, string $requesterInstanceName): ?array
    {
        $role = (string) $definition['role'];
        $record = $this->getRegistryRecord($role);

        if ($this->registryRecordMatchesDefinition($record, $definition)) {
            $recordToken = \trim((string) ($record['token_file_name'] ?? ''));
            $tokenFileName = $recordToken !== ''
                ? $recordToken
                : (string) $definition['token_file_name'];

            if ($this->probeRunningSharedService($definition, $tokenFileName)) {
                $inspection = $this->inspectRunningSharedService($definition, $tokenFileName);

                return $this->storeAndBuildRuntime(
                    $definition,
                    $requesterInstanceName,
                    $inspection,
                    $tokenFileName,
                    true,
                    false
                );
            }
        }

        $inspection = $this->inspectRunningSharedService(
            $definition,
            (string) $definition['token_file_name']
        );

        if ((bool) ($inspection['reusable'] ?? false)) {
            $tokenFileName = \trim((string) ($inspection['token_file_name'] ?? ''));
            if ($tokenFileName === '') {
                $tokenFileName = (string) $definition['token_file_name'];
            }

            if ($this->probeRunningSharedService($definition, $tokenFileName)) {
                return $this->storeAndBuildRuntime(
                    $definition,
                    $requesterInstanceName,
                    $inspection,
                    $tokenFileName,
                    true,
                    false
                );
            }
        }

        $this->removeRegistryRecord($role);

        if ($this->isPortOccupied((int) $definition['port'])) {
            $pid = (int) ($inspection['pid'] ?? 0);
            $processName = (string) ($inspection['process_name'] ?? '');
            throw new \RuntimeException(
                \sprintf(
                    'Shared %s port %d is occupied by a non-reusable process for instance [%s] (pid=%d, process=%s).',
                    $this->displayNameForRole($role),
                    (int) $definition['port'],
                    $requesterInstanceName,
                    $pid,
                    $processName !== '' ? $processName : 'unknown'
                )
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function waitUntilServiceReady(array $definition, string $requesterInstanceName): array
    {
        $timeoutSec = (float) ($definition['ensure_timeout_sec'] ?? self::DEFAULT_ENSURE_TIMEOUT_SEC);
        $pollMs = (int) ($definition['ensure_poll_interval_ms'] ?? self::DEFAULT_ENSURE_POLL_INTERVAL_MS);
        $pollMs = $pollMs > 0 ? $pollMs : self::DEFAULT_ENSURE_POLL_INTERVAL_MS;
        $deadline = \microtime(true) + $timeoutSec;
        $lastInspection = [];

        while (\microtime(true) < $deadline) {
            SchedulerSystem::usleep($pollMs * 1000);

            $lastInspection = $this->inspectRunningSharedService(
                $definition,
                (string) $definition['token_file_name']
            );

            if ((bool) ($lastInspection['reusable'] ?? false)) {
                $tokenFileName = \trim((string) ($lastInspection['token_file_name'] ?? ''));
                if ($tokenFileName === '') {
                    $tokenFileName = (string) $definition['token_file_name'];
                }

                if ($this->probeRunningSharedService($definition, $tokenFileName)) {
                    return $this->storeAndBuildRuntime(
                        $definition,
                        $requesterInstanceName,
                        $lastInspection,
                        $tokenFileName,
                        false,
                        true
                    );
                }
            }
        }

        $pid = (int) ($lastInspection['pid'] ?? 0);
        throw new \RuntimeException(
            \sprintf(
                'Shared %s service failed to become ready on %s:%d for instance [%s] (pid=%d).',
                $this->displayNameForRole((string) $definition['role']),
                (string) $definition['host'],
                (int) $definition['port'],
                $requesterInstanceName,
                $pid
            )
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
    {
        $command = $this->buildLaunchCommand($definition, $requesterInstanceName);
        $cmd = $command->build();
        $processName = $command->getProcessName();
        if ($processName !== null) {
            $cmd .= ' --name=' . \escapeshellarg($processName);
        }

        return Processer::create($cmd, block: false, foreground: false);
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function buildLaunchCommand(array $definition, string $requesterInstanceName): ServiceCommand
    {
        $arguments = [
            (string) $definition['host'],
            (string) $definition['port'],
            (string) $definition['service_instance_name'],
            '--instance-name=' . (string) $definition['service_instance_name'],
            '--token-file-name=' . (string) $definition['token_file_name'],
            '--bootstrap-instance=' . $requesterInstanceName,
            '--shared-service=1',
        ];

        if ((string) $definition['role'] === ControlMessage::ROLE_MEMORY_SERVER) {
            $arguments[] = '--role=' . ControlMessage::ROLE_MEMORY_SERVER;
        }

        return new ServiceCommand(
            script: 'app/code/Weline/Server/bin/session_server.php',
            arguments: $arguments,
            processName: (string) $definition['process_name'],
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
    {
        return $this->getInspector()->inspect(
            (int) $definition['port'],
            (string) $definition['role'],
            $expectedTokenFileName
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
    {
        $client = new SharedStateClient(
            (string) $definition['host'],
            (int) $definition['port'],
            [
                'token_file_name' => $tokenFileName,
                'acquire_timeout' => 0.2,
            ]
        );

        try {
            return $client->ping();
        } catch (\Throwable) {
            return false;
        } finally {
            $client->disconnect();
        }
    }

    protected function isPortOccupied(int $port): bool
    {
        return $port > 0 && Processer::isPortInUse($port);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    protected function storeAndBuildRuntime(
        array $definition,
        string $requesterInstanceName,
        array $inspection,
        string $tokenFileName,
        bool $reuseExisting,
        bool $createdNow
    ): array {
        $role = (string) $definition['role'];
        $existingRecord = $this->getRegistryRecord($role);
        $consumers = \is_array($existingRecord['consumers'] ?? null) ? $existingRecord['consumers'] : [];
        $consumers[$requesterInstanceName] = [
            'last_ensured_at' => \date('c'),
        ];

        $actualProcessName = \trim((string) ($inspection['process_name'] ?? ''));
        if ($actualProcessName === '') {
            $actualProcessName = (string) $definition['process_name'];
        }

        $actualInstanceName = \trim((string) ($inspection['instance_name'] ?? ''));
        if ($actualInstanceName === '') {
            $actualInstanceName = (string) $definition['service_instance_name'];
        }

        $record = [
            'role' => $role,
            'host' => (string) $definition['host'],
            'port' => (int) $definition['port'],
            'token_file_name' => $tokenFileName,
            'pid' => (int) ($inspection['pid'] ?? 0),
            'process_name' => $actualProcessName,
            'instance_name' => $actualInstanceName,
            'service_instance_name' => $actualInstanceName,
            'shared_service' => true,
            'independent' => true,
            'configured_token_file_name' => (string) $definition['token_file_name'],
            'last_ensured_by_instance' => $requesterInstanceName,
            'last_ensured_at' => \date('c'),
            'last_verified_at' => \date('c'),
            'consumers' => $consumers,
        ];

        $this->putRegistryRecord($role, $record);

        return [
            'host' => (string) $definition['host'],
            'port' => (int) $definition['port'],
            'token_file_name' => $tokenFileName,
            'reuse_existing' => $reuseExisting,
            'created_now' => $createdNow,
            'pid' => (int) ($inspection['pid'] ?? 0),
            'process_name' => $actualProcessName,
            'instance_name' => $actualInstanceName,
            'service_instance_name' => $actualInstanceName,
            'independent' => true,
            'shared_service' => true,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $definition
     */
    protected function registryRecordMatchesDefinition(array $record, array $definition): bool
    {
        if ($record === []) {
            return false;
        }

        return (string) ($record['role'] ?? '') === (string) $definition['role']
            && (int) ($record['port'] ?? 0) === (int) $definition['port']
            && \trim((string) ($record['host'] ?? '')) === (string) $definition['host'];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    protected function buildServiceDefinition(
        string $role,
        string $requesterInstanceName,
        array $config,
        array $envConfig
    ): array {
        $wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $sharedState = \is_array($wlsConfig['shared_state'] ?? null) ? $wlsConfig['shared_state'] : [];
        $ensureTimeoutSec = (float) ($sharedState['ensure_timeout_sec'] ?? self::DEFAULT_ENSURE_TIMEOUT_SEC);
        $ensurePollIntervalMs = (int) ($sharedState['ensure_poll_interval_ms'] ?? self::DEFAULT_ENSURE_POLL_INTERVAL_MS);

        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            $memoryConfig = \is_array($wlsConfig['memory_service'] ?? null) ? $wlsConfig['memory_service'] : [];
            $port = (int) ($config['memory_server_port'] ?? $memoryConfig['port'] ?? 19971);
            if ($port <= 0) {
                $port = 19971;
            }

            $tokenFileName = \trim((string) ($config['memory_server_token_file_name'] ?? $memoryConfig['token_file_name'] ?? 'memory_server.token'));
            if ($tokenFileName === '') {
                $tokenFileName = 'memory_server.token';
            }

            return [
                'role' => $role,
                'display_name' => 'Memory Service',
                'host' => '127.0.0.1',
                'port' => $port,
                'token_file_name' => $tokenFileName,
                'process_name' => MemoryServerProvider::PROCESS_NAME_PREFIX . '-shared-' . $port,
                'service_instance_name' => 'shared-memory-' . $port,
                'requester_instance_name' => $requesterInstanceName,
                'ensure_timeout_sec' => $ensureTimeoutSec,
                'ensure_poll_interval_ms' => $ensurePollIntervalMs,
            ];
        }

        $sessionConfig = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];
        $wlsSession = \is_array($wlsConfig['session'] ?? null) ? $wlsConfig['session'] : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        $port = (int) (
            $config['session_server_port']
            ?? $wlsSession['port']
            ?? $wlsServer['port']
            ?? $sessionConfig['server_port']
            ?? 19970
        );
        if ($port <= 0) {
            $port = 19970;
        }

        $tokenFileName = \trim((string) (
            $config['session_server_token_file_name']
            ?? $wlsServer['token_file_name']
            ?? $wlsSession['token_file_name']
            ?? 'session_server.token'
        ));
        if ($tokenFileName === '') {
            $tokenFileName = 'session_server.token';
        }

        return [
            'role' => $role,
            'display_name' => 'Session Server',
            'host' => '127.0.0.1',
            'port' => $port,
            'token_file_name' => $tokenFileName,
            'process_name' => SessionServerProvider::PROCESS_NAME_PREFIX . '-shared-' . $port,
            'service_instance_name' => 'shared-session-' . $port,
            'requester_instance_name' => $requesterInstanceName,
            'ensure_timeout_sec' => $ensureTimeoutSec,
            'ensure_poll_interval_ms' => $ensurePollIntervalMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRegistryRecord(string $role): array
    {
        return $this->getRegistry()->getRecord($role);
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function putRegistryRecord(string $role, array $record): void
    {
        $this->getRegistry()->putRecord($role, $record);
        $instanceName = \trim((string) ($record['last_ensured_by_instance'] ?? ''));
        if ($instanceName !== '') {
            $this->getRegistry()->touchConsumer($role, $instanceName);
        }
    }

    protected function removeRegistryRecord(string $role): void
    {
        $this->getRegistry()->removeRecord($role);
    }

    protected function displayNameForRole(string $role): string
    {
        return $role === ControlMessage::ROLE_MEMORY_SERVER ? 'Memory Service' : 'Session Server';
    }

    protected function getRegistry(): SharedStateServiceRegistry
    {
        if ($this->registry === null) {
            $this->registry = new SharedStateServiceRegistry();
        }

        return $this->registry;
    }

    protected function getInspector(): SharedSidecarInspector
    {
        if ($this->inspector === null) {
            $this->inspector = new SharedSidecarInspector();
        }

        return $this->inspector;
    }
}
