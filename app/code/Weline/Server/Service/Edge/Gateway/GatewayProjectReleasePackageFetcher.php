<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Fetches a signed project gateway release package from an allowlisted HTTPS CDN
 * into extend/server/wls-gateway/{target}/.
 *
 * Contract:
 * - Never downloads trusted-release-keys.json
 * - Refuses to network when no enabled trust key exists
 * - Stages under var/tmp, verifies, then atomically publishes
 * - Must not run while holding the host package-bootstrap.lock
 */
final class GatewayProjectReleasePackageFetcher
{
    public const STATE_OK = 'PACKAGE_FETCHED';
    public const STATE_TRUST_UNAVAILABLE = 'TRUST_UNAVAILABLE';
    public const STATE_FETCH_DISABLED = 'PACKAGE_FETCH_DISABLED';
    public const STATE_FETCH_FAILED = 'PACKAGE_FETCH_FAILED';
    public const STATE_PACKAGE_INCOMPLETE = 'PACKAGE_INCOMPLETE';

    private const MAX_REDIRECTS = 3;
    private const MAX_MANIFEST_BYTES = 8_388_608;
    private const MAX_SIGNATURE_BYTES = 16_384;
    private const CONNECT_TIMEOUT_SEC = 10.0;

    /** @var callable(string,string,float):void|null */
    private $downloader;

    /** @var callable(string,string,?float):array|null */
    private $verifier;

    public function __construct(
        private readonly GatewayProjectReleasePackageFetchConfig $config,
        private readonly ?string $projectRoot = null,
        private readonly ?string $trustedKeysFile = null,
        private readonly ?string $stagingRoot = null,
        ?callable $downloader = null,
        ?callable $verifier = null,
    ) {
        $this->downloader = $downloader;
        $this->verifier = $verifier;
    }

    /**
     * @return array{ok:bool,state:string,reason:string,path:string,target_profile:string,enabled_keys:int}
     */
    public function probeTrust(): array
    {
        $target = GatewayProjectReleasePackageResolver::targetProfile();
        $enabled = $this->countEnabledTrustedKeys();
        if ($enabled < 1) {
            return [
                'ok' => false,
                'state' => self::STATE_TRUST_UNAVAILABLE,
                'reason' => 'No enabled Gateway trusted release key exists; inject '
                    . 'app/code/Weline/Server/env/gateway/trusted-release-keys.json before fetch.',
                'path' => '',
                'target_profile' => $target,
                'enabled_keys' => 0,
            ];
        }
        return [
            'ok' => true,
            'state' => 'TRUST_READY',
            'reason' => 'At least one enabled Gateway trusted release key is present.',
            'path' => '',
            'target_profile' => $target,
            'enabled_keys' => $enabled,
        ];
    }

