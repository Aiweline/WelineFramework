<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

/**
 * Small local-only Unix-socket sidecar that coalesces Hook refresh events and
 * keeps PHP/SQLite project-index state warm between Hook processes.
 */
final class IndexSidecar
{
    private const PROTOCOL_VERSION = 1;
    private const MAX_REQUEST_BYTES = 65_536;
    private const MAX_PATHS = 200;
    private const DEBOUNCE_MICROSECONDS = 100_000;
    private const IDLE_SECONDS = 900;

    /**
     * Return null when the caller must use the legacy one-shot refresh.
     * Return 0 when an existing sidecar accepted the request, a positive PID
     * for a newly spawned sidecar, and -1 only inside that child after its
     * server loop has ended so the Hook caller can stop child-only execution.
     *
     * @param list<string> $paths
     */
    public static function enqueue(Config $config, string $repository, array $paths = []): ?int
    {
        if (!(bool) $config->get('index.sidecar_enabled', true)
            || !function_exists('pcntl_fork')
            || !in_array('unix', stream_get_transports(), true)) {
            return null;
        }
        $request = self::request($repository, $paths);
        if ($request === null) {
            return null;
        }
        if (self::send($config, $request)) {
            return 0;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            return null;
        }
        if ($pid > 0) {
            return $pid;
        }
        if (function_exists('posix_setsid')) {
            posix_setsid();
        }
        foreach ([STDIN, STDOUT, STDERR] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        self::serve($config, $request);

        return -1;
    }

    /** @return array<string, mixed> */
    public static function status(Config $config): array
    {
        $statePath = self::statePath($config);
        $state = [];
        if (is_file($statePath)) {
            $body = file_get_contents($statePath);
            if (is_string($body)) {
                try {
                    $decoded = Json::decode($body, []);
                    $state = is_array($decoded) ? $decoded : [];
                } catch (Throwable) {
                    $state = ['status' => 'unknown', 'reason' => 'invalid_state_file'];
                }
            }
        }

        return [
            'enabled' => (bool) $config->get('index.sidecar_enabled', true),
            'supported' => function_exists('pcntl_fork') && in_array('unix', stream_get_transports(), true),
            'socket_path' => self::socketPath($config),
            'socket_present' => file_exists(self::socketPath($config)) && !is_link(self::socketPath($config)),
            'state' => $state,
        ];
    }

    /** @param list<string> $paths
     *  @return array<string, mixed>|null
     */
    private static function request(string $repository, array $paths): ?array
    {
        $repository = realpath(trim($repository));
        if ($repository === false || !is_dir($repository)) {
            return null;
        }
        $normalized = [];
        foreach (Text::uniqueStrings($paths) as $path) {
            $path = str_replace('\\', '/', trim($path));
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")
                || preg_match('~(?:^|/)\.\.(?:/|$)~D', $path) === 1) {
                return null;
            }
            $normalized[] = $path;
            if (count($normalized) > self::MAX_PATHS) {
                $normalized = [];
                break;
            }
        }

        return [
            'version' => self::PROTOCOL_VERSION,
            'request_id' => Ids::make('index-refresh'),
            'repository' => $repository,
            'paths' => $normalized,
        ];
    }

    /** @param array<string, mixed> $request */
    private static function send(Config $config, array $request): bool
    {
        $socketPath = self::socketPath($config);
        if (is_link($socketPath)) {
            return false;
        }
        $client = @stream_socket_client(
            'unix://' . $socketPath,
            $errorCode,
            $errorMessage,
            0.05,
            STREAM_CLIENT_CONNECT,
        );
        if (!is_resource($client)) {
            return false;
        }
        $message = Json::encode($request) . "\n";
        $written = 0;
        $length = strlen($message);
        while ($written < $length) {
            $count = @fwrite($client, substr($message, $written));
            if (!is_int($count) || $count < 1) {
                fclose($client);
                return false;
            }
            $written += $count;
        }
        fclose($client);

        return true;
    }

