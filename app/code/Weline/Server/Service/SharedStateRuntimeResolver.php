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
        $envWls = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $envWlsSession = \is_array($envWls['session'] ?? null) ? $envWls['session'] : [];
        $envWlsServer = \is_array($envWlsSession['wls_server'] ?? null)
            ? $envWlsSession['wls_server']
            : [];
        $envMemory = \is_array($envWls['memory_service'] ?? null) ? $envWls['memory_service'] : [];

        $sessionHost = \trim((string) ($config['session_host'] ?? $config['session_server_host'] ?? $session['host']));
        if ($sessionHost === '') {
            $sessionHost = '127.0.0.1';
        }

        $sessionPort = (int) ($config['session_server_port'] ?? $session['port']);
        if ($sessionPort <= 0) {
            $sessionPort = 19970 + MasterProcess::getProjectPortOffset();
            // 如果探测失败,使用项目偏移量计算默认端口
            if ($sessionPort <= 0) {
                $sessionPort = 19970;
            }
        }

        $sessionTokenExplicit = \array_key_exists('session_token_file_name', $config)
            || \array_key_exists('session_server_token_file_name', $config)
            || \trim((string)($envWlsSession['token_file_name'] ?? '')) !== ''
            || \trim((string)($envWlsServer['token_file_name'] ?? '')) !== '';
        $sessionToken = \trim((string) (
            $config['session_token_file_name']
            ?? $config['session_server_token_file_name']
            ?? $session['token_file_name']
        ));
        if (!$sessionTokenExplicit && (
            $sessionToken === ''
            || $sessionToken === 'session_server.token'
            || $sessionToken === SharedStateRuntimeScope::scopeDefaultFileName('session_server.token')
            || $sessionToken === SharedStateRuntimeScope::defaultTokenFileNameForRole(
                'session_server',
                (int)($session['port'] ?? 0)
            )
        )) {
            $sessionToken = $this->resolveImplicitTokenFileName('session_server', $sessionPort);
        }
        if ($sessionToken === '') {
            $sessionToken = SharedStateRuntimeScope::defaultTokenFileNameForRole('session_server', $sessionPort);
        }

        $memoryHost = \trim((string) ($config['memory_host'] ?? $config['memory_server_host'] ?? $memory['host']));
        if ($memoryHost === '') {
            $memoryHost = '127.0.0.1';
        }

        $memoryPort = (int) ($config['memory_server_port'] ?? $memory['port']);
        if ($memoryPort <= 0) {
            $memoryPort = 19971 + MasterProcess::getProjectPortOffset();
            // 如果探测失败,使用项目偏移量计算默认端口
            if ($memoryPort <= 0) {
                $memoryPort = 19971;
            }
        }

        $memoryTokenExplicit = \array_key_exists('memory_token_file_name', $config)
            || \array_key_exists('memory_server_token_file_name', $config)
            || \trim((string)($envMemory['token_file_name'] ?? '')) !== '';
        $memoryToken = \trim((string) (
            $config['memory_token_file_name']
            ?? $config['memory_server_token_file_name']
            ?? $memory['token_file_name']
        ));
        if (!$memoryTokenExplicit && (
            $memoryToken === ''
            || $memoryToken === 'memory_server.token'
            || $memoryToken === SharedStateRuntimeScope::scopeDefaultFileName('memory_server.token')
            || $memoryToken === SharedStateRuntimeScope::defaultTokenFileNameForRole(
                'memory_server',
                (int)($memory['port'] ?? 0)
            )
        )) {
            $memoryToken = $this->resolveImplicitTokenFileName('memory_server', $memoryPort);
        }
        if ($memoryToken === '') {
            $memoryToken = SharedStateRuntimeScope::defaultTokenFileNameForRole('memory_server', $memoryPort);
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

        $port = (int) ($runtimeSession['port'] ?? $session['server_port'] ?? $wlsSession['port'] ?? $wlsServer['port'] ?? (19970 + MasterProcess::getProjectPortOffset()));

        return [
            'host' => (string) ($runtimeSession['host'] ?? $wlsSession['host'] ?? $session['server_host'] ?? $wlsServer['host'] ?? '127.0.0.1'),
            'port' => $port,
            'token_file_name' => (string) (
                $runtimeSession['token_file_name']
                ?? $wlsSession['token_file_name']
                ?? $wlsServer['token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole('session_server', $port)
            ),
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

        $port = (int) ($memory['port'] ?? (19971 + MasterProcess::getProjectPortOffset()));

        return [
            'host' => (string) ($memory['host'] ?? '127.0.0.1'),
            'port' => $port,
            'token_file_name' => (string) (
                $memory['token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole('memory_server', $port)
            ),
        ];
    }

    private function resolveImplicitTokenFileName(string $role, int $port): string
    {
        return SharedStateRuntimeScope::defaultTokenFileNameForRole($role, $port);
    }

}
