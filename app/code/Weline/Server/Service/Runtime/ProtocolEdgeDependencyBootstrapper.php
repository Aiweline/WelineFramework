<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker;

/**
 * Verifies only the explicitly configured Caddy compatibility edge before WLS
 * creates managed processes. WLS-native HTTP/2/HTTP/3 runs in Worker/Dispatcher
 * and never downloads or compiles a protocol-edge binary.
 */
final class ProtocolEdgeDependencyBootstrapper
{
    private const INSTALL_TIMEOUT_SECONDS = 900;
    private const INSTALL_LOCK_TIMEOUT_SECONDS = 30;
    private const PROBE_TIMEOUT_SECONDS = 20;
    private const HTTP3_LIVE_PROBE_TIMEOUT_SECONDS = 5;
    private const WINDOWS_CADDY_DOWNLOAD_TIMEOUT_SECONDS = 120;
    private const WINDOWS_CADDY_READ_TIMEOUT_SECONDS = 15;
    private const WINDOWS_CADDY_MAX_REDIRECTS = 5;
    private const WINDOWS_CADDY_MAX_ARCHIVE_BYTES = 134_217_728;
    private const WINDOWS_CADDY_MAX_EXECUTABLE_BYTES = 268_435_456;
    private const WINDOWS_CADDY_MAX_ARCHIVE_ENTRIES = 256;
    private const WINDOWS_CADDY_MAX_BACKUPS = 4;
    private const WINDOWS_CADDY_MAX_RUNTIME_ENTRIES = 4096;
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
                'status' => 'native',
                'message' => (string)__('WLS 原生 HTTP/2/HTTP/3 使用 Worker/Dispatcher 数据面，无需协议边缘二进制。'),
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
        $directory = \rtrim(\sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'wls-caddy-http3-probe-' . \bin2hex(\random_bytes(6));
        if (!@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            return ['success' => false, 'output' => 'Unable to create the HTTP/3 live-probe directory.'];
        }

        $configPath = $directory . DIRECTORY_SEPARATOR . 'caddy.json';
        $config = [
            'admin' => ['disabled' => true],
            'storage' => [
                'module' => 'file_system',
                'root' => $directory . DIRECTORY_SEPARATOR . 'data',
            ],
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
        if (@\file_put_contents($configPath, $payload, LOCK_EX) !== \strlen($payload)) {
            @\rmdir($directory);
            return ['success' => false, 'output' => 'Unable to write the HTTP/3 live-probe configuration.'];
        }
        @\chmod($configPath, 0600);

        try {
            $result = GatewayBoundedCommandRunner::run(
                [$binary, 'run', '--config', $configPath],
                (float)self::HTTP3_LIVE_PROBE_TIMEOUT_SECONDS,
                $directory,
                false,
            );
            $output = (string)($result['output'] ?? '');
            $listenerReady = \str_contains($output, 'enabling HTTP/3 listener');
            $serverReady = \str_contains($output, 'serving initial configuration');
            $code = (int)($result['code'] ?? 125);
            $runnerValid = $code === 0 || $code === 124;
        } catch (\Throwable $throwable) {
            $output = $throwable->getMessage();
            $listenerReady = false;
            $serverReady = false;
            $runnerValid = false;
        }
        try {
            $this->removeBoundedProbeTree($directory);
        } catch (\Throwable $throwable) {
            $runnerValid = false;
            $output .= "\nHTTP/3 probe cleanup failed: " . $throwable->getMessage();
        }

        return [
            'success' => $runnerValid && $listenerReady && $serverReady,
            'output' => $this->tail($output),
        ];
    }

    private function removeBoundedProbeTree(string $directory): void
    {
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException('HTTP/3 probe directory identity is unsafe.');
        }
        $records = GatewayBoundedTreeWalker::collect($directory, true, true);
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
            $removed = $record['directory']
                ? @\rmdir($record['path'])
                : @\unlink($record['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove HTTP/3 probe artifact: ' . $record['path'],
                );
            }
        }
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
            // Windows installation is deliberately project-local and pinned by
            // an immutable release digest. Falling through to winget/choco/
            // scoop would silently replace that trust contract with a mutable
            // host package and may also require host-wide elevation.
            return $this->installVerifiedWindowsCaddy($selection);
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