    /** @param array<string, mixed> $initial */
    private static function serve(Config $config, array $initial): void
    {
        $lockPath = self::lockPath($config);
        $lock = @fopen($lockPath, 'c+');
        if (!is_resource($lock)) {
            self::processOne($config, $initial);
            return;
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            for ($attempt = 0; $attempt < 25; ++$attempt) {
                if (self::send($config, $initial)) {
                    fclose($lock);
                    return;
                }
                usleep(20_000);
            }
            fclose($lock);
            self::processOne($config, $initial);
            return;
        }

        $socketPath = self::socketPath($config);
        if (is_link($socketPath)) {
            self::appendLog($config, 'Refusing to replace a symbolic-link sidecar socket');
            flock($lock, LOCK_UN);
            fclose($lock);
            self::processOne($config, $initial);
            return;
        }
        if (file_exists($socketPath) && !self::removeOwnedSocket($socketPath)) {
            self::appendLog($config, 'Refusing to replace an unsafe stale sidecar socket path');
            flock($lock, LOCK_UN);
            fclose($lock);
            self::processOne($config, $initial);
            return;
        }
        $server = @stream_socket_server(
            'unix://' . $socketPath,
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if (!is_resource($server)) {
            self::appendLog($config, 'Unable to start index sidecar socket: ' . $errorMessage);
            flock($lock, LOCK_UN);
            fclose($lock);
            self::processOne($config, $initial);
            return;
        }
        @chmod($socketPath, 0600);
        stream_set_blocking($server, false);
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, Json::encode(['pid' => getmypid(), 'socket' => $socketPath, 'started_at' => Clock::now()]));
        fflush($lock);

        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach (array_filter([defined('SIGTERM') ? SIGTERM : null, defined('SIGINT') ? SIGINT : null]) as $signal) {
                pcntl_signal($signal, static function () use (&$running): void {
                    $running = false;
                });
            }
        }

