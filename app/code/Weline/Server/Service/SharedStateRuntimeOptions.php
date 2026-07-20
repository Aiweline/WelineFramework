<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Resolves instance-local shared-state endpoints for child processes.
 *
 * Runtime precedence:
 * 1. Explicit child-process CLI arguments
 * 2. Master-provided in-memory env runtime
 * 3. Disk env.php defaults
 */
class SharedStateRuntimeOptions
{
    /**
     * @param array{host: string, port: int, token_file_name: string} $session
     * @param array{host: string, port: int, token_file_name: string} $memory
     */
    public function __construct(
        private readonly array $session,
        private readonly array $memory,
    ) {}

    /**
     * @param array<int, string> $argv
     * @param array<string, mixed> $envConfig
     */
    public static function fromCliArgs(array $argv, string $instanceName, array $envConfig = []): self
    {
        $args = self::parseCliOptions($argv);

        return new self(
            self::resolveSession($args, $envConfig),
            self::resolveMemory($args, $envConfig),
        );
    }

    /**
     * @return array{host: string, port: int, token_file_name: string}
     */
    public function getSession(): array
    {
        return $this->session;
    }

    /**
     * @return array{host: string, port: int, token_file_name: string}
     */
    public function getMemory(): array
    {
        return $this->memory;
    }

    /**
     * @return array<string, mixed>
     */
    public function toEnvOverrides(): array
    {
        return [
            'session' => [
                'server_host' => $this->session['host'],
                'server_port' => $this->session['port'],
            ],
            'wls' => [
                'session' => [
                    'host' => $this->session['host'],
                    'port' => $this->session['port'],
                    'token_file_name' => $this->session['token_file_name'],
                    'wls_server' => [
                        'host' => $this->session['host'],
                        'port' => $this->session['port'],
                        'token_file_name' => $this->session['token_file_name'],
                    ],
                ],
                'memory_service' => [
                    'host' => $this->memory['host'],
                    'port' => $this->memory['port'],
                    'token_file_name' => $this->memory['token_file_name'],
                ],
                'shared_state' => [
                    'runtime' => [
                        'session' => $this->session,
                        'memory' => $this->memory,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, string> $argv
     * @return array<string, string>
     */
    private static function parseCliOptions(array $argv): array
    {
        $options = [];
        foreach ($argv as $arg) {
            if (!\is_string($arg) || !\str_starts_with($arg, '--')) {
                continue;
            }

            $eqPos = \strpos($arg, '=');
            if ($eqPos === false || $eqPos <= 2) {
                continue;
            }

            $key = \substr($arg, 2, $eqPos - 2);
            $value = \substr($arg, $eqPos + 1);
            $options[$key] = self::trimShellQuotes($value);
        }

        return $options;
    }

    private static function trimShellQuotes(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        $last = $value[\strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return \substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * @param array<string, string> $args
     * @param array<string, mixed> $envConfig
     * @return array{host: string, port: int, token_file_name: string}
     */
    private static function resolveSession(array $args, array $envConfig): array
    {
        $sharedStateRuntime = \is_array(($envConfig['wls'] ?? [])['shared_state']['runtime'] ?? null)
            ? $envConfig['wls']['shared_state']['runtime']
            : [];
        $runtimeSession = \is_array($sharedStateRuntime['session'] ?? null)
            ? $sharedStateRuntime['session']
            : [];
        $wlsSession = \is_array(($envConfig['wls'] ?? [])['session'] ?? null)
            ? $envConfig['wls']['session']
            : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        $envSession = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];

        $host = (string) (
            $args['session-host']
            ?? $runtimeSession['host']
            ?? $wlsSession['host']
            ?? $envSession['server_host']
            ?? $wlsServer['host']
            ?? '127.0.0.1'
        );
        $host = \trim($host) !== '' ? \trim($host) : '127.0.0.1';

        // 默认端口 19970 + 项目偏移量，确保多项目不冲突
        $defaultPort = 19970 + MasterProcess::getProjectPortOffset();
        $port = (int) (
            $args['session-port']
            ?? $runtimeSession['port']
            ?? $envSession['server_port']
            ?? $wlsSession['port']
            ?? $wlsServer['port']
            ?? $defaultPort
        );
        if ($port <= 0) {
            $port = $defaultPort;
        }

        $defaultTokenFileName = SharedStateRuntimeScope::defaultTokenFileNameForRole('session_server', $port);
        $tokenFileName = (string) (
            $args['session-token-file-name']
            ?? $runtimeSession['token_file_name']
            ?? $wlsSession['token_file_name']
            ?? $wlsServer['token_file_name']
            ?? $defaultTokenFileName
        );
        if ($tokenFileName === '') {
            $tokenFileName = $defaultTokenFileName;
        }

        return [
            'host' => $host,
            'port' => $port,
            'token_file_name' => $tokenFileName,
        ];
    }

    /**
     * @param array<string, string> $args
     * @param array<string, mixed> $envConfig
     * @return array{host: string, port: int, token_file_name: string}
     */
    private static function resolveMemory(array $args, array $envConfig): array
    {
        $sharedStateRuntime = \is_array(($envConfig['wls'] ?? [])['shared_state']['runtime'] ?? null)
            ? $envConfig['wls']['shared_state']['runtime']
            : [];
        $runtimeMemory = \is_array($sharedStateRuntime['memory'] ?? null)
            ? $sharedStateRuntime['memory']
            : [];
        $memoryConfig = \is_array(($envConfig['wls'] ?? [])['memory_service'] ?? null)
            ? $envConfig['wls']['memory_service']
            : [];

        $host = (string) (
            $args['memory-host']
            ?? $runtimeMemory['host']
            ?? $memoryConfig['host']
            ?? '127.0.0.1'
        );
        $host = \trim($host) !== '' ? \trim($host) : '127.0.0.1';

        // 默认端口 19971 + 项目偏移量，确保多项目不冲突
        $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
        $port = (int) (
            $args['memory-port']
            ?? $runtimeMemory['port']
            ?? $memoryConfig['port']
            ?? $defaultPort
        );
        if ($port <= 0) {
            $port = $defaultPort;
        }

        $defaultTokenFileName = SharedStateRuntimeScope::defaultTokenFileNameForRole('memory_server', $port);
        $tokenFileName = (string) (
            $args['memory-token-file-name']
            ?? $runtimeMemory['token_file_name']
            ?? $memoryConfig['token_file_name']
            ?? $defaultTokenFileName
        );
        if ($tokenFileName === '') {
            $tokenFileName = $defaultTokenFileName;
        }

        return [
            'host' => $host,
            'port' => $port,
            'token_file_name' => $tokenFileName,
        ];
    }
}
