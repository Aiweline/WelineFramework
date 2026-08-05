<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Verifies that an Nginx PID still belongs to one immutable runtime manifest.
 *
 * The caller remains responsible for proving the PID is alive. This class
 * fences all lifecycle actions by exact argv paths, binary digest, runtime
 * generation and a PID-bound process identity record.
 */
final class NginxProcessIdentity
{
    public const SCHEMA_VERSION = 2;
    private const MAX_MANIFEST_BYTES = 16 * 1024 * 1024;
    private const MAX_BINARY_BYTES = 1024 * 1024 * 1024;

    private static ?\FFI $darwinLibproc = null;
    private static bool $darwinLibprocUnavailable = false;

    public function __construct(
        private readonly string $role,
        private readonly string $binary,
        private readonly string $prefix,
        private readonly string $config,
        private readonly string $installManifest,
        private readonly string $processManifest,
        private readonly ?\Closure $processStartIdentityResolver = null,
    ) {
        if (\preg_match('/\A[a-z][a-z0-9._-]{1,63}\z/D', $role) !== 1) {
            throw new \InvalidArgumentException('Nginx process role is invalid.');
        }
        foreach ([$binary, $prefix, $config, $installManifest, $processManifest] as $path) {
            if (!$this->isAbsolutePath($path)
                || \str_contains($path, "\0")
                || \preg_match('#(?:^|[\\/])\.\.?([\\/]|$)#D', $path) === 1
            ) {
                throw new \InvalidArgumentException(
                    'Nginx process identity paths must be canonical absolute paths.'
                );
            }
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
            $processStartIdentity = $this->processStartIdentity($pid);
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

        try {
            if (!\hash_equals(
                $processStartIdentity,
                $this->processStartIdentity($pid),
            )) {
                return $this->failure(
                    $pid,
                    'Nginx process identity changed during attestation.',
                    $expected,
                );
            }
        } catch (\Throwable $throwable) {
            return $this->failure($pid, $throwable->getMessage(), $expected);
        }

        return $this->withLock(function () use (
            $pid,
            $expected,
            $processStartIdentity,
            $allowLegacyAdoption,
        ): array {
            try {
                $lockedProcessStartIdentity = $this->processStartIdentity($pid);
            } catch (\Throwable $throwable) {
                return $this->failure($pid, $throwable->getMessage(), $expected);
            }
            if (!\hash_equals($processStartIdentity, $lockedProcessStartIdentity)) {
                return $this->failure(
                    $pid,
                    'Nginx process identity changed before the attestation lock was acquired.',
                    $expected,
                );
            }
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
                    'process_start_identity' => $processStartIdentity,
                    'adopted_from_legacy' => true,
                    'recorded_at' => \gmdate(DATE_ATOM),
                ];
                $this->publishProcessManifest($record);
                $adopted = true;
            } elseif (($record['schema_version'] ?? null) === 1) {
                if (!$allowLegacyAdoption
                    || !$this->legacyProcessRecordMatches($record, $pid, $expected)
                ) {
                    return $this->failure(
                        $pid,
                        'Legacy PID-bound Nginx process identity cannot be safely migrated.',
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
                    'process_start_identity' => $processStartIdentity,
                    'adopted_from_process_identity_schema' => 1,
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
                'process_start_identity',
            ] as $field) {
                $expectedValue = $field === 'process_start_identity'
                    ? $processStartIdentity
                    : (string)$expected[$field];
                if (!\is_string($record[$field] ?? null)
                    || !\hash_equals($expectedValue, (string)$record[$field])
                ) {
                    return $this->failure(
                        $pid,
                        'PID-bound Nginx process identity field mismatch: ' . $field,
                        $expected,
                    );
                }
            }
            if (($record['schema_version'] ?? null) !== self::SCHEMA_VERSION
                || !\is_int($record['pid'] ?? null)
                || (int)$record['pid'] !== $pid
            ) {
                return $this->failure(
                    $pid,
                    'PID-bound Nginx process identity generation does not match.',
                    $expected,
                );
            }
            try {
                if (!\hash_equals(
                    $processStartIdentity,
                    $this->processStartIdentity($pid),
                )) {
                    return $this->failure(
                        $pid,
                        'Nginx process identity changed while its attestation was locked.',
                        $expected,
                    );
                }
            } catch (\Throwable $throwable) {
                return $this->failure($pid, $throwable->getMessage(), $expected);
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
            if ($record === null) {
                return null;
            }
            if (!\in_array($record['schema_version'] ?? null, [1, self::SCHEMA_VERSION], true)
                || !\is_int($record['pid'] ?? null)
                || (int)$record['pid'] < 1
            ) {
                throw new \RuntimeException('PID-bound Nginx process identity is malformed.');
            }
            return (int)$record['pid'];
        });
    }

    /** @return array{ok:bool,reason:string,binary_sha256:string,runtime_generation:string} */
    public function runtimeStatus(): array
    {
        try {
            $expected = $this->expectedRuntime();
            return [
                'ok' => true,
                'reason' => 'Nginx runtime manifest and binary are valid.',
                'binary_sha256' => $expected['binary_sha256'],
                'runtime_generation' => $expected['runtime_generation'],
            ];
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'reason' => $throwable->getMessage(),
                'binary_sha256' => '',
                'runtime_generation' => '',
            ];
        }
    }

