<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Server\Log\WlsLogger;

class SharedStateRuntimeResolver
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array{
     *   session: array{host:string, port:int, token_file_name:string},
     *   memory: array{host:string, port:int, token_file_name:string}
     * }
     */
    public function resolve(array $config = [], array $envConfig = [], ?string $instanceName = null): array
    {
        $resolveStartedAt = \microtime(true);
        if ($envConfig === []) {
            $loaded = Env::getInstance()->getConfig();
            $envConfig = \is_array($loaded) ? $loaded : [];
        }

        $instanceName ??= $this->resolveCurrentInstanceName();
        $base = $instanceName !== null
            ? SharedStateRuntimeOptions::fromCliArgs([], $instanceName, $envConfig)
            : new SharedStateRuntimeOptions(
                $this->resolveFallbackSession($envConfig),
                $this->resolveFallbackMemory($envConfig)
            );

        $session = $base->getSession();
        $memory = $base->getMemory();

        $wlsServer = \is_array($config['wls_server'] ?? null) ? $config['wls_server'] : [];

        $sessionHost = \trim((string) ($config['session_host'] ?? $config['session_server_host'] ?? $session['host']));
        if ($sessionHost === '') {
            $sessionHost = '127.0.0.1';
        }

        $sessionPort = (int) ($config['session_server_port'] ?? $config['session_port'] ?? $session['port']);
        if ($sessionPort <= 0) {
            $probeRuntime = $this->probeRuntimeWithTelemetry(
                'session_server',
                [],
                $envConfig,
                'session_port_missing',
                $instanceName
            );
            $sessionPort = (int) ($probeRuntime['port'] ?? 0);
            // 如果探测失败,使用项目偏移量计算默认端口
            if ($sessionPort <= 0) {
                $sessionPort = 19970 + MasterProcess::getProjectPortOffset();
            }
        }

        $sessionTokenExplicit = \array_key_exists('session_token_file_name', $config)
            || \array_key_exists('session_server_token_file_name', $config);
        $sessionToken = \trim((string) (
            $config['session_token_file_name']
            ?? $config['session_server_token_file_name']
            ?? $session['token_file_name']
        ));
        if (!$sessionTokenExplicit && ($sessionToken === '' || $sessionToken === 'session_server.token')) {
            $probeRuntime = $this->probeRuntimeWithTelemetry(
                'session_server',
                $config,
                $envConfig,
                'session_token_default',
                $instanceName,
                $sessionPort
            );
            $probeToken = \trim((string)($probeRuntime['token_file_name'] ?? ''));
            if ($probeToken !== '') {
                $sessionToken = $probeToken;
            }
        }
        if ($sessionToken === '') {
            $sessionToken = 'session_server.token';
        }

        $memoryHost = \trim((string) ($config['memory_host'] ?? $config['memory_server_host'] ?? $memory['host']));
        if ($memoryHost === '') {
            $memoryHost = '127.0.0.1';
        }

        $memoryPort = (int) ($config['memory_server_port'] ?? $config['memory_port'] ?? $memory['port']);
        if ($memoryPort <= 0) {
            $probeRuntime = $this->probeRuntimeWithTelemetry(
                'memory_server',
                [],
                $envConfig,
                'memory_port_missing',
                $instanceName
            );
            $memoryPort = (int) ($probeRuntime['port'] ?? 0);
            // 如果探测失败,使用项目偏移量计算默认端口
            if ($memoryPort <= 0) {
                $memoryPort = 19971 + MasterProcess::getProjectPortOffset();
            }
        }

        $memoryTokenExplicit = \array_key_exists('memory_token_file_name', $config)
            || \array_key_exists('memory_server_token_file_name', $config);
        $memoryToken = \trim((string) (
            $config['memory_token_file_name']
            ?? $config['memory_server_token_file_name']
            ?? $memory['token_file_name']
        ));
        if (!$memoryTokenExplicit && ($memoryToken === '' || $memoryToken === 'memory_server.token')) {
            $probeRuntime = $this->probeRuntimeWithTelemetry(
                'memory_server',
                $config,
                $envConfig,
                'memory_token_default',
                $instanceName,
                $memoryPort
            );
            $probeToken = \trim((string)($probeRuntime['token_file_name'] ?? ''));
            if ($probeToken !== '') {
                $memoryToken = $probeToken;
            }
        }
        if ($memoryToken === '') {
            $memoryToken = 'memory_server.token';
        }

        $runtime = [
            'session' => [
                'host' => $sessionHost,
                'port' => $sessionPort,
                'token_file_name' => $sessionToken,
            ],
            'memory' => [
                'host' => $memoryHost,
                'port' => $memoryPort,
                'token_file_name' => $memoryToken,
            ],
        ];

        $elapsedMs = \max(0, (int) \round((\microtime(true) - $resolveStartedAt) * 1000));
        WlsLogger::info_(
            '[SharedStateRuntimeResolver] resolve instance=' . ($instanceName ?? 'auto')
            . ' elapsed=' . $elapsedMs . 'ms'
            . ' session=' . $runtime['session']['host'] . ':' . $runtime['session']['port'] . '/' . $runtime['session']['token_file_name']
            . ' memory=' . $runtime['memory']['host'] . ':' . $runtime['memory']['port'] . '/' . $runtime['memory']['token_file_name']
        );

        return $runtime;
    }

    private function resolveCurrentInstanceName(): ?string
    {
        foreach ([
            \getenv('WLS_INSTANCE'),
            \getenv('WLS_INSTANCE_NAME'),
            $_ENV['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
        ] as $candidate) {
            if (\is_string($candidate) && \trim($candidate) !== '') {
                return \trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $envConfig
     * @return array{host:string, port:int, token_file_name:string}
     */
    private function resolveFallbackSession(array $envConfig): array
    {
        $runtimeSession = \is_array(($envConfig['wls'] ?? [])['shared_state']['runtime']['session'] ?? null)
            ? $envConfig['wls']['shared_state']['runtime']['session']
            : [];
        $wlsSession = \is_array(($envConfig['wls'] ?? [])['session'] ?? null) ? $envConfig['wls']['session'] : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        $session = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];

        return [
            'host' => (string) ($runtimeSession['host'] ?? $wlsSession['host'] ?? $session['server_host'] ?? $wlsServer['host'] ?? '127.0.0.1'),
            'port' => (int) ($runtimeSession['port'] ?? $session['server_port'] ?? $wlsSession['port'] ?? $wlsServer['port'] ?? (19970 + MasterProcess::getProjectPortOffset())),
            'token_file_name' => (string) ($runtimeSession['token_file_name'] ?? $wlsSession['token_file_name'] ?? $wlsServer['token_file_name'] ?? 'session_server.token'),
        ];
    }

    /**
     * @param array<string, mixed> $envConfig
     * @return array{host:string, port:int, token_file_name:string}
     */
    private function resolveFallbackMemory(array $envConfig): array
    {
        $memory = \is_array(($envConfig['wls'] ?? [])['memory_service'] ?? null)
            ? $envConfig['wls']['memory_service']
            : [];

        return [
            'host' => (string) ($memory['host'] ?? '127.0.0.1'),
            'port' => (int) ($memory['port'] ?? (19971 + MasterProcess::getProjectPortOffset())),
            'token_file_name' => (string) ($memory['token_file_name'] ?? 'memory_server.token'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    protected function probeRuntime(string $role, array $config, array $envConfig): array
    {
        $probe = (new SharedStateServiceManager())->probe($role, $config, $envConfig);
        return \is_array($probe['runtime'] ?? null) ? $probe['runtime'] : [];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    private function probeRuntimeWithTelemetry(
        string $role,
        array $config,
        array $envConfig,
        string $reason,
        ?string $instanceName = null,
        ?int $resolvedPort = null
    ): array {
        $startedAt = \microtime(true);
        $runtime = $this->probeRuntime($role, $config, $envConfig);
        $elapsedMs = \max(0, (int) \round((\microtime(true) - $startedAt) * 1000));

        WlsLogger::info_(
            '[SharedStateRuntimeResolver] probe role=' . $role
            . ' reason=' . $reason
            . ' instance=' . ($instanceName ?? 'auto')
            . ($resolvedPort !== null ? ' port=' . $resolvedPort : '')
            . ' elapsed=' . $elapsedMs . 'ms'
            . ' result_port=' . (int) ($runtime['port'] ?? 0)
            . ' result_token=' . (string) ($runtime['token_file_name'] ?? '')
        );

        return $runtime;
    }
}
