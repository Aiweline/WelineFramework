<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

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

        $sessionHost = \trim((string) ($config['host'] ?? $config['server_host'] ?? $wlsServer['host'] ?? $session['host']));
        if ($sessionHost === '') {
            $sessionHost = '127.0.0.1';
        }

        $sessionPort = (int) ($config['port'] ?? $config['server_port'] ?? $config['session_server_port'] ?? $wlsServer['port'] ?? $session['port']);
        if ($sessionPort <= 0) {
            $sessionPort = 19970;
        }

        $sessionToken = \trim((string) (
            $config['token_file_name']
            ?? $config['session_server_token_file_name']
            ?? $wlsServer['token_file_name']
            ?? $session['token_file_name']
        ));
        if ($sessionToken === '') {
            $sessionToken = 'session_server.token';
        }

        $memoryHost = \trim((string) ($config['host'] ?? $config['memory_host'] ?? $memory['host']));
        if ($memoryHost === '') {
            $memoryHost = '127.0.0.1';
        }

        $memoryPort = (int) ($config['port'] ?? $config['memory_port'] ?? $config['memory_server_port'] ?? $memory['port']);
        if ($memoryPort <= 0) {
            $memoryPort = 19971;
        }

        $memoryToken = \trim((string) (
            $config['token_file_name']
            ?? $config['memory_token_file_name']
            ?? $config['memory_server_token_file_name']
            ?? $memory['token_file_name']
        ));
        if ($memoryToken === '') {
            $memoryToken = 'memory_server.token';
        }

        return [
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
        $wlsSession = \is_array(($envConfig['wls'] ?? [])['session'] ?? null) ? $envConfig['wls']['session'] : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        $session = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];

        return [
            'host' => (string) ($wlsServer['host'] ?? $wlsSession['host'] ?? $session['server_host'] ?? '127.0.0.1'),
            'port' => (int) ($wlsServer['port'] ?? $wlsSession['port'] ?? $session['server_port'] ?? 19970),
            'token_file_name' => (string) ($wlsServer['token_file_name'] ?? $wlsSession['token_file_name'] ?? 'session_server.token'),
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
            'port' => (int) ($memory['port'] ?? 19971),
            'token_file_name' => (string) ($memory['token_file_name'] ?? 'memory_server.token'),
        ];
    }
}
