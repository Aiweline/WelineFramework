<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

/**
 * Verifies that an Nginx PID still belongs to one immutable runtime manifest.
 *
 * The caller remains responsible for proving the PID is alive. This class
 * fences all lifecycle actions by exact argv paths, binary digest, runtime
 * generation and a PID-bound process identity record.
 */
final class NginxProcessIdentity
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly string $role,
        private readonly string $binary,
        private readonly string $prefix,
        private readonly string $config,
        private readonly string $installManifest,
        private readonly string $processManifest,
    ) {
        if (\preg_match('/\A[a-z][a-z0-9._-]{1,63}\z/D', $role) !== 1) {
            throw new \InvalidArgumentException('Nginx process role is invalid.');
        }
    }

    /**
     * @return array{
     *   ok:bool,
     *   reason:string,
     *   pid:int,
     *   role:string,
     *   binary_sha256:string,
     *   runtime_generation:string,
     *   adopted:bool
     * }
     */
    public function inspect(int $pid, string $commandLine, bool $allowLegacyAdoption = false): array
    {
        if ($pid < 1 || \trim($commandLine) === '') {
            return $this->failure($pid, 'PID or command line is unavailable.');
        }

        try {
            $expected = $this->expectedRuntime();
        } catch (\Throwable $throwable) {
            return $this->failure($pid, $throwable->getMessage());
        }
        if (!$this->commandMatches($commandLine)) {
            return $this->failure(
                $pid,
                'Nginx argv does not match the expected binary, prefix and config.',
                $expected,
            );
        }

        return $this->withLock(function () use ($pid, $expected, $allowLegacyAdoption): array {
            $record = $this->readProcessManifest();
            $adopted = false;
            if ($record === null) {
                if (!$allowLegacyAdoption) {
                    return $this->failure(
                        $pid,
                        'PID-bound Nginx process identity is missing.',
                        $expected,
                    );
                }
                $record = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'role' => $this->role,
                    'pid' => $pid,
                    'binary' => $expected['binary'],
                    'binary_sha256' => $expected['binary_sha256'],
                    'prefix' => $expected['prefix'],
                    'config' => $expected['config'],
                    'runtime_generation' => $expected['runtime_generation'],
                    'adopted_from_legacy' => true,
                    'recorded_at' => \gmdate(DATE_ATOM),
                ];
                $this->publishProcessManifest($record);
                $adopted = true;
            }

            foreach ([
                'role',
                'binary',
                'binary_sha256',
                'prefix',
                'config',
                'runtime_generation',
            ] as $field) {
                if (!\is_string($record[$field] ?? null)
                    || !\hash_equals((string)$expected[$field], (string)$record[$field])
                ) {
                    return $this->failure(
                        $pid,
                        'PID-bound Nginx process identity field mismatch: ' . $field,
                        $expected,
                    );
                }
            }
            if ((int)($record['schema_version'] ?? 0) !== self::SCHEMA_VERSION
                || (int)($record['pid'] ?? 0) !== $pid
            ) {
                return $this->failure(
                    $pid,
                    'PID-bound Nginx process identity generation does not match.',
                    $expected,
                );
            }

            return [
                'ok' => true,
                'reason' => 'PID, executable digest and runtime generation match.',
                'pid' => $pid,
                'role' => $this->role,
                'binary_sha256' => $expected['binary_sha256'],
                'runtime_generation' => $expected['runtime_generation'],
                'adopted' => $adopted,
            ];
        });
    }

    public function recordedPid(): ?int
    {
        return $this->withLock(function (): ?int {
            $record = $this->readProcessManifest();
            $pid = (int)($record['pid'] ?? 0);
            return $pid > 0 ? $pid : null;
        });
    }

    public function clear(int $expectedPid): void
    {
        $this->withLock(function () use ($expectedPid): null {
            $record = $this->readProcessManifest();
            if ($record === null) {
                return null;
            }
            if ((int)($record['pid'] ?? 0) !== $expectedPid) {
                throw new \RuntimeException(
                    'Refusing to clear a PID-bound Nginx identity owned by another generation.'
                );
            }
            if (!@\unlink($this->processManifest) && \is_file($this->processManifest)) {
                throw new \RuntimeException('Unable to clear the PID-bound Nginx process identity.');
            }
            return null;
        });
    }

    /**
     * @return array{
     *   role:string,
     *   binary:string,
     *   binary_sha256:string,
     *   prefix:string,
     *   config:string,
     *   runtime_generation:string
     * }
     */
    private function expectedRuntime(): array
    {
        foreach ([
            $this->binary,
            $this->installManifest,
        ] as $file) {
            if (!\is_file($file) || \is_link($file)) {
                throw new \RuntimeException('Nginx runtime identity file is missing or unsafe: ' . $file);
            }
        }
        $decoded = \json_decode((string)@\file_get_contents($this->installManifest), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Nginx install manifest is invalid.');
        }
        $actualDigest = @\hash_file('sha256', $this->binary);
        if (!\is_string($actualDigest)) {
            throw new \RuntimeException('Nginx binary digest does not match its install manifest.');
        }
        $actualDigest = \strtolower($actualDigest);
        $expectedDigest = \strtolower(\trim((string)($decoded['binary_sha256'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1) {
            $legacyArtifactDigest = \strtolower(\trim((string)(
                $decoded['artifact_sha256']
                ?? $decoded['source_sha256']
                ?? ''
            )));
            if ($this->normalizePath((string)($decoded['binary'] ?? ''))
                    !== $this->normalizePath($this->binary)
                || !\hash_equals(\PHP_OS_FAMILY, (string)($decoded['platform'] ?? ''))
                || \preg_match('/\A[a-f0-9]{64}\z/D', $legacyArtifactDigest) !== 1
            ) {
                throw new \RuntimeException('Legacy Nginx install manifest cannot be safely attested.');
            }
            // WLS 1.x manifests predate binary_sha256. Preserve compatibility
            // by binding the first verified legacy process record to the
            // current binary digest; every later mutation changes generation
            // and is rejected by the PID-bound record.
            $expectedDigest = $actualDigest;
            $decoded['legacy_attested_binary_sha256'] = $expectedDigest;
        }
        if (!\hash_equals($expectedDigest, $actualDigest)) {
            throw new \RuntimeException('Nginx binary digest does not match its install manifest.');
        }
        $runtimeGeneration = \strtolower(\trim((string)($decoded['runtime_generation'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1) {
            $canonical = $this->canonicalize($decoded);
            $encoded = \json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $runtimeGeneration = \hash('sha256', $encoded);
        }

        return [
            'role' => $this->role,
            'binary' => $this->normalizePath($this->binary),
            'binary_sha256' => $expectedDigest,
            'prefix' => $this->normalizePath($this->prefix),
            'config' => $this->normalizePath($this->config),
            'runtime_generation' => $runtimeGeneration,
        ];
    }

    private function commandMatches(string $commandLine): bool
    {
        $tokens = $this->tokenize($commandLine);
        $binary = $this->normalizePath($this->binary);
        $prefix = $this->normalizePath($this->prefix);
        $config = $this->normalizePath($this->config);
        $binaryMatched = false;
        $prefixMatched = false;
        $configMatched = false;
        foreach ($tokens as $index => $token) {
            if ($this->normalizePath($token) === $binary) {
                $binaryMatched = true;
            }
            if ($token === '-p' && isset($tokens[$index + 1])) {
                $prefixMatched = $this->normalizePath($tokens[$index + 1]) === $prefix;
            }
            if ($token === '-c' && isset($tokens[$index + 1])) {
                $configMatched = $this->normalizePath($tokens[$index + 1]) === $config;
            }
        }
        return $binaryMatched && $prefixMatched && $configMatched;
    }

    /** @return list<string> */
    private function tokenize(string $command): array
    {
        \preg_match_all('/"([^"]*)"|\'([^\']*)\'|([^\\s]+)/', $command, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            foreach ([1, 2, 3] as $index) {
                if (!isset($match[$index]) || $match[$index] === '') {
                    continue;
                }
                $tokens[] = \trim((string)$match[$index], " \t\n\r\0\x0B\"'");
                break;
            }
        }
        return $tokens;
    }

    private function normalizePath(string $path): string
    {
        $path = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, \trim($path, " \t\n\r\0\x0B\"'"));
        $path = \rtrim($path, DIRECTORY_SEPARATOR);
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    /** @return array<string,mixed>|null */
    private function readProcessManifest(): ?array
    {
        if (!\is_file($this->processManifest)) {
            return null;
        }
        if (\is_link($this->processManifest)) {
            throw new \RuntimeException('PID-bound Nginx process identity must not be a symlink.');
        }
        $decoded = \json_decode((string)@\file_get_contents($this->processManifest), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('PID-bound Nginx process identity is invalid.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $record */
    private function publishProcessManifest(array $record): void
    {
        $directory = \dirname($this->processManifest);
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create the Nginx process identity directory.');
        }
        if (\is_link($this->processManifest)) {
            throw new \RuntimeException('PID-bound Nginx process identity target is unsafe.');
        }
        $payload = \json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $temporary = $this->processManifest . '.tmp.' . \bin2hex(\random_bytes(6));
        if (@\file_put_contents($temporary, $payload, LOCK_EX) !== \strlen($payload)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to stage the PID-bound Nginx process identity.');
        }
        @\chmod($temporary, 0600);
        $this->inheritDirectoryOwnership($temporary, \dirname($this->processManifest));
        if (!@\rename($temporary, $this->processManifest)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish the PID-bound Nginx process identity.');
        }
        @\chmod($this->processManifest, 0600);
    }

    private function withLock(callable $operation): mixed
    {
        $directory = \dirname($this->processManifest);
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create the Nginx process identity directory.');
        }
        $lockFile = $this->processManifest . '.lock';
        if (\is_link($lockFile)) {
            throw new \RuntimeException('Nginx process identity lock target is unsafe.');
        }
        $lock = @\fopen($lockFile, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock the Nginx process identity.');
        }
        @\chmod($lockFile, 0600);
        $this->inheritDirectoryOwnership($lockFile, $directory);
        try {
            return $operation();
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * Promotion rollback runs as the host administrator but restores a
     * project-owned runtime. A root-owned 0600 identity file would make the
     * recovered Nginx impossible for the project user to inspect or stop.
     */
    private function inheritDirectoryOwnership(string $file, string $directory): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $owner = @\stat($directory);
        if (!\is_array($owner)
            || !\is_int($owner['uid'] ?? null)
            || !\is_int($owner['gid'] ?? null)
            || !@\chown($file, (int)$owner['uid'])
            || !@\chgrp($file, (int)$owner['gid'])
        ) {
            throw new \RuntimeException(
                'Unable to preserve project ownership for the Nginx process identity.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        return $value;
    }

    /** @param array<string,string> $expected */
    private function failure(int $pid, string $reason, array $expected = []): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'pid' => $pid,
            'role' => $this->role,
            'binary_sha256' => (string)($expected['binary_sha256'] ?? ''),
            'runtime_generation' => (string)($expected['runtime_generation'] ?? ''),
            'adopted' => false,
        ];
    }
}