    /**
     * @return array{ok:bool,state:string,reason:string,path:string,target_profile:string,enabled_keys:int}
     */
    public function fetch(
        ?string $targetProfile = null,
        bool $force = false,
        ?float $deadlineMonotonic = null,
    ): array {
        $deadlineMonotonic ??= (\hrtime(true) / 1_000_000_000) + $this->config->timeoutSec;
        $target = $targetProfile === null
            ? GatewayProjectReleasePackageResolver::targetProfile()
            : $this->normalizeTarget($targetProfile);

        if (!$this->config->isFetchConfigured()) {
            return $this->result(
                false,
                self::STATE_FETCH_DISABLED,
                'Gateway package_base_url / package_fetch_hosts is not configured.',
                '',
                $target,
            );
        }

        $trust = $this->probeTrust();
        if (!$trust['ok']) {
            return $this->result(
                false,
                self::STATE_TRUST_UNAVAILABLE,
                (string)$trust['reason'],
                '',
                $target,
                (int)$trust['enabled_keys'],
            );
        }

        $root = $this->canonicalProjectRoot();
        $finalDir = $root . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR
            . 'server' . DIRECTORY_SEPARATOR . 'wls-gateway' . DIRECTORY_SEPARATOR . $target;
        if (!$force && $this->finalTreeLooksComplete($finalDir)) {
            try {
                $this->verifyPackageDirectory($finalDir, $deadlineMonotonic);
                return $this->result(
                    true,
                    self::STATE_OK,
                    'An already verified Gateway project release package is present.',
                    (string)\realpath($finalDir),
                    $target,
                    (int)$trust['enabled_keys'],
                );
            } catch (\Throwable) {
                // Incomplete or corrupt final tree: continue to replace when force
                // or when caller asked for a fresh fetch of a broken tree.
            }
        }

        $lockPath = $this->stagingLockPath($root, $target);
        $lock = $this->acquireStagingLock($lockPath, $deadlineMonotonic);
        $staging = '';
        try {
            $this->assertDeadline($deadlineMonotonic);
            $staging = $this->createStagingDirectory($root, $target);
            $this->downloadReleaseTree($staging, $target, $deadlineMonotonic);
            $this->verifyPackageDirectory($staging, $deadlineMonotonic);
            $published = $this->publishStagingAtomically(
                $staging,
                $finalDir,
                $force,
                $deadlineMonotonic,
            );
            $staging = '';
            return $this->result(
                true,
                self::STATE_OK,
                'Signed Gateway project release package fetched, verified, and published.',
                $published,
                $target,
                (int)$trust['enabled_keys'],
            );
        } catch (\Throwable $throwable) {
            if ($staging !== '') {
                $this->removeTree($staging);
            }
            return $this->result(
                false,
                self::STATE_FETCH_FAILED,
                $throwable->getMessage(),
                '',
                $target,
                (int)$trust['enabled_keys'],
            );
        } finally {
            $this->releaseStagingLock($lock, $lockPath);
        }
    }

    /**
     * Detect a final distribution tree that has a manifest but fails verification.
     *
     * @return array{ok:bool,state:string,reason:string,path:string,target_profile:string}
     */
    public function assessLocalPackage(?string $targetProfile = null): array
    {
        $target = $targetProfile === null
            ? GatewayProjectReleasePackageResolver::targetProfile()
            : $this->normalizeTarget($targetProfile);
        $resolved = (new GatewayProjectReleasePackageResolver(
            $this->projectRoot,
            $target,
        ))->resolve();
        if (($resolved['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'state' => (string)($resolved['state'] ?? 'PACKAGE_UNAVAILABLE'),
                'reason' => (string)($resolved['reason'] ?? 'Package unavailable.'),
                'path' => '',
                'target_profile' => $target,
            ];
        }
        $path = (string)$resolved['path'];
        try {
            $this->verifyPackageDirectory($path, null);
            return [
                'ok' => true,
                'state' => 'AVAILABLE',
                'reason' => 'Local Gateway project release package verifies.',
                'path' => $path,
                'target_profile' => $target,
            ];
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'state' => self::STATE_PACKAGE_INCOMPLETE,
                'reason' => $throwable->getMessage(),
                'path' => $path,
                'target_profile' => $target,
            ];
        }
    }