        try {
            $recovery = $this->recoverInterruptedWindowsBinary($managedBinary, $selection);
            if ($recovery !== null) {
                return $recovery;
            }
            $this->cleanupWindowsTransientFiles(
                $directory,
                $packageName,
                \basename($managedBinary),
            );
            $this->pruneWindowsBackups(
                $managedBinary,
                self::WINDOWS_CADDY_MAX_BACKUPS - 1,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'output' => 'Managed Caddy runtime cleanup failed closed: '
                    . $throwable->getMessage(),
            ];
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
            $entrySize = -1;
            $matchingEntries = 0;
            if ($zip->numFiles < 1 || $zip->numFiles > self::WINDOWS_CADDY_MAX_ARCHIVE_ENTRIES) {
                $zip->close();
                return ['success' => false, 'output' => 'Verified Caddy archive has an unsafe entry count.'];
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = (string)$zip->getNameIndex($index);
                if (\strtolower(\basename(\str_replace('\\', '/', $entry))) === 'caddy.exe') {
                    $stat = $zip->statIndex($index);
                    $entryName = $entry;
                    $entrySize = \is_array($stat) ? (int)($stat['size'] ?? -1) : -1;
                    ++$matchingEntries;
                }
            }
            if ($entryName === ''
                || $matchingEntries !== 1
                || $entrySize < 1
                || $entrySize > self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES
            ) {
                $zip->close();
                return ['success' => false, 'output' => 'Verified Caddy archive has no unique bounded caddy.exe entry.'];
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
                $bytes = \stream_copy_to_stream($source, $destination, $entrySize + 1);
                $persisted = @\fflush($destination)
                    && (!\function_exists('fsync') || @\fsync($destination));
            } finally {
                @\fclose($source);
                @\fclose($destination);
                $zip->close();
            }
            if (!\is_int($bytes) || $bytes !== $entrySize || !$persisted) {
                return ['success' => false, 'output' => 'Verified Caddy executable extraction exceeded its declared bounds or was not persisted.'];
            }
            @\chmod($candidate, 0755);

            try {
                $candidateProof = $this->stableRegularFileProof(
                    $candidate,
                    self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES,
                    'extracted Caddy executable',
                );
            } catch (\Throwable $throwable) {
                return ['success' => false, 'output' => $throwable->getMessage()];
            }

            $probe = $this->probe($candidate, $selection);
            if (!$probe['success']) {
                return [
                    'success' => false,
                    'output' => 'Downloaded Caddy failed the runtime capability probe: ' . $probe['output'],
                ];
            }

            $publish = $this->publishWindowsBinary(
                $candidate,
                $managedBinary,
                $token,
                $candidateProof,
            );
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
        $deadline = $this->monotonicSeconds()
            + self::WINDOWS_CADDY_DOWNLOAD_TIMEOUT_SECONDS;
        try {
            $opened = $this->openBoundedHttpsDownload($url, $deadline);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'output' => 'Unable to open the verified dependency package: '
                    . $throwable->getMessage(),
            ];
        }
        $source = $opened['stream'] ?? null;
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
        $bytes = 0;
        $hash = \hash_init('sha256');
        $failure = '';
        try {
            while (!@\feof($source)) {
                $remaining = $deadline - $this->monotonicSeconds();
                if ($remaining <= 0.0) {
                    $failure = 'Verified dependency package download exceeded its total deadline.';
                    break;
                }
                @\stream_set_timeout(
                    $source,
                    (int)\max(1, \min(
                        self::WINDOWS_CADDY_READ_TIMEOUT_SECONDS,
                        \ceil($remaining),
                    )),
                );
                $chunk = @\fread($source, 65_536);
                $metadata = @\stream_get_meta_data($source);
                if (!\is_string($chunk)
                    || (\is_array($metadata) && ($metadata['timed_out'] ?? false) === true)
                ) {
                    $failure = 'Verified dependency package download stalled or failed.';
                    break;
                }
                if ($chunk === '') {
                    if (@\feof($source)) {
                        break;
                    }
                    continue;
                }
                $bytes += \strlen($chunk);
                if ($bytes > self::WINDOWS_CADDY_MAX_ARCHIVE_BYTES) {
                    $failure = 'Verified dependency package exceeds its fixed archive-size limit.';
                    break;
                }
                \hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < \strlen($chunk)) {
                    $written = @\fwrite($destination, \substr($chunk, $offset));
                    if (!\is_int($written) || $written < 1) {
                        $failure = 'Unable to persist the verified dependency package.';
                        break 2;
                    }
                    $offset += $written;
                }
            }
            if ($failure === ''
                && (!@\fflush($destination)
                    || (\function_exists('fsync') && !@\fsync($destination)))
            ) {
                $failure = 'Unable to durably persist the verified dependency package.';
            }
        } finally {
            @\fclose($source);
            @\fclose($destination);
        }
        if ($failure !== '') {
            return ['success' => false, 'output' => $failure];
        }
        if ($bytes <= 0) {
            return ['success' => false, 'output' => 'Verified dependency package download was empty.'];
        }

        $actualHash = \hash_final($hash);
        $persistedSize = @\filesize($target);
        if (!\is_int($persistedSize)
            || $persistedSize !== $bytes
            || !\hash_equals($expectedHash, \strtolower($actualHash))
        ) {
            return ['success' => false, 'output' => 'Dependency package SHA-256 mismatch; installation refused.'];
        }

        return ['success' => true, 'output' => 'Dependency package digest verified.'];
    }

    /** @return array{stream:resource,url:string} */
    private function openBoundedHttpsDownload(string $url, float $deadline): array
    {
        $current = $url;
        for ($redirect = 0; $redirect <= self::WINDOWS_CADDY_MAX_REDIRECTS; ++$redirect) {
            $parts = \parse_url($current);
            if (!\is_array($parts)
                || !\hash_equals('https', \strtolower((string)($parts['scheme'] ?? '')))
                || \trim((string)($parts['host'] ?? '')) === ''
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['fragment'])
                || (isset($parts['port']) && (int)$parts['port'] !== 443)
            ) {
                throw new \RuntimeException('Verified dependency redirects must remain on canonical HTTPS URLs.');
            }
            $remaining = $deadline - $this->monotonicSeconds();
            if ($remaining <= 0.0) {
                throw new \RuntimeException('Verified dependency download exceeded its total deadline.');
            }
            $context = \stream_context_create([
                'http' => [
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'timeout' => (int)\max(1, \min(
                        self::WINDOWS_CADDY_READ_TIMEOUT_SECONDS,
                        \ceil($remaining),
                    )),
                    'user_agent' => 'WelineFramework-WLS/' . PHP_VERSION,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                ],
            ]);
            $http_response_header = [];
            $stream = @\fopen($current, 'rb', false, $context);
            $headers = isset($http_response_header) && \is_array($http_response_header)
                ? $http_response_header
                : [];
            $status = 0;
            $location = '';
            foreach ($headers as $header) {
                if (\preg_match('/\AHTTP\/\S+\s+([0-9]{3})\b/i', (string)$header, $match) === 1) {
                    $status = (int)$match[1];
                } elseif (\stripos((string)$header, 'Location:') === 0) {
                    $location = \trim(\substr((string)$header, 9));
                }
            }
            if ($status >= 200 && $status < 300 && \is_resource($stream)) {
                return ['stream' => $stream, 'url' => $current];
            }
            if (\is_resource($stream)) {
                @\fclose($stream);
            }
            if ($status < 300 || $status > 399 || $location === '') {
                throw new \RuntimeException('Verified dependency HTTPS endpoint returned an invalid response.');
            }
            if ($redirect >= self::WINDOWS_CADDY_MAX_REDIRECTS) {
                throw new \RuntimeException('Verified dependency download exceeded its redirect limit.');
            }
            $current = $this->resolveHttpsRedirect($current, $location);
        }

        throw new \RuntimeException('Verified dependency redirect resolution failed.');
    }

    private function resolveHttpsRedirect(string $base, string $location): string
    {
        if ($location === ''
            || \preg_match('/[\x00-\x20\x7f]/', $location) === 1
        ) {
            throw new \RuntimeException('Verified dependency redirect location is malformed.');
        }
        if (\preg_match('/\Ahttps:\/\//i', $location) === 1) {
            return $location;
        }
        if (\str_starts_with($location, '//')
            || \preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/D', $location) === 1
        ) {
            throw new \RuntimeException('Verified dependency redirect attempted a protocol change.');
        }
        $parts = \parse_url($base);
        if (!\is_array($parts) || !isset($parts['host'])) {
            throw new \RuntimeException('Verified dependency redirect base is invalid.');
        }
        $authority = 'https://' . $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . (int)$parts['port'];
        }
        if (\str_starts_with($location, '/')) {
            return $authority . $location;
        }
        $path = (string)($parts['path'] ?? '/');
        return $authority . \substr($path, 0, (int)\strrpos($path, '/') + 1) . $location;
    }

    private function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /**
     * @param array{sha256:string,size:int} $expectedCandidate
     * @return array{success:bool,output:string}
     */
    private function publishWindowsBinary(
        string $candidate,
        string $target,
        string $token,
        array $expectedCandidate,
    ): array {
        try {
            $candidateProof = $this->stableRegularFileProof(
                $candidate,
                self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES,
                'probed Caddy executable',
            );
        } catch (\Throwable $throwable) {
            return ['success' => false, 'output' => $throwable->getMessage()];
        }
        if ($candidateProof !== $expectedCandidate) {
            return [
                'success' => false,
                'output' => 'The probed Caddy executable changed before publication.',
            ];
        }

        $backup = '';
        $targetProof = null;
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            try {
                $targetProof = $this->stableRegularFileProof(
                    $target,
                    self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES,
                    'existing managed Caddy executable',
                );
            } catch (\Throwable $throwable) {
                return ['success' => false, 'output' => $throwable->getMessage()];
            }
            $backup = $target . '.backup-' . \gmdate('YmdHis') . '-' . $token
                . '-' . $targetProof['sha256'];
            if (\file_exists($backup) || \is_link($backup)) {
                return [
                    'success' => false,
                    'output' => 'Managed Caddy backup target already exists or is unsafe.',
                ];
            }
            if (!@\rename($target, $backup)) {
                return ['success' => false, 'output' => 'Unable to preserve the existing managed Caddy binary.'];
            }
        } elseif (\file_exists($target) || \is_link($target)) {
            return [
                'success' => false,
                'output' => 'Managed Caddy target is linked, special, or indeterminate.',
            ];
        }
        if (@\rename($candidate, $target)) {
            try {
                $published = $this->stableRegularFileProof(
                    $target,
                    self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES,
                    'published managed Caddy executable',
                );
                if ($published === $expectedCandidate) {
                    return [
                        'success' => true,
                        'output' => 'Managed Caddy binary published atomically.',
                    ];
                }
            } catch (\Throwable) {
                // The exact rollback result below is the publication authority.
            }

            return $this->restoreWindowsBinary(
                $target,
                $backup,
                $targetProof,
                $token,
                'Published Caddy executable failed its post-rename identity check.',
            );
        }
        return $this->restoreWindowsBinary(
            $target,
            $backup,
            $targetProof,
            $token,
            'Unable to atomically publish the managed Caddy binary.',
        );
    }

    /** @return array{success:bool,output:string}|null */
    private function recoverInterruptedWindowsBinary(
        string $target,
        HttpProtocolSelection $selection,
    ): ?array {
        $targetState = @\lstat($target);
        if (\is_array($targetState)) {
            if (\is_link($target)
                || ((((int)($targetState['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($targetState['nlink'] ?? 0) !== 1
            ) {
                return [
                    'success' => false,
                    'output' => 'Managed Caddy recovery refused a linked or special target.',
                ];
            }
            return null;
        }
        if (\file_exists($target) || \is_link($target)) {
            return [
                'success' => false,
                'output' => 'Managed Caddy recovery could not determine the target identity.',
            ];
        }

        $pattern = '/\A' . \preg_quote(\basename($target), '/')
            . '\.backup-[0-9]{14}-[a-f0-9]{16}-([a-f0-9]{64})\z/D';
        $backups = [];
        foreach (GatewayBoundedTreeWalker::collect(
            \dirname($target),
            false,
            false,
            self::WINDOWS_CADDY_MAX_RUNTIME_ENTRIES,
        ) as $record) {
            $matches = [];
            if (!$record['directory']
                && (int)$record['depth'] === 1
                && \preg_match($pattern, \basename((string)$record['path']), $matches) === 1
            ) {
                $record['expected_sha256'] = (string)$matches[1];
                $backups[] = $record;
            }
        }
        \usort(
            $backups,
            static fn (array $left, array $right): int =>
                \strcmp((string)$right['path'], (string)$left['path']),
        );
        foreach ($backups as $record) {
            try {
                GatewayBoundedTreeWalker::revalidate($record);
                $proof = $this->stableRegularFileProof(
                    (string)$record['path'],
                    self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES,
                    'managed Caddy recovery backup',
                );
            } catch (\Throwable) {
                continue;
            }
            if (!\hash_equals((string)$record['expected_sha256'], $proof['sha256'])) {
                continue;
            }
            $probe = $this->probe((string)$record['path'], $selection);
            if (!$probe['success']) {
                continue;
            }
            if (!@\rename((string)$record['path'], $target)) {
                return [
                    'success' => false,
                    'output' => 'Unable to restore the verified managed Caddy recovery backup.',
                ];
            }
            try {
                $restored = $this->stableRegularFileProof(
                    $target,
                    self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES,
                    'restored managed Caddy executable',
                );
            } catch (\Throwable $throwable) {
                return [
                    'success' => false,
                    'output' => 'Managed Caddy recovery publication could not be verified: '
                        . $throwable->getMessage(),
                ];
            }
            if ($restored !== $proof) {
                return [
                    'success' => false,
                    'output' => 'Managed Caddy recovery publication changed after atomic restore.',
                ];
            }
            $restoredProbe = $this->probe($target, $selection);
            if (!$restoredProbe['success']) {
                return [
                    'success' => false,
                    'output' => 'Restored managed Caddy executable failed its capability probe: '
                        . $restoredProbe['output'],
                ];
            }
            return [
                'success' => true,
                'output' => 'Recovered the last verified managed Caddy binary after an interrupted publication.',
            ];
        }

        return null;
    }

    /**
     * @param array{sha256:string,size:int}|null $expectedBackup
     * @return array{success:bool,output:string}
     */
    private function restoreWindowsBinary(
        string $target,
        string $backup,
        ?array $expectedBackup,
        string $token,
        string $failure,
    ): array {
        $failed = $target . '.failed-' . $token;
        if (\file_exists($failed) || \is_link($failed)) {
            return [
                'success' => false,
                'output' => $failure . ' Rollback staging path is already occupied.',
            ];
        }
        if (\file_exists($target) || \is_link($target)) {
            if (\is_link($target) || !\is_file($target) || !@\rename($target, $failed)) {
                return [
                    'success' => false,
                    'output' => $failure . ' The failed target could not be isolated.',
                ];
            }
        }
        if ($backup !== '') {
            if ($expectedBackup === null
                || !\is_file($backup)
                || \is_link($backup)
                || !@\rename($backup, $target)
            ) {
                return [
                    'success' => false,
                    'output' => $failure . ' The previous managed Caddy binary could not be restored.',
                ];
            }
            try {
                $restored = $this->stableRegularFileProof(
                    $target,
                    self::WINDOWS_CADDY_MAX_EXECUTABLE_BYTES,
                    'restored managed Caddy executable',
                );
            } catch (\Throwable $throwable) {
                return [
                    'success' => false,
                    'output' => $failure . ' Restored target verification failed: '
                        . $throwable->getMessage(),
                ];
            }
            if ($restored !== $expectedBackup) {
                return [
                    'success' => false,
                    'output' => $failure . ' Restored target content does not match the preserved binary.',
                ];
            }
        }
        if ((\file_exists($failed) || \is_link($failed)) && !@\unlink($failed)) {
            return [
                'success' => false,
                'output' => $failure . ' Rollback succeeded but failed-target cleanup did not.',
            ];
        }

        return [
            'success' => false,
            'output' => $failure . ($backup !== '' ? ' Previous binary restored.' : ''),
        ];
    }

    private function cleanupWindowsTransientFiles(
        string $directory,
        string $packageName,
        string $binaryLeaf,
    ): void {
        $downloadPattern = '/\A' . \preg_quote($packageName, '/')
            . '\.download-[a-f0-9]{16}\z/D';
        $candidatePattern = '/\A' . \preg_quote($binaryLeaf, '/')
            . '\.install-[a-f0-9]{16}\.exe\z/D';
        foreach (GatewayBoundedTreeWalker::collect(
            $directory,
            false,
            false,
            self::WINDOWS_CADDY_MAX_RUNTIME_ENTRIES,
        ) as $record) {
            if ($record['directory'] || (int)$record['depth'] !== 1) {
                continue;
            }
            $leaf = \basename((string)$record['path']);
            if (\preg_match($downloadPattern, $leaf) !== 1
                && \preg_match($candidatePattern, $leaf) !== 1
            ) {
                continue;
            }
            GatewayBoundedTreeWalker::revalidate($record);
            if (!@\unlink((string)$record['path'])) {
                throw new \RuntimeException(
                    'Unable to remove stale installer artifact: ' . $leaf
                );
            }
        }
    }

    private function pruneWindowsBackups(string $target, int $keep): void
    {
        if ($keep < 0 || $keep >= self::WINDOWS_CADDY_MAX_BACKUPS) {
            throw new \InvalidArgumentException('Managed Caddy backup bound is invalid.');
        }
        $directory = \dirname($target);
        $pattern = '/\A' . \preg_quote(\basename($target), '/')
            . '\.backup-[0-9]{14}-[a-f0-9]{16}(?:-[a-f0-9]{64})?\z/D';
        $backups = [];
        foreach (GatewayBoundedTreeWalker::collect(
            $directory,
            false,
            false,
            self::WINDOWS_CADDY_MAX_RUNTIME_ENTRIES,
        ) as $record) {
            if (!$record['directory']
                && (int)$record['depth'] === 1
                && \preg_match($pattern, \basename((string)$record['path'])) === 1
            ) {
                $backups[] = $record;
            }
        }
        \usort(
            $backups,
            static fn (array $left, array $right): int =>
                \strcmp((string)$left['path'], (string)$right['path']),
        );
        while (\count($backups) > $keep) {
            $record = \array_shift($backups);
            if (!\is_array($record)) {
                break;
            }
            GatewayBoundedTreeWalker::revalidate($record);
            if (!@\unlink((string)$record['path'])) {
                throw new \RuntimeException(
                    'Unable to prune an old managed Caddy backup.'
                );
            }
        }
    }

    /** @return array{sha256:string,size:int} */
    private function stableRegularFileProof(
        string $file,
        int $maximumBytes,
        string $label,
    ): array {
        $before = @\lstat($file);
        if (!\is_array($before)
            || \is_link($file)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is linked, special, or outside bounds.');
        }
        $handle = @\fopen($file, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !$this->sameFileState($before, $opened)) {
                throw new \RuntimeException($label . ' changed before hashing.');
            }
            $hash = \hash_init('sha256');
            $consumed = 0;
            while ($consumed < (int)$opened['size']) {
                $chunk = @\fread(
                    $handle,
                    \min(1_048_576, (int)$opened['size'] - $consumed),
                );
                if (!\is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException($label . ' ended before its declared size.');
                }
                $consumed += \strlen($chunk);
                \hash_update($hash, $chunk);
            }
            $extra = @\fread($handle, 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($file);
            if ($extra !== ''
                || !\is_array($after)
                || !\is_array($pathAfter)
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being hashed.');
            }
            return [
                'sha256' => \hash_final($hash),
                'size' => $consumed,
            ];
        } finally {
            @\fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function sameFileState(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }
        return true;
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
        if (\is_link($directory) || !\is_dir($directory)) {
            return null;
        }
        $path = $directory . DS . 'protocol_edge_dependency_install.lock';
        $handle = false;
        $created = false;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $before = @\lstat($path);
            $created = false;
            if (\is_array($before)) {
                if (\is_link($path)
                    || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($before['nlink'] ?? 0) !== 1
                ) {
                    return null;
                }
                $handle = @\fopen($path, 'r+b');
            } else {
                if (\file_exists($path) || \is_link($path)) {
                    return null;
                }
                $handle = @\fopen($path, 'x+b');
                $created = \is_resource($handle);
            }
            if (!\is_resource($handle)) {
                SchedulerSystem::usleep(2000);
                continue;
            }
            $opened = @\fstat($handle);
            $pathStatus = @\lstat($path);
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || \is_link($path)
                || ((((int)($opened['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($opened['nlink'] ?? 0) !== 1
                || (!$created
                    && (!\is_array($before)
                        || !$this->sameFileState($before, $opened)))
                || !$this->sameFileState($opened, $pathStatus)
            ) {
                @\fclose($handle);
                return null;
            }
            if (!@\chmod($path, 0600)) {
                @\fclose($handle);
                return null;
            }
            break;
        }
        if (!\is_resource($handle)) {
            return null;
        }

        $deadline = $this->monotonicSeconds() + self::INSTALL_LOCK_TIMEOUT_SECONDS;
        do {
            if (@\flock($handle, \LOCK_EX | \LOCK_NB)) {
                $opened = @\fstat($handle);
                $pathStatus = @\lstat($path);
                if (\is_array($opened)
                    && \is_array($pathStatus)
                    && !\is_link($path)
                    && $this->sameFileState($opened, $pathStatus)
                ) {
                    if ($created) {
                        @\fflush($handle);
                        \function_exists('fsync') && @\fsync($handle);
                    }
                    return $handle;
                }
                @\flock($handle, \LOCK_UN);
                break;
            }
            if ($this->monotonicSeconds() >= $deadline) {
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
        if ($environment !== null) {
            return [
                'success' => false,
                'exit_code' => 127,
                'output' => 'Custom dependency-process environments are not supported.',
                'timed_out' => false,
            ];
        }
        try {
            $result = GatewayBoundedCommandRunner::run(
                $command,
                (float)$timeoutSeconds,
                $workingDirectory ?? (\defined('BP') ? BP : null),
                false,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'exit_code' => 126,
                'output' => $throwable->getMessage(),
                'timed_out' => false,
            ];
        }
        $exitCode = (int)($result['code'] ?? 125);

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => (string)($result['output'] ?? ''),
            'timed_out' => $exitCode === 124,
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