        $pending = [];
        $requestCount = 0;
        $batchCount = 0;
        $lastActivity = microtime(true);
        self::mergePending($pending, $initial, $requestCount);
        $store = null;
        try {
            $store = new Store($config);
            $service = new IntelligenceService($store, $config);
            self::writeState($config, [
                'status' => 'running',
                'pid' => getmypid(),
                'started_at' => Clock::now(),
                'request_count' => $requestCount,
                'batch_count' => $batchCount,
            ]);
            while ($running && ($pending !== [] || (microtime(true) - $lastActivity) < self::IDLE_SECONDS)) {
                $read = [$server];
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 100_000);
                if ($read !== []) {
                    while (($connection = @stream_socket_accept($server, 0)) !== false) {
                        stream_set_timeout($connection, 0, 200_000);
                        $line = fgets($connection, self::MAX_REQUEST_BYTES + 1);
                        fclose($connection);
                        $request = self::decodeRequest(is_string($line) ? $line : '');
                        if ($request !== null) {
                            self::mergePending($pending, $request, $requestCount);
                            $lastActivity = microtime(true);
                        }
                    }
                }

                $now = microtime(true);
                foreach (array_keys($pending) as $repository) {
                    $batch = $pending[$repository];
                    if ((float) $batch['due_at'] > $now) {
                        continue;
                    }
                    unset($pending[$repository]);
                    $input = [
                        'repository' => $repository,
                        'mode' => 'incremental',
                    ];
                    if (!$batch['full']) {
                        $input['paths'] = array_keys($batch['paths']);
                    }
                    try {
                        $service->call('index_project', $input);
                    } catch (Throwable $exception) {
                        [$message] = Redactor::string($exception->getMessage());
                        self::appendLog($config, Text::truncate($message, 2_000));
                    }
                    ++$batchCount;
                    self::writeState($config, [
                        'status' => 'running',
                        'pid' => getmypid(),
                        'last_batch_at' => Clock::now(),
                        'last_batch_mode' => $batch['full'] ? 'incremental' : 'targeted',
                        'last_batch_path_count' => $batch['full'] ? null : count($batch['paths']),
                        'request_count' => $requestCount,
                        'batch_count' => $batchCount,
                    ]);
                }
            }
            unset($service);
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            self::appendLog($config, Text::truncate($message, 2_000));
        } finally {
            if ($store instanceof Store) {
                $store->close();
            }
            fclose($server);
            self::removeOwnedSocket($socketPath);
            self::removeFallbackSocketDirectory($config, $socketPath);
            self::writeState($config, [
                'status' => 'stopped',
                'pid' => getmypid(),
                'stopped_at' => Clock::now(),
                'request_count' => $requestCount,
                'batch_count' => $batchCount,
            ]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, array{full:bool,paths:array<string, true>,due_at:float,request_count:int}> $pending
     *  @param array<string, mixed> $request
     */
    private static function mergePending(array &$pending, array $request, int &$requestCount): void
    {
        $repository = (string) $request['repository'];
        $paths = is_array($request['paths'] ?? null) ? $request['paths'] : [];
        $full = $paths === [];
        if (!isset($pending[$repository])) {
            $pending[$repository] = [
                'full' => $full,
                'paths' => [],
                'due_at' => microtime(true) + (self::DEBOUNCE_MICROSECONDS / 1_000_000),
                'request_count' => 0,
            ];
        }
        ++$requestCount;
        ++$pending[$repository]['request_count'];
        $pending[$repository]['due_at'] = microtime(true) + (self::DEBOUNCE_MICROSECONDS / 1_000_000);
        if ($pending[$repository]['full'] || $full) {
            $pending[$repository]['full'] = true;
            $pending[$repository]['paths'] = [];
            return;
        }
        foreach ($paths as $path) {
            $pending[$repository]['paths'][(string) $path] = true;
            if (count($pending[$repository]['paths']) > self::MAX_PATHS) {
                $pending[$repository]['full'] = true;
                $pending[$repository]['paths'] = [];
                break;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function decodeRequest(string $line): ?array
    {
        if ($line === '' || strlen($line) > self::MAX_REQUEST_BYTES) {
            return null;
        }
        $request = json_decode(trim($line), true);
        if (!is_array($request) || array_is_list($request)
            || (int) ($request['version'] ?? 0) !== self::PROTOCOL_VERSION) {
            return null;
        }
        $paths = $request['paths'] ?? [];
        if (!is_array($paths) || !array_is_list($paths) || count($paths) > self::MAX_PATHS) {
            return null;
        }

        return self::request((string) ($request['repository'] ?? ''), $paths);
    }

    /** @param array<string, mixed> $request */
    private static function processOne(Config $config, array $request): void
    {
        try {
            $store = new Store($config);
            try {
                $input = [
                    'repository' => (string) $request['repository'],
                    'mode' => 'incremental',
                ];
                if (($request['paths'] ?? []) !== []) {
                    $input['paths'] = $request['paths'];
                }
                (new IntelligenceService($store, $config))->call('index_project', $input);
            } finally {
                $store->close();
            }
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            self::appendLog($config, Text::truncate($message, 2_000));
        }
    }

    private static function socketPath(Config $config): string
    {
        $preferred = $config->dataDir() . '/index-sidecar.sock';
        if (strlen($preferred) <= 90) {
            return $preferred;
        }
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : substr(hash('sha256', (string) getenv('HOME')), 0, 8);
        $directory = rtrim(sys_get_temp_dir(), '/') . '/weline-mcp-' . $uid . '-' . substr(hash('sha256', $config->dataDir()), 0, 16);
        if (!is_dir($directory) && !is_link($directory)) {
            @mkdir($directory, 0700, true);
        }
        if (is_dir($directory) && !is_link($directory)) {
            @chmod($directory, 0700);
        } else {
            return $preferred;
        }

        return $directory . '/index.sock';
    }

    private static function removeFallbackSocketDirectory(Config $config, string $socketPath): void
    {
        $directory = dirname($socketPath);
        if ($directory === rtrim($config->dataDir(), '/') || is_link($directory)
            || !str_starts_with(basename($directory), 'weline-mcp-')) {
            return;
        }
        @rmdir($directory);
    }

    private static function removeOwnedSocket(string $socketPath): bool
    {
        if (!file_exists($socketPath) && !is_link($socketPath)) {
            return true;
        }
        $directory = dirname($socketPath);
        if (is_link($socketPath) || !is_dir($directory) || is_link($directory)
            || @filetype($socketPath) !== 'socket') {
            return false;
        }
        $directoryMode = @fileperms($directory);
        if (is_int($directoryMode) && ($directoryMode & 0077) !== 0) {
            return false;
        }
        $metadata = @lstat($socketPath);
        if (!is_array($metadata)) {
            return false;
        }
        if (function_exists('posix_geteuid') && (int) ($metadata['uid'] ?? -1) !== posix_geteuid()) {
            return false;
        }

        // nosemgrep: php.lang.security.unlink-use.unlink-use -- fixed same-owner socket inside a private non-symlink directory.
        return @unlink($socketPath);
    }

    private static function lockPath(Config $config): string
    {
        return $config->dataDir() . '/index-sidecar.lock';
    }

    private static function statePath(Config $config): string
    {
        return $config->dataDir() . '/index-sidecar-state.json';
    }

    /** @param array<string, mixed> $state */
    private static function writeState(Config $config, array $state): void
    {
        $path = self::statePath($config);
        @file_put_contents($path, Json::encode($state), LOCK_EX);
        if (is_file($path)) {
            @chmod($path, 0600);
        }
    }

    private static function appendLog(Config $config, string $message): void
    {
        $line = sprintf("%s index sidecar: %s\n", Clock::now(), $message);
        @file_put_contents($config->dataDir() . '/auto-index.log', $line, FILE_APPEND | LOCK_EX);
    }
}
