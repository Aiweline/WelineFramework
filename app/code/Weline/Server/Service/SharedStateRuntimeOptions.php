<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

/**
 * Resolves instance-local shared-state endpoints for child processes.
 *
 * Runtime precedence:
 * 1. Explicit child-process CLI arguments
 * 2. Persisted instance file shared_state/session/memory metadata
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
        $instanceRuntime = self::readInstanceRuntime($instanceName);

        return new self(
            self::resolveSession($args, $instanceRuntime, $envConfig),
            self::resolveMemory($args, $instanceRuntime, $envConfig),
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
     * @return array{session?: array<string, mixed>, memory?: array<string, mixed>}
     */
    private static function readInstanceRuntime(string $instanceName): array
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return [];
        }

        $instanceFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        if (!\is_file($instanceFile)) {
            return [];
        }

        $raw = @\file_get_contents($instanceFile);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            return [];
        }

        $sharedState = \is_array($data['shared_state'] ?? null) ? $data['shared_state'] : [];

        return [
            'session' => \is_array($sharedState['session'] ?? null) ? $sharedState['session'] : [],
            'memory' => \is_array($sharedState['memory'] ?? null) ? $sharedState['memory'] : [],
        ];
    }

    /**
     * @param array<string, string> $args
     * @param array{session?: array<string, mixed>, memory?: array<string, mixed>} $instanceRuntime
     * @param array<string, mixed> $envConfig
     * @return array{host: string, port: int, token_file_name: string}
     */
    private static function resolveSession(array $args, array $instanceRuntime, array $envConfig): array
    {
        $wlsSession = \is_array(($envConfig['wls'] ?? [])['session'] ?? null)
            ? $envConfig['wls']['session']
            : [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];
        $envSession = \is_array($envConfig['session'] ?? null) ? $envConfig['session'] : [];
        $instanceSession = \is_array($instanceRuntime['session'] ?? null) ? $instanceRuntime['session'] : [];

        $host = (string) (
            $args['session-host']
            ?? $instanceSession['host']
            ?? $wlsServer['host']
            ?? $wlsSession['host']
            ?? $envSession['server_host']
            ?? '127.0.0.1'
        );
        $host = \trim($host) !== '' ? \trim($host) : '127.0.0.1';

        $port = (int) (
            $args['session-port']
            ?? $instanceSession['port']
            ?? $wlsServer['port']
            ?? $wlsSession['port']
            ?? $envSession['server_port']
            ?? 19970
        );
        if ($port <= 0) {
            $port = 19970;
        }

        $tokenFileName = (string) (
            $args['session-token-file-name']
            ?? $instanceSession['token_file_name']
            ?? $wlsServer['token_file_name']
            ?? $wlsSession['token_file_name']
            ?? 'session_server.token'
        );
        if ($tokenFileName === '') {
            $tokenFileName = 'session_server.token';
        }

        return [
            'host' => $host,
            'port' => $port,
            'token_file_name' => $tokenFileName,
        ];
    }

    /**
     * @param array<string, string> $args
     * @param array{session?: array<string, mixed>, memory?: array<string, mixed>} $instanceRuntime
     * @param array<string, mixed> $envConfig
     * @return array{host: string, port: int, token_file_name: string}
     */
    private static function resolveMemory(array $args, array $instanceRuntime, array $envConfig): array
    {
        $memoryConfig = \is_array(($envConfig['wls'] ?? [])['memory_service'] ?? null)
            ? $envConfig['wls']['memory_service']
            : [];
        $instanceMemory = \is_array($instanceRuntime['memory'] ?? null) ? $instanceRuntime['memory'] : [];

        $host = (string) (
            $args['memory-host']
            ?? $instanceMemory['host']
            ?? $memoryConfig['host']
            ?? '127.0.0.1'
        );
        $host = \trim($host) !== '' ? \trim($host) : '127.0.0.1';

        $port = (int) (
            $args['memory-port']
            ?? $instanceMemory['port']
            ?? $memoryConfig['port']
            ?? 19971
        );
        if ($port <= 0) {
            $port = 19971;
        }

        $tokenFileName = (string) (
            $args['memory-token-file-name']
            ?? $instanceMemory['token_file_name']
            ?? $memoryConfig['token_file_name']
            ?? 'memory_server.token'
        );
        if ($tokenFileName === '') {
            $tokenFileName = 'memory_server.token';
        }

        return [
            'host' => $host,
            'port' => $port,
            'token_file_name' => $tokenFileName,
        ];
    }
}