    public function countEnabledTrustedKeys(): int
    {
        $file = $this->trustedKeysFile
            ?? \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'env'
                . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR
                . 'trusted-release-keys.json';
        if (!\is_file($file) || \is_link($file)) {
            return 0;
        }
        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '') {
            return 0;
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return 0;
        }
        $count = 0;
        foreach ((array)($decoded['keys'] ?? []) as $candidate) {
            if (\is_array($candidate)
                && ($candidate['enabled'] ?? false) === true
                && \hash_equals('ed25519', (string)($candidate['algorithm'] ?? ''))
                && \trim((string)($candidate['id'] ?? '')) !== ''
                && \trim((string)($candidate['public_key_base64'] ?? '')) !== ''
            ) {
                ++$count;
            }
        }
        return $count;
    }

    private function downloadReleaseTree(
        string $staging,
        string $target,
        float $deadlineMonotonic,
    ): void {
        $manifestUrl = $this->config->baseUrl . '/' . $target . '/manifest.json';
        $signatureUrl = $this->config->baseUrl . '/' . $target . '/manifest.sig';
        $manifestPath = $staging . DIRECTORY_SEPARATOR . 'manifest.json';
        $signaturePath = $staging . DIRECTORY_SEPARATOR . 'manifest.sig';
        $this->downloadToFile($manifestUrl, $manifestPath, $deadlineMonotonic, self::MAX_MANIFEST_BYTES);
        $this->downloadToFile($signatureUrl, $signaturePath, $deadlineMonotonic, self::MAX_SIGNATURE_BYTES);

        $manifestBytes = $this->readRegularFile($manifestPath, self::MAX_MANIFEST_BYTES, 'staged manifest');
        $manifest = \json_decode($manifestBytes, true);
        if (!\is_array($manifest) || !\is_array($manifest['components'] ?? null) || $manifest['components'] === []) {
            throw new \RuntimeException('Fetched Gateway manifest is missing a usable components map.');
        }
        if (\count($manifest['components']) > HostGatewayPackageManager::MAX_PACKAGE_COMPONENTS) {
            throw new \RuntimeException('Fetched Gateway manifest exceeds its fixed component limit.');
        }

        $total = \strlen($manifestBytes) + \filesize($signaturePath);
        foreach ($manifest['components'] as $relative => $definition) {
            $this->assertDeadline($deadlineMonotonic);
            if (!\is_string($relative) || $relative === '' || \str_contains($relative, "\0")) {
                throw new \RuntimeException('Fetched Gateway component path is unsafe.');
            }
            $relative = \str_replace('\\', '/', $relative);
            if (\str_starts_with($relative, '/')
                || \str_contains($relative, '..')
                || !\is_array($definition)
            ) {
                throw new \RuntimeException('Fetched Gateway component path escaped the package root: ' . $relative);
            }
            $size = (int)($definition['size'] ?? 0);
            if ($size < 1 || $size > HostGatewayPackageManager::MAX_PACKAGE_BYTES) {
                throw new \RuntimeException('Fetched Gateway component size is invalid: ' . $relative);
            }
            $total += $size;
            if ($total > HostGatewayPackageManager::MAX_PACKAGE_BYTES) {
                throw new \RuntimeException('Fetched Gateway package exceeds the fixed size limit.');
            }
            $destination = $staging . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = \dirname($destination);
            if (!\is_dir($parent) && !@\mkdir($parent, 0700, true) && !\is_dir($parent)) {
                throw new \RuntimeException('Unable to create staged Gateway component directory for ' . $relative);
            }
            $url = $this->config->baseUrl . '/' . $target . '/'
                . \implode('/', \array_map('rawurlencode', \explode('/', $relative)));
            $this->downloadToFile($url, $destination, $deadlineMonotonic, $size);
            $expected = \strtolower(\trim((string)($definition['sha256'] ?? '')));
            $actual = \hash_file('sha256', $destination);
            if (!\is_string($actual)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1
                || !\hash_equals($expected, $actual)
                || (int)\filesize($destination) !== $size
            ) {
                throw new \RuntimeException('Fetched Gateway component digest mismatch: ' . $relative);
            }
            $mode = $definition['mode'] ?? null;
            if (\is_int($mode)) {
                @\chmod($destination, $mode);
            }
        }
    }

    private function downloadToFile(
        string $url,
        string $destination,
        float $deadlineMonotonic,
        int $maxBytes,
    ): void {
        $this->assertDeadline($deadlineMonotonic);
        $this->assertAllowedUrl($url);
        if ($this->downloader !== null) {
            ($this->downloader)($url, $destination, $deadlineMonotonic);
            if (!\is_file($destination) || \is_link($destination)) {
                throw new \RuntimeException('Injected Gateway downloader did not create: ' . $destination);
            }
            if (\filesize($destination) > $maxBytes) {
                throw new \RuntimeException('Downloaded Gateway object exceeds its size bound: ' . $url);
            }
            return;
        }

        $tmp = $destination . '.part';
        if (\file_exists($tmp) || \is_link($tmp)) {
            $this->removeLeaf($tmp);
        }
        $source = $this->openHttpsDownload($url, $deadlineMonotonic);
        $target = @\fopen($tmp, 'xb');
        if (!\is_resource($target)) {
            @\fclose($source);
            throw new \RuntimeException('Unable to create partial Gateway download: ' . $tmp);
        }
        $total = 0;
        try {
            while (!@\feof($source)) {
                $this->assertDeadline($deadlineMonotonic);
                $chunk = @\fread($source, 1024 * 1024);
                if (!\is_string($chunk)) {
                    throw new \RuntimeException('Gateway download read failed: ' . $url);
                }
                if ($chunk === '') {
                    $metadata = @\stream_get_meta_data($source);
                    if ((bool)($metadata['timed_out'] ?? false)) {
                        throw new \RuntimeException('Gateway download timed out: ' . $url);
                    }
                    if (!@\feof($source)) {
                        throw new \RuntimeException('Gateway download made no progress: ' . $url);
                    }
                    break;
                }
                $total += \strlen($chunk);
                if ($total > $maxBytes) {
                    throw new \RuntimeException('Gateway download exceeds its size bound: ' . $url);
                }
                $offset = 0;
                while ($offset < \strlen($chunk)) {
                    $written = @\fwrite($target, \substr($chunk, $offset));
                    if (!\is_int($written) || $written < 1) {
                        throw new \RuntimeException('Unable to write Gateway download: ' . $tmp);
                    }
                    $offset += $written;
                }
            }
            if (!@\fflush($target) || (\function_exists('fsync') && !@\fsync($target))) {
                throw new \RuntimeException('Unable to flush Gateway download: ' . $tmp);
            }
        } catch (\Throwable $throwable) {
            @\fclose($source);
            @\fclose($target);
            $this->removeLeaf($tmp);
            throw $throwable;
        }
        @\fclose($source);
        @\fclose($target);
        if ($total < 1 || !@\rename($tmp, $destination)) {
            $this->removeLeaf($tmp);
            throw new \RuntimeException('Unable to publish Gateway download: ' . $destination);
        }
    }

    /** @return resource */
    private function openHttpsDownload(string $url, float $deadlineMonotonic)
    {
        $current = $url;
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; ++$redirect) {
            $this->assertDeadline($deadlineMonotonic);
            $this->assertAllowedUrl($current);
            $remaining = \max(1.0, $deadlineMonotonic - (\hrtime(true) / 1_000_000_000));
            $context = \stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => \min(self::CONNECT_TIMEOUT_SEC, $remaining),
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'header' => "Accept: */*\r\nUser-Agent: Weline-WLS-GatewayPackageFetcher/1\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $stream = @\fopen($current, 'rb', false, $context);
            if (!\is_resource($stream)) {
                throw new \RuntimeException('Unable to open Gateway package URL: ' . $current);
            }
            $meta = @\stream_get_meta_data($stream);
            $wrapper = (array)($meta['wrapper_data'] ?? []);
            $statusLine = (string)($wrapper[0] ?? '');
            if (\preg_match('/\s(30[12378])\s/', $statusLine, $match) === 1) {
                @\fclose($stream);
                $location = '';
                foreach ($wrapper as $headerLine) {
                    if (!\is_string($headerLine)) {
                        continue;
                    }
                    if (\preg_match('/\ALocation:\s*(.+)\z/i', \trim($headerLine), $loc) === 1) {
                        $location = \trim($loc[1]);
                        break;
                    }
                }
                if ($location === '') {
                    throw new \RuntimeException('Gateway package redirect lacked Location: ' . $current);
                }
                if (!\preg_match('#\Ahttps?://#i', $location)) {
                    $parts = \parse_url($current);
                    $location = ((string)($parts['scheme'] ?? 'https')) . '://'
                        . ((string)($parts['host'] ?? ''))
                        . (\str_starts_with($location, '/') ? $location : '/' . $location);
                }
                $current = $location;
                continue;
            }
            if (\preg_match('/\s(200)\s/', $statusLine) !== 1 && $statusLine !== '') {
                @\fclose($stream);
                throw new \RuntimeException('Gateway package URL returned ' . $statusLine);
            }
            return $stream;
        }
        throw new \RuntimeException('Gateway package download exceeded redirect limit.');
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = \parse_url($url);
        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        $host = \strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            throw new \RuntimeException('Gateway package downloads must use HTTPS with an explicit host.');
        }
        if (!\in_array($host, $this->config->allowedHosts, true)) {
            throw new \RuntimeException('Gateway package host is not allowlisted: ' . $host);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Gateway package URLs must not embed credentials.');
        }
    }

    private function verifyPackageDirectory(string $directory, ?float $deadlineMonotonic): array
    {
        if ($this->verifier !== null) {
            return ($this->verifier)($directory, 'default', $deadlineMonotonic);
        }
        $verified = (new HostGatewayPackageManager(
            trustedKeysFile: $this->trustedKeysFile,
        ))->verifyPackage($directory, 'default', $deadlineMonotonic);
        if (($verified['manifest']['release_ready'] ?? false) !== true) {
            throw new \RuntimeException('Fetched Gateway package is not release_ready.');
        }
        return $verified;
    }

    private function publishStagingAtomically(
        string $staging,
        string $finalDir,
        bool $force,
        float $deadlineMonotonic,
    ): string {
        $this->assertDeadline($deadlineMonotonic);
        $parent = \dirname($finalDir);
        if (!\is_dir($parent) && !@\mkdir($parent, 0755, true) && !\is_dir($parent)) {
            throw new \RuntimeException('Unable to create Gateway package parent: ' . $parent);
        }
        $this->assertNoSymlinkComponents($parent);
        if (\is_link($finalDir)) {
            throw new \RuntimeException('Gateway package destination is a symbolic link.');
        }
        if (\is_dir($finalDir)) {
            if (!$force && $this->finalTreeLooksComplete($finalDir)) {
                try {
                    $this->verifyPackageDirectory($finalDir, $deadlineMonotonic);
                    $this->removeTree($staging);
                    return (string)\realpath($finalDir);
                } catch (\Throwable) {
                    // Replace incomplete tree.
                }
            }
            $backup = $finalDir . '.bak-' . \bin2hex(\random_bytes(4));
            if (!@\rename($finalDir, $backup)) {
                throw new \RuntimeException('Unable to move previous Gateway package aside.');
            }
            if (!@\rename($staging, $finalDir)) {
                @\rename($backup, $finalDir);
                throw new \RuntimeException('Unable to publish staged Gateway package.');
            }
            $this->removeTree($backup);
        } elseif (!@\rename($staging, $finalDir)) {
            throw new \RuntimeException('Unable to publish staged Gateway package.');
        }
        $real = \realpath($finalDir);
        if (!\is_string($real)) {
            throw new \RuntimeException('Published Gateway package path is indeterminate.');
        }
        return $real;
    }

    private function finalTreeLooksComplete(string $finalDir): bool
    {
        return \is_dir($finalDir)
            && !\is_link($finalDir)
            && \is_file($finalDir . DIRECTORY_SEPARATOR . 'manifest.json')
            && !\is_link($finalDir . DIRECTORY_SEPARATOR . 'manifest.json');
    }

    private function createStagingDirectory(string $root, string $target): string
    {
        $base = $this->stagingRoot
            ?? ($root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp'
                . DIRECTORY_SEPARATOR . 'wls-gateway-package-stage');
        if (!\is_dir($base) && !@\mkdir($base, 0700, true) && !\is_dir($base)) {
            throw new \RuntimeException('Unable to create Gateway package staging root.');
        }
        $staging = $base . DIRECTORY_SEPARATOR . $target . '-' . \bin2hex(\random_bytes(8));
        if (!@\mkdir($staging, 0700, true) && !\is_dir($staging)) {
            throw new \RuntimeException('Unable to create Gateway package staging directory.');
        }
        return $staging;
    }

    private function stagingLockPath(string $root, string $target): string
    {
        $dir = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp';
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Unable to create Gateway package lock directory.');
        }
        return $dir . DIRECTORY_SEPARATOR . 'wls-gateway-package-fetch-' . $target . '.lock';
    }

    /** @return resource */
    private function acquireStagingLock(string $lockPath, float $deadlineMonotonic)
    {
        $handle = @\fopen($lockPath, 'c+b');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open Gateway package staging lock.');
        }
        while (!@\flock($handle, LOCK_EX | LOCK_NB)) {
            $this->assertDeadline($deadlineMonotonic);
            \usleep(50_000);
        }
        return $handle;
    }

    /** @param resource|null $handle */
    private function releaseStagingLock(mixed $handle, string $lockPath): void
    {
        if (\is_resource($handle)) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
        // Lock file itself may remain; do not unlink while others may open it.
        unset($lockPath);
    }

    private function canonicalProjectRoot(): string
    {
        $configured = $this->projectRoot
            ?? (\defined('BP') ? (string)\constant('BP') : (string)\getcwd());
        if ($configured === '' || \str_contains($configured, "\0") || \is_link($configured)) {
            throw new \RuntimeException('Gateway project release root is missing, linked, or unsafe.');
        }
        $root = @\realpath($configured);
        if (!\is_string($root) || $root === '' || !\is_dir($root)) {
            throw new \RuntimeException('Gateway project release root is missing, linked, or unsafe.');
        }
        return $root;
    }

    private function normalizeTarget(string $target): string
    {
        $target = \strtolower(\trim($target));
        $allowed = [
            'linux-x86_64',
            'linux-arm64',
            'darwin-x86_64',
            'darwin-arm64',
            'windows-x86_64',
        ];
        if (!\in_array($target, $allowed, true)) {
            throw new \RuntimeException(
                'The requested WLS Gateway release target is unsupported.',
            );
        }
        return $target;
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        $current = '';
        foreach (\explode(DIRECTORY_SEPARATOR, \ltrim($path, DIRECTORY_SEPARATOR)) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (\is_link($current)) {
                throw new \RuntimeException('Gateway package path contains a linked component.');
            }
        }
    }

    private function readRegularFile(string $path, int $maxBytes, string $label): string
    {
        if (!\is_file($path) || \is_link($path)) {
            throw new \RuntimeException($label . ' is missing or unsafe.');
        }
        $size = \filesize($path);
        if (!\is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new \RuntimeException($label . ' is empty or oversized.');
        }
        $bytes = @\file_get_contents($path);
        if (!\is_string($bytes) || \strlen($bytes) !== $size) {
            throw new \RuntimeException($label . ' could not be read stably.');
        }
        return $bytes;
    }

    private function removeLeaf(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }
        if (\is_dir($path)) {
            $this->removeTree($path);
        }
    }

    private function removeTree(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }
        if (!\is_dir($path)) {
            return;
        }
        $items = @\scandir($path);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
        @\rmdir($path);
    }

    private function assertDeadline(float $deadlineMonotonic): void
    {
        if ((\hrtime(true) / 1_000_000_000) >= $deadlineMonotonic) {
            throw new \RuntimeException('Gateway package fetch exceeded its wall-clock deadline.');
        }
    }

    /**
     * @return array{ok:bool,state:string,reason:string,path:string,target_profile:string,enabled_keys:int}
     */
    private function result(
        bool $ok,
        string $state,
        string $reason,
        string $path,
        string $target,
        int $enabledKeys = 0,
    ): array {
        return [
            'ok' => $ok,
            'state' => $state,
            'reason' => $reason,
            'path' => $path,
            'target_profile' => $target,
            'enabled_keys' => $enabledKeys,
        ];
    }
}