    public function clear(int $expectedPid): void
    {
        $this->withLock(function () use ($expectedPid): null {
            $record = $this->readProcessManifest();
            if ($record === null) {
                return null;
            }
            if (!\is_int($record['pid'] ?? null)
                || (int)$record['pid'] !== $expectedPid
            ) {
                throw new \RuntimeException(
                    'Refusing to clear a PID-bound Nginx identity owned by another generation.'
                );
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $this->processManifest,
                'PID-bound Nginx process identity',
            )) {
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
        $manifestContents = GatewayProjectStateFilesystem::read(
            $this->installManifest,
            self::MAX_MANIFEST_BYTES,
            'Nginx install manifest',
        );
        $decoded = \json_decode($manifestContents, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Nginx install manifest is invalid.');
        }
        if (($decoded['schema_version'] ?? null) !== 2
            || !\is_string($decoded['role'] ?? null)
            || !\hash_equals($this->role, (string)$decoded['role'])
            || !\is_string($decoded['implementation_level'] ?? null)
            || !\hash_equals(
                'nginx-runtime-v2',
                (string)$decoded['implementation_level'],
            )
            || !\is_string($decoded['binary'] ?? null)
            || $this->normalizePath((string)$decoded['binary'])
                !== $this->normalizePath($this->binary)
        ) {
            throw new \RuntimeException(
                'Legacy Nginx must be upgraded to a WLS 2.0 runtime manifest before promotion.'
            );
        }
        $actualDigest = $this->stableFileHash($this->binary);
        $expectedDigest = \strtolower(\trim((string)($decoded['binary_sha256'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1) {
            throw new \RuntimeException(
                'Legacy Nginx must be upgraded to a binary-digest manifest before promotion.'
            );
        }
        if (!\hash_equals($expectedDigest, $actualDigest)) {
            throw new \RuntimeException('Nginx binary digest does not match its install manifest.');
        }
        $runtimeGeneration = \strtolower(\trim((string)($decoded['runtime_generation'] ?? '')));
        $generationSource = $decoded;
        unset($generationSource['runtime_generation']);
        $encoded = \json_encode(
            $this->canonicalize($generationSource),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $calculatedGeneration = \hash('sha256', $encoded);
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
            || !\hash_equals($calculatedGeneration, $runtimeGeneration)
        ) {
            throw new \RuntimeException(
                'Nginx install manifest runtime generation failed integrity validation.'
            );
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
        if ($tokens === []) {
            return false;
        }
        // Linux /proc cmdline starts with the binary. Darwin ps often prefixes
        // "nginx: master process" before the same argv path.
        $binaryIndex = null;
        foreach ($tokens as $index => $token) {
            if ($this->normalizePath($token) === $binary) {
                $binaryIndex = $index;
                break;
            }
        }
        if ($binaryIndex === null) {
            return false;
        }
        $prefixMatches = 0;
        $configMatches = 0;
        $count = \count($tokens);
        for ($index = $binaryIndex + 1; $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '-p' && isset($tokens[$index + 1])) {
                if ($this->normalizePath($tokens[$index + 1]) !== $prefix) {
                    return false;
                }
                ++$prefixMatches;
            }
            if ($token === '-c' && isset($tokens[$index + 1])) {
                if ($this->normalizePath($tokens[$index + 1]) !== $config) {
                    return false;
                }
                ++$configMatches;
            }
        }
        return $prefixMatches === 1 && $configMatches === 1;
    }

    /** @param array<string,mixed> $record @param array<string,string> $expected */
    private function legacyProcessRecordMatches(array $record, int $pid, array $expected): bool
    {
        if (($record['schema_version'] ?? null) !== 1
            || !\is_int($record['pid'] ?? null)
            || (int)$record['pid'] !== $pid
            || \array_key_exists('process_start_identity', $record)
        ) {
            return false;
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
                return false;
            }
        }
        return true;
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
        if (!\file_exists($this->processManifest) && !\is_link($this->processManifest)) {
            return null;
        }
        $decoded = \json_decode(GatewayProjectStateFilesystem::read(
            $this->processManifest,
            self::MAX_MANIFEST_BYTES,
            'PID-bound Nginx process identity',
        ), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('PID-bound Nginx process identity is invalid.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $record */
    private function publishProcessManifest(array $record): void
    {
        $directory = \dirname($this->processManifest);
        $this->ensureProcessIdentityDirectory($directory);
        $payload = \json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        GatewayProjectStateFilesystem::atomicWrite(
            $this->processManifest,
            $payload,
            0600,
            $this->directoryOwnershipSeal($directory),
        );
    }

    private function withLock(callable $operation): mixed
    {
        $directory = \dirname($this->processManifest);
        $this->ensureProcessIdentityDirectory($directory);
        $lockFile = $this->processManifest . '.lock';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            static fn (): mixed => $operation(),
            $this->directoryOwnershipSeal($directory),
        );
    }

    /**
     * Promotion rollback runs as the host administrator but restores a
     * project-owned runtime. A root-owned 0600 identity file would make the
     * recovered Nginx impossible for the project user to inspect or stop.
     */
    private function directoryOwnershipSeal(string $directory): ?\Closure
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return null;
        }
        $owner = @\lstat($directory);
        if (!\is_array($owner)
            || !\is_int($owner['uid'] ?? null)
            || !\is_int($owner['gid'] ?? null)
        ) {
            throw new \RuntimeException(
                'Unable to preserve project ownership for the Nginx process identity.'
            );
        }
        $uid = (int)$owner['uid'];
        $gid = (int)$owner['gid'];
        return static function ($handle, string $path) use ($uid, $gid): void {
            $ownerOk = \function_exists('fchown')
                ? @\fchown($handle, $uid)
                : @\chown($path, $uid);
            $groupOk = \function_exists('fchgrp')
                ? @\fchgrp($handle, $gid)
                : @\chgrp($path, $gid);
            if (!$ownerOk || !$groupOk) {
                throw new \RuntimeException(
                    'Unable to preserve project ownership for the Nginx process identity.'
                );
            }
        };
    }

    private function ensureProcessIdentityDirectory(string $directory): void
    {
        if (!\is_dir($directory)
            && (!@\mkdir($directory, 0700) || !\is_dir($directory))
        ) {
            throw new \RuntimeException(
                'Unable to create the Nginx process identity directory.'
            );
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Nginx process identity directory is unsafe.');
        }
    }

    private function stableFileHash(string $file): string
    {
        $before = @\lstat($file);
        $size = \is_array($before) ? (int)($before['size'] ?? -1) : -1;
        if (!\is_array($before)
            || \is_link($file)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || $size < 1
            || $size > self::MAX_BINARY_BYTES
        ) {
            throw new \RuntimeException('Nginx binary is missing, linked, or oversized.');
        }
        $handle = @\fopen($file, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open the Nginx binary for attestation.');
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !$this->sameFileStatus($before, $opened)) {
                throw new \RuntimeException('Nginx binary changed before attestation.');
            }
            $context = \hash_init('sha256');
            $hashed = \hash_update_stream($context, $handle);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($file);
            if (!\is_int($hashed)
                || $hashed !== $size
                || !\is_array($after)
                || !\is_array($pathAfter)
                || !$this->sameFileStatus($opened, $after)
                || !$this->sameFileStatus($after, $pathAfter)
            ) {
                throw new \RuntimeException('Nginx binary changed during attestation.');
            }
            return \hash_final($context);
        } finally {
            @\fclose($handle);
        }
    }

    private function processStartIdentity(int $pid): string
    {
        if ($pid < 1) {
            throw new \RuntimeException('Nginx process start identity requires a positive PID.');
        }
        $raw = $this->processStartIdentityResolver !== null
            ? (string)($this->processStartIdentityResolver)($pid)
            : $this->platformProcessStartIdentity($pid);
        $raw = \trim($raw);
        if ($raw === '' || \strlen($raw) > 4096 || \str_contains($raw, "\0")) {
            throw new \RuntimeException('Nginx process start identity is unavailable.');
        }
        return \hash('sha256', \PHP_OS_FAMILY . "\0" . $pid . "\0" . $raw);
    }

    private function platformProcessStartIdentity(int $pid): string
    {
        if (\PHP_OS_FAMILY === 'Linux') {
            $startTicks = NginxChildProcessProbe::linuxProcessStartTicks($pid);
            if ($startTicks !== null && Processer::isRunningByPid($pid)) {
                return 'linux-start-ticks:' . $startTicks;
            }
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            $startTimeval = $this->darwinProcessStartTimeval($pid);
            if ($startTimeval !== null && Processer::isRunningByPid($pid)) {
                return 'darwin-start-timeval:' . $startTimeval;
            }
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $powershell = $this->windowsPowerShellPath();
            if ($powershell !== null) {
                $script = '$ErrorActionPreference="Stop";'
                    . '$p=Get-CimInstance Win32_Process -Filter "ProcessId=' . $pid . '";'
                    . 'if($null -eq $p){exit 3};'
                    . '[Console]::Out.Write($p.CreationDate.ToUniversalTime().Ticks)';
                $utf16 = '';
                for ($offset = 0, $length = \strlen($script); $offset < $length; ++$offset) {
                    $utf16 .= $script[$offset] . "\0";
                }
                $result = GatewayBoundedCommandRunner::run([
                    $powershell,
                    '-NoLogo',
                    '-NoProfile',
                    '-NonInteractive',
                    '-ExecutionPolicy',
                    'Bypass',
                    '-EncodedCommand',
                    \base64_encode($utf16),
                ], 10.0);
                $ticks = \trim((string)($result['output'] ?? ''));
                if ((int)($result['code'] ?? 1) === 0
                    && \preg_match('/\A[0-9]+\z/D', $ticks) === 1
                ) {
                    return 'windows-creation-ticks:' . $ticks;
                }
            }
        }
        $info = Processer::getProcessInfo($pid);
        $startedAt = \trim((string)($info['start_time'] ?? ''));
        $normalizedStart = $this->normalizeProcessStartTime($startedAt);
        if (($info['exists'] ?? false) === true && $normalizedStart !== null) {
            return 'process-start-time:' . $normalizedStart;
        }
        throw new \RuntimeException('Unable to attest the Nginx process start identity.');
    }

    /**
     * Accept a clean lstart, or recover a trailing lstart when earlier numeric
     * columns bled into the field (legacy Processer / multi-word COMM rows).
     */
    private function normalizeProcessStartTime(string $startedAt): ?string
    {
        $startedAt = \trim($startedAt);
        if ($startedAt === '') {
            return null;
        }
        if (\preg_match('/\A(?:[A-Za-z]{3}\s+){2}\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4}\z/D', $startedAt) === 1) {
            return \preg_replace('/\s+/', ' ', $startedAt) ?? $startedAt;
        }
        if (\preg_match(
            '/((?:[A-Za-z]{3}\s+){2}\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4})\s*\z/D',
            $startedAt,
            $matches,
        ) === 1) {
            return \preg_replace('/\s+/', ' ', $matches[1]) ?? $matches[1];
        }

        return null;
    }

    private function darwinProcessStartTimeval(int $pid): ?string
    {
        if ($pid <= 0
            || !\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || PHP_INT_SIZE < 8
        ) {
            return null;
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true)) {
            return null;
        }
        try {
            $ffi = $this->darwinLibprocFfi();
            if ($ffi === null) {
                return null;
            }
            $info = $ffi->new('struct proc_bsdinfo');
            $size = \FFI::sizeof($info);
            $read = (int)$ffi->proc_pidinfo($pid, 3, 0, \FFI::addr($info), $size);
            if ($read !== $size || (int)$info->pbi_pid !== $pid) {
                return null;
            }
            $seconds = (int)$info->pbi_start_tvsec;
            $microseconds = (int)$info->pbi_start_tvusec;
            if ($seconds < 1 || $microseconds < 0 || $microseconds > 999_999) {
                return null;
            }

            return $seconds . ':' . $microseconds;
        } catch (\Throwable) {
            return null;
        }
    }

    private function darwinLibprocFfi(): ?\FFI
    {
        if (self::$darwinLibprocUnavailable) {
            return null;
        }
        if (self::$darwinLibproc instanceof \FFI) {
            return self::$darwinLibproc;
        }
        try {
            self::$darwinLibproc = \FFI::cdef(
                <<<'CDEF'
typedef unsigned int uint32_t;
typedef signed int int32_t;
typedef unsigned long long uint64_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
struct proc_bsdinfo {
    uint32_t pbi_flags;
    uint32_t pbi_status;
    uint32_t pbi_xstatus;
    uint32_t pbi_pid;
    uint32_t pbi_ppid;
    uid_t pbi_uid;
    gid_t pbi_gid;
    uid_t pbi_ruid;
    gid_t pbi_rgid;
    uid_t pbi_svuid;
    gid_t pbi_svgid;
    uint32_t rfu_1;
    char pbi_comm[16];
    char pbi_name[32];
    uint32_t pbi_nfiles;
    uint32_t pbi_pgid;
    uint32_t pbi_pjobc;
    uint32_t e_tdev;
    uint32_t e_tpgid;
    int32_t pbi_nice;
    uint64_t pbi_start_tvsec;
    uint64_t pbi_start_tvusec;
};
int proc_pidinfo(int pid, int flavor, uint64_t arg, void *buffer, int buffersize);
CDEF,
                '/usr/lib/libproc.dylib',
            );

            return self::$darwinLibproc;
        } catch (\Throwable) {
            self::$darwinLibprocUnavailable = true;
            self::$darwinLibproc = null;

            return null;
        }
    }

    private function windowsPowerShellPath(): ?string
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        $systemRoot = \rtrim((string)(\getenv('SystemRoot') ?: \getenv('windir') ?: 'C:\\Windows'), '/\\');
        if ($systemRoot === '' || \str_contains($systemRoot, "\0")) {
            return null;
        }
        foreach (['System32', 'Sysnative'] as $systemDirectory) {
            $candidate = $systemRoot . DIRECTORY_SEPARATOR . $systemDirectory
                . DIRECTORY_SEPARATOR . 'WindowsPowerShell' . DIRECTORY_SEPARATOR . 'v1.0'
                . DIRECTORY_SEPARATOR . 'powershell.exe';
            if (\is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameFileStatus(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return \preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+)/D', $path) === 1;
        }
        return \str_starts_with($path, '/');
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
