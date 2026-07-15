<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Verifies an explicitly configured compatibility protocol edge before WLS
 * creates managed processes. The former in-repository Go engine is removed;
 * native HTTP/2/3 remains fail-closed until a WLS Transport Adapter exists.
 */
final class ProtocolEdgeDependencyBootstrapper
{
    private const INSTALL_TIMEOUT_SECONDS = 900;
    private const INSTALL_LOCK_TIMEOUT_SECONDS = 30;
    private const PROBE_TIMEOUT_SECONDS = 20;
    private const HTTP3_LIVE_PROBE_TIMEOUT_SECONDS = 5;
    private const MAX_OUTPUT_BYTES = 1048576;
    private const WINDOWS_CADDY_VERSION = '2.11.4';
    private const WINDOWS_CADDY_BASE_URL = 'https://github.com/caddyserver/caddy/releases/download/v'
        . self::WINDOWS_CADDY_VERSION;
    private const WINDOWS_CADDY_PACKAGE_SHA256 = [
        'amd64' => '1708333f79e274c7697285afe6d592ab39314e0b131e9ec6bea08ad27df62ebf',
        'arm64' => 'c7f16da93728f61455f77c04eac1ff4de06a38da281ed6d3dcbfae795be2a936',
    ];

    /**
     * @param array<int|string, mixed> $args
     * @param array<string, mixed> $config
     * @return array{status:string,message:string,binary:string,output?:string}
     */
    public function ensureAvailable(
        array $args,
        array $config,
        HttpProtocolSelection $selection,
    ): array {
        if (!$selection->isProtocolEdgeEnabled()) {
            return [
                'status' => 'disabled',
                'message' => (string)__('HTTP 协议边缘未启用。'),
                'binary' => '',
            ];
        }

        if ($selection->isNativeProtocolEdge()) {
            return [
                'status' => 'failed',
                'message' => (string)__('内置 Go 协议边缘已移除；HTTP/2/3 将在 WLS 原生 Transport Adapter 完成前保持关闭。'),
                'binary' => '',
            ];
        }

        $engineName = 'Caddy compatibility edge';

        $binary = ProtocolEdgeRuntime::resolveBinary($config);
        $probe = $binary !== '' ? $this->probe($binary, $selection) : null;
        if (\is_array($probe) && $probe['success']) {
            return [
                'status' => 'ready',
                'message' => (string)__('%{1} 已验证：%{2}', [$engineName, $probe['version']]),
                'binary' => $binary,
            ];
        }

        if ($this->hasFlag($args, ['no-auto-deps', 'no-auto-dependencies'])) {
            return [
                'status' => 'failed',
                'message' => (string)__('HTTP/2/HTTP/3 需要 %{1}，但 --no-auto-deps 禁止自动安装。', [$engineName]),
                'binary' => '',
                'output' => (string)($probe['output'] ?? ''),
            ];
        }

        $lock = $this->acquireInstallLock();
        if (!\is_resource($lock)) {
            return [
                'status' => 'failed',
                'message' => (string)__('无法获取 HTTP 协议边缘依赖安装锁。'),
                'binary' => '',
            ];
        }

        try {
            // Another concurrent start may have completed installation while
            // this process was waiting for the lock.
            $binary = ProtocolEdgeRuntime::resolveBinary($config);
            $probe = $binary !== '' ? $this->probe($binary, $selection) : null;
            if (\is_array($probe) && $probe['success']) {
                return [
                    'status' => 'ready',
                    'message' => (string)__('其他 WLS 启动进程已安装并验证 HTTP 协议边缘。'),
                    'binary' => $binary,
                ];
            }

            $install = $this->installForCurrentPlatform($selection);
            $binary = ProtocolEdgeRuntime::resolveBinary($config);
            $probe = $binary !== '' ? $this->probe($binary, $selection) : null;
            if (!$install['success'] || !\is_array($probe) || !$probe['success']) {
                $output = \trim((string)$install['output'] . PHP_EOL . (string)($probe['output'] ?? ''));
                return [
                    'status' => 'failed',
                    'message' => (string)__(
                        '%{1} 自动安装后仍无法验证 HTTP/3/HTTP/2 能力；WLS 已在创建子进程前停止。',
                        [$engineName]
                    ),
                    'binary' => '',
                    'output' => $this->tail($output),
                ];
            }

            return [
                'status' => 'installed',
                'message' => (string)__('%{1} 已自动安装并验证：%{2}', [$engineName, $probe['version']]),
                'binary' => $binary,
                'output' => $this->tail((string)$install['output']),
            ];
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @return array{success:bool,version:string,output:string}
     */
    public function probe(string $binary, HttpProtocolSelection $selection): array
    {
        if ($selection->isNativeProtocolEdge()) {
            return [
                'success' => false,
                'version' => '',
                'output' => 'The in-repository native protocol edge has been removed.',
            ];
        }

        $version = $this->run([$binary, 'version'], self::PROBE_TIMEOUT_SECONDS);
        if (!$version['success'] || \preg_match('/\bv?2\.[0-9]+\.[0-9]+\b/', $version['output']) !== 1) {
            return [
                'success' => false,
                'version' => '',
                'output' => $this->tail($version['output']),
            ];
        }

        $modules = $this->run([$binary, 'list-modules'], self::PROBE_TIMEOUT_SECONDS);
        $hasReverseProxy = $modules['success']
            && \str_contains($modules['output'], 'http.handlers.reverse_proxy');
        $hasPersistentSessionTickets = !$selection->tlsSessionResumption
            || ($modules['success']
                && \str_contains($modules['output'], 'tls.stek.distributed')
                && \str_contains($modules['output'], 'caddy.storage.file_system'));
        $hasHttp3 = true;
        $buildInfoOutput = '';
        if ($selection->supports(HttpProtocolSelection::HTTP_3)) {
            $buildInfo = $this->run([$binary, 'build-info'], self::PROBE_TIMEOUT_SECONDS);
            $buildInfoOutput = $buildInfo['output'];
            // Distribution packages may strip dependency metadata, so
            // build-info cannot be the HTTP/3 authority. Start a real bounded
            // TCP+UDP listener on port 0 and require Caddy to acknowledge h3.
            $http3Probe = $this->probeHttp3Listener($binary);
            $hasHttp3 = $http3Probe['success'];
            $buildInfoOutput .= PHP_EOL . $http3Probe['output'];
        }

        return [
            'success' => $hasReverseProxy && $hasPersistentSessionTickets && $hasHttp3,
            'version' => \trim($version['output']),
            'output' => $this->tail($modules['output'] . PHP_EOL . $buildInfoOutput),
        ];
    }

    /**
     * @return array{success:bool,output:string}
     */
    private function probeHttp3Listener(string $binary): array
    {
        if (!\function_exists('proc_open')) {
            return ['success' => false, 'output' => 'proc_open is unavailable for the HTTP/3 live probe.'];
        }

        $directory = \rtrim(\sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'wls-caddy-http3-probe-' . \bin2hex(\random_bytes(6));
        if (!@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            return ['success' => false, 'output' => 'Unable to create the HTTP/3 live-probe directory.'];
        }

        $configPath = $directory . DIRECTORY_SEPARATOR . 'caddy.json';
        $config = [
            'admin' => ['disabled' => true],
            'apps' => [
                'http' => [
                    'servers' => [
                        'probe' => [
                            'listen' => ['127.0.0.1:0'],
                            'protocols' => ['h1', 'h2', 'h3'],
                            'automatic_https' => ['disable_redirects' => true],
                            'tls_connection_policies' => [(object)[]],
                            'routes' => [[
                                'handle' => [[
                                    'handler' => 'static_response',
                                    'status_code' => 204,
                                ]],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
        $payload = \json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        if (@\file_put_contents($configPath, $payload) === false) {
            @\rmdir($directory);
            return ['success' => false, 'output' => 'Unable to write the HTTP/3 live-probe configuration.'];
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $environment = \getenv();
        $environment = \is_array($environment) ? $environment : [];
        $environment['XDG_CONFIG_HOME'] = $directory . DIRECTORY_SEPARATOR . 'config';
        $environment['XDG_DATA_HOME'] = $directory . DIRECTORY_SEPARATOR . 'data';
        if (PHP_OS_FAMILY === 'Windows') {
            $environment['APPDATA'] = $environment['XDG_CONFIG_HOME'];
            $environment['LOCALAPPDATA'] = $environment['XDG_DATA_HOME'];
        }

        $process = @\proc_open([
            $binary,
            'run',
            '--config',
            $configPath,
        ], [
            0 => ['file', $null, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $directory, $environment, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            @\unlink($configPath);
            @\rmdir($directory);
            return ['success' => false, 'output' => 'Unable to launch Caddy for the HTTP/3 live probe.'];
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                \stream_set_blocking($pipes[$index], false);
            }
        }

        $output = '';
        $listenerReady = false;
        $serverReady = false;
        $deadlineNanoseconds = \hrtime(true)
            + (self::HTTP3_LIVE_PROBE_TIMEOUT_SECONDS * 1_000_000_000);
        while (\hrtime(true) < $deadlineNanoseconds) {
            $read = [];
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && \is_resource($pipes[$index]) && !\feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                @\stream_select($read, $write, $except, 0, 100000);
                foreach ($read as $pipe) {
                    $chunk = (string)(@\fread($pipe, 8192) ?: '');
                    if ($chunk !== '' && \strlen($output) < self::MAX_OUTPUT_BYTES) {
                        $output .= \substr($chunk, 0, self::MAX_OUTPUT_BYTES - \strlen($output));
                    }
                }
            }

            $listenerReady = $listenerReady || \str_contains($output, 'enabling HTTP/3 listener');
            $serverReady = $serverReady || \str_contains($output, 'serving initial configuration');
            if ($listenerReady && $serverReady) {
                break;
            }
            $status = \proc_get_status($process);
            if (!($status['running'] ?? false)) {
                break;
            }
        }

        $status = \proc_get_status($process);
        if ($status['running'] ?? false) {
            @\proc_terminate($process);
            $terminateDeadlineNanoseconds = \hrtime(true) + 1_000_000_000;
            do {
                SchedulerSystem::usleep(50000);
                $status = \proc_get_status($process);
            } while (($status['running'] ?? false) && \hrtime(true) < $terminateDeadlineNanoseconds);
            if (($status['running'] ?? false) && PHP_OS_FAMILY !== 'Windows') {
                @\proc_terminate($process, 9);
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                $chunk = (string)(@\stream_get_contents($pipes[$index]) ?: '');
                if ($chunk !== '' && \strlen($output) < self::MAX_OUTPUT_BYTES) {
                    $output .= \substr($chunk, 0, self::MAX_OUTPUT_BYTES - \strlen($output));
                }
                @\fclose($pipes[$index]);
            }
        }
        @\proc_close($process);

        $removeTree = static function (string $path) use (&$removeTree): void {
            if (\is_dir($path) && !\is_link($path)) {
                foreach ((array)@\scandir($path) as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
                @\rmdir($path);
                return;
            }
            @\unlink($path);
        };
        $removeTree($directory);

        return [
            'success' => $listenerReady && $serverReady,
            'output' => $this->tail($output),
        ];
    }

    /**
     * @return array{success:bool,output:string}
     */
    private function installForCurrentPlatform(HttpProtocolSelection $selection): array
    {
        if ($selection->isNativeProtocolEdge()) {
            return [
                'success' => false,
                'output' => 'Native HTTP/2/3 is unavailable until the WLS Transport Adapter is implemented.',
            ];
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $brew = $this->findExecutable('brew');
            if ($brew === '') {
                return ['success' => false, 'output' => 'Homebrew is required to install Caddy on macOS.'];
            }

            return $this->run([$brew, 'install', 'caddy'], self::INSTALL_TIMEOUT_SECONDS);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Prefer the project-local, fixed-digest package. It needs no
            // administrator rights and selects native ARM64 on Windows ARM,
            // instead of relying on an x64 package-manager shim.
            $verified = $this->installVerifiedWindowsCaddy($selection);
            if ($verified['success']) {
                return $verified;
            }
            $fallbackOutput = [$verified['output']];

            $winget = $this->findExecutable('winget');
            if ($winget !== '') {
                $install = $this->run([
                    $winget,
                    'install',
                    '--id',
                    'CaddyServer.Caddy',
                    '--exact',
                    '--silent',
                    '--accept-package-agreements',
                    '--accept-source-agreements',
                ], self::INSTALL_TIMEOUT_SECONDS);
                if ($install['success']) {
                    return $install;
                }
                $fallbackOutput[] = $install['output'];
            }
            $choco = $this->findExecutable('choco');
            if ($choco !== '') {
                $install = $this->run([$choco, 'install', 'caddy', '-y'], self::INSTALL_TIMEOUT_SECONDS);
                if ($install['success']) {
                    return $install;
                }
                $fallbackOutput[] = $install['output'];
            }
            $scoop = $this->findExecutable('scoop');
            if ($scoop !== '') {
                $install = $this->run([$scoop, 'install', 'caddy'], self::INSTALL_TIMEOUT_SECONDS);
                if ($install['success']) {
                    return $install;
                }
                $fallbackOutput[] = $install['output'];
            }

            return [
                'success' => false,
                'output' => $this->tail(\implode(PHP_EOL, \array_filter($fallbackOutput, 'strlen'))),
            ];
        }

        if (PHP_OS_FAMILY === 'Linux') {
            foreach ([
                ['apt-get', ['install', '-y', 'caddy']],
                ['dnf', ['install', '-y', 'caddy']],
                ['yum', ['install', '-y', 'caddy']],
                ['apk', ['add', 'caddy']],
            ] as [$managerName, $arguments]) {
                $manager = $this->findExecutable($managerName);
                if ($manager === '') {
                    continue;
                }
                $command = [$manager, ...$arguments];
                if (\function_exists('posix_geteuid') && (int)\posix_geteuid() !== 0) {
                    $sudo = $this->findExecutable('sudo');
                    if ($sudo === '') {
                        return [
                            'success' => false,
                            'output' => 'Installing Caddy requires root or passwordless sudo for the detected package manager.',
                        ];
                    }
                    $command = [$sudo, '-n', ...$command];
                }

                return $this->run($command, self::INSTALL_TIMEOUT_SECONDS);
            }

            return ['success' => false, 'output' => 'No supported Linux package manager was found for Caddy installation.'];
        }

        return ['success' => false, 'output' => 'This platform has no verified Caddy auto-installer.'];
    }

    /**
     * Install one immutable official Windows release and validate its complete
     * HTTP/1.1 + HTTP/2 + HTTP/3 feature set before publishing it atomically.
     *
     * @return array{success:bool,output:string}
     */
    private function installVerifiedWindowsCaddy(HttpProtocolSelection $selection): array
    {
        if (!\class_exists(\ZipArchive::class)) {
            return ['success' => false, 'output' => 'Verified Windows Caddy installation requires ZipArchive.'];
        }

        $architecture = $this->windowsNativeArchitecture();
        $expectedHash = self::WINDOWS_CADDY_PACKAGE_SHA256[$architecture] ?? '';
        if ($expectedHash === '') {
            return [
                'success' => false,
                'output' => 'No fixed Caddy digest is available for Windows architecture: ' . $architecture,
            ];
        }

        $packageName = 'caddy_' . self::WINDOWS_CADDY_VERSION . '_windows_' . $architecture . '.zip';
        $url = self::WINDOWS_CADDY_BASE_URL . '/' . $packageName;
        $managedBinary = ProtocolEdgeRuntime::managedBinaryPath();
        $directory = \dirname($managedBinary);
        if (!\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            return ['success' => false, 'output' => 'Unable to create the managed Caddy runtime directory.'];
        }
        if (!\is_writable($directory)) {
            return ['success' => false, 'output' => 'Managed Caddy runtime directory is not writable: ' . $directory];
        }

        $token = \bin2hex(\random_bytes(8));
        $archive = $directory . DIRECTORY_SEPARATOR . $packageName . '.download-' . $token;
        $candidate = $managedBinary . '.install-' . $token . '.exe';
        try {
            $download = $this->downloadVerifiedFile($url, $archive, $expectedHash);
            if (!$download['success']) {
                return $download;
            }

            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                return ['success' => false, 'output' => 'Unable to open the verified Caddy archive.'];
            }
            $entryName = '';
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = (string)$zip->getNameIndex($index);
                if (\strtolower(\basename(\str_replace('\\', '/', $entry))) === 'caddy.exe') {
                    $entryName = $entry;
                    break;
                }
            }
            if ($entryName === '') {
                $zip->close();
                return ['success' => false, 'output' => 'Verified Caddy archive does not contain caddy.exe.'];
            }

            $source = $zip->getStream($entryName);
            $destination = @\fopen($candidate, 'xb');
            if (!\is_resource($source) || !\is_resource($destination)) {
                if (\is_resource($source)) {
                    @\fclose($source);
                }
                if (\is_resource($destination)) {
                    @\fclose($destination);
                }
                $zip->close();
                return ['success' => false, 'output' => 'Unable to extract the verified Caddy executable.'];
            }
            try {
                $bytes = \stream_copy_to_stream($source, $destination);
                @\fflush($destination);
            } finally {
                @\fclose($source);
                @\fclose($destination);
                $zip->close();
            }
            if (!\is_int($bytes) || $bytes <= 0) {
                return ['success' => false, 'output' => 'Verified Caddy executable is empty.'];
            }
            @\chmod($candidate, 0755);

            $probe = $this->probe($candidate, $selection);
            if (!$probe['success']) {
                return [
                    'success' => false,
                    'output' => 'Downloaded Caddy failed the runtime capability probe: ' . $probe['output'],
                ];
            }

            $publish = $this->publishWindowsBinary($candidate, $managedBinary, $token);
            if (!$publish['success']) {
                return $publish;
            }

            return [
                'success' => true,
                'output' => 'Installed official Caddy ' . self::WINDOWS_CADDY_VERSION
                    . ' for Windows ' . $architecture . ' with verified SHA-256; ' . $probe['version'],
            ];
        } finally {
            // Installer-owned fixed directory and random-suffix files only.
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            @\unlink($archive);
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            @\unlink($candidate);
        }
    }

    private function windowsNativeArchitecture(): string
    {
        // Windows on ARM can run an x64 PHP binary without setting
        // PROCESSOR_ARCHITEW6432. In that case PROCESSOR_ARCHITECTURE and
        // php_uname() describe the emulated process, while the processor
        // identifier still describes the native ARM host.
        $processorIdentifier = \strtolower(\trim((string)\getenv('PROCESSOR_IDENTIFIER')));
        if (\str_contains($processorIdentifier, 'arm')) {
            return 'arm64';
        }
        $architecture = \strtolower(\trim((string)(
            \getenv('PROCESSOR_ARCHITEW6432') ?: \getenv('PROCESSOR_ARCHITECTURE') ?: ''
        )));
        if (\str_contains($architecture, 'arm64')) {
            return 'arm64';
        }
        if (\str_contains($architecture, 'amd64') || \str_contains($architecture, 'x86_64')) {
            return 'amd64';
        }

        return $architecture !== '' ? $architecture : 'unknown';
    }

    /** @return array{success:bool,output:string} */
    private function downloadVerifiedFile(string $url, string $target, string $expectedHash): array
    {
        $context = \stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 120,
                'user_agent' => 'WelineFramework-WLS/' . PHP_VERSION,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $source = @\fopen($url, 'rb', false, $context);
        $destination = @\fopen($target, 'xb');
        if (!\is_resource($source) || !\is_resource($destination)) {
            if (\is_resource($source)) {
                @\fclose($source);
            }
            if (\is_resource($destination)) {
                @\fclose($destination);
            }
            return ['success' => false, 'output' => 'Unable to download the verified dependency package over HTTPS.'];
        }
        try {
            $bytes = \stream_copy_to_stream($source, $destination);
            @\fflush($destination);
        } finally {
            @\fclose($source);
            @\fclose($destination);
        }
        if (!\is_int($bytes) || $bytes <= 0) {
            return ['success' => false, 'output' => 'Verified dependency package download was empty.'];
        }

        $actualHash = \hash_file('sha256', $target);
        if (!\is_string($actualHash) || !\hash_equals($expectedHash, \strtolower($actualHash))) {
            return ['success' => false, 'output' => 'Dependency package SHA-256 mismatch; installation refused.'];
        }

        return ['success' => true, 'output' => 'Dependency package digest verified.'];
    }

    /** @return array{success:bool,output:string} */
    private function publishWindowsBinary(string $candidate, string $target, string $token): array
    {
        $backup = '';
        if (\is_file($target)) {
            $backup = $target . '.backup-' . \gmdate('YmdHis') . '-' . $token;
            if (!@\rename($target, $backup)) {
                return ['success' => false, 'output' => 'Unable to preserve the existing managed Caddy binary.'];
            }
        }
        if (@\rename($candidate, $target)) {
            return ['success' => true, 'output' => 'Managed Caddy binary published atomically.'];
        }
        if ($backup !== '' && !\is_file($target)) {
            @\rename($backup, $target);
        }

        return ['success' => false, 'output' => 'Unable to atomically publish the managed Caddy binary.'];
    }

    private function findExecutable(string $name): string
    {
        $binaryName = PHP_OS_FAMILY === 'Windows' && !\str_ends_with(\strtolower($name), '.exe')
            ? $name . '.exe'
            : $name;
        foreach (\array_filter(\explode(PATH_SEPARATOR, (string)(\getenv('PATH') ?: '')), 'strlen') as $directory) {
            $candidate = \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binaryName;
            if (ProtocolEdgeRuntime::isRunnableBinary($candidate)) {
                return $candidate;
            }
        }
        foreach (['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin'] as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $binaryName;
            if (ProtocolEdgeRuntime::isRunnableBinary($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return resource|null
     */
    private function acquireInstallLock(): mixed
    {
        $directory = Env::VAR_DIR . 'server' . DS . 'locks';
        if (!\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            return null;
        }
        $handle = @\fopen($directory . DS . 'protocol_edge_dependency_install.lock', 'c+');
        if (!\is_resource($handle)) {
            return null;
        }

        $deadline = \microtime(true) + self::INSTALL_LOCK_TIMEOUT_SECONDS;
        do {
            if (@\flock($handle, \LOCK_EX | \LOCK_NB)) {
                return $handle;
            }
            if (\microtime(true) >= $deadline) {
                break;
            }
            SchedulerSystem::usleep(50000);
        } while (true);

        @\fclose($handle);

        return null;
    }

    /**
     * @param list<string> $command
     * @return array{success:bool,exit_code:int,output:string,timed_out:bool}
     */
    private function run(
        array $command,
        int $timeoutSeconds,
        ?string $workingDirectory = null,
        ?array $environment = null,
    ): array
    {
        if (!\function_exists('proc_open')) {
            return ['success' => false, 'exit_code' => 127, 'output' => 'proc_open is unavailable.', 'timed_out' => false];
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @\proc_open($command, [
            0 => ['file', $null, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory ?? (\defined('BP') ? BP : null), $environment, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            return ['success' => false, 'exit_code' => 126, 'output' => 'Unable to launch dependency process.', 'timed_out' => false];
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                \stream_set_blocking($pipes[$index], false);
            }
        }
        $startedAt = \microtime(true);
        $output = '';
        $timedOut = false;
        $lastStatus = ['running' => true, 'exitcode' => -1];
        while (true) {
            $lastStatus = \proc_get_status($process);
            $read = [];
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && \is_resource($pipes[$index]) && !\feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                @\stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $pipe) {
                    $chunk = (string)(@\fread($pipe, 8192) ?: '');
                    if ($chunk !== '' && \strlen($output) < self::MAX_OUTPUT_BYTES) {
                        $output .= \substr($chunk, 0, self::MAX_OUTPUT_BYTES - \strlen($output));
                    }
                }
            }
            if (!($lastStatus['running'] ?? false)) {
                break;
            }
            if ((\microtime(true) - $startedAt) >= $timeoutSeconds) {
                $timedOut = true;
                @\proc_terminate($process);
                break;
            }
        }
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                $chunk = (string)(@\stream_get_contents($pipes[$index]) ?: '');
                if ($chunk !== '' && \strlen($output) < self::MAX_OUTPUT_BYTES) {
                    $output .= \substr($chunk, 0, self::MAX_OUTPUT_BYTES - \strlen($output));
                }
                @\fclose($pipes[$index]);
            }
        }
        $closeCode = @\proc_close($process);
        $exitCode = $timedOut
            ? 124
            : ((int)($lastStatus['exitcode'] ?? -1) >= 0 ? (int)$lastStatus['exitcode'] : (int)$closeCode);

        return [
            'success' => !$timedOut && $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * @param array<int|string, mixed> $args
     * @param list<string> $names
     */
    private function hasFlag(array $args, array $names): bool
    {
        foreach ($names as $name) {
            if (isset($args[$name])) {
                return true;
            }
        }
        foreach ($args as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \ltrim(\strtolower((string)$value), '-');
            if (\in_array($value, $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function tail(string $output): string
    {
        $output = \trim($output);
        if ($output === '') {
            return '';
        }

        return \strlen($output) <= 4000 ? $output : \substr($output, -4000);
    }
}
