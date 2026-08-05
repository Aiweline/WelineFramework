<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Resolves one fail-closed, opaque identity for the current host boot.
 *
 * Persisted monotonic timestamps are comparable only while this identity is
 * unchanged.  The raw platform value is deliberately hashed before it enters
 * project-derived state.
 */
final class GatewayHostBootIdentity
{
    private static ?string $currentBootId = null;

    public static function current(): string
    {
        return self::$currentBootId ??= \hash(
            'sha256',
            'wls-gateway-host-boot/1|' . self::platformToken(),
        );
    }

    /**
     * Exact raw platform token hashed by every PHP and Native component with
     * the `wls-gateway-host-boot/1|` domain separator.
     */
    public static function platformToken(): string
    {
        if (\PHP_OS_FAMILY === 'Linux') {
            $bootId = \strtolower(\trim(self::readStableLinuxBootId()));
            if (\preg_match(
                '/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/D',
                $bootId,
            ) === 1) {
                return $bootId;
            }
            throw new \RuntimeException('Linux boot identity is unavailable.');
        }

        if (\PHP_OS_FAMILY === 'Darwin') {
            $bootTime = self::readDarwinBootTime();
            if (\preg_match(
                '/\A\{\s*sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)\s*\}/',
                $bootTime,
                $matches,
            ) === 1
            ) {
                return 'darwin-' . (int)$matches[1] . '-' . (int)$matches[2];
            }
            throw new \RuntimeException('macOS boot identity is unavailable.');
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            if (!\class_exists(\FFI::class, false)) {
                throw new \RuntimeException(
                    'Windows native boot identity requires the PHP FFI capability.',
                );
            }
            try {
                $ffi = \FFI::cdef(
                    <<<'CDEF'
typedef long NTSTATUS;
typedef unsigned long ULONG;
typedef unsigned long long ULONGLONG;
typedef long long LONGLONG;
typedef struct {
    LONGLONG boot_time;
    LONGLONG current_time;
    LONGLONG time_zone_bias;
    ULONG time_zone_id;
    ULONG reserved;
    ULONGLONG boot_time_bias;
    ULONGLONG sleep_time_bias;
} WLS_SYSTEM_TIMEOFDAY_INFORMATION;
NTSTATUS NtQuerySystemInformation(
    int information_class,
    void *information,
    ULONG information_length,
    ULONG *return_length
);
CDEF,
                    'ntdll.dll',
                );
                $information = $ffi->new('WLS_SYSTEM_TIMEOFDAY_INFORMATION');
                $status = (int)$ffi->NtQuerySystemInformation(
                    3,
                    \FFI::addr($information),
                    \FFI::sizeof($information),
                    null,
                );
                $bootTime = (int)$information->boot_time;
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Windows native boot identity query failed.',
                    0,
                    $throwable,
                );
            }
            if ($status < 0 || $bootTime <= 0) {
                throw new \RuntimeException('Windows boot identity is unavailable.');
            }

            return 'windows-' . \sprintf('%016x', $bootTime);
        }

        throw new \RuntimeException(
            'Unsupported platform for WLS Gateway boot identity: ' . \PHP_OS_FAMILY,
        );
    }

    private static function readStableLinuxBootId(): string
    {
        $path = '/proc/sys/kernel/random/boot_id';
        $named = @\lstat($path);
        if (!\is_array($named)
            || \is_link($path)
            || ((((int)($named['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($named['nlink'] ?? 0) !== 1
        ) {
            return '';
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return '';
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened)
                || ((((int)($opened['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($opened['nlink'] ?? 0) !== 1
                || (int)($opened['dev'] ?? -1) !== (int)($named['dev'] ?? -2)
                || (int)($opened['ino'] ?? -1) !== (int)($named['ino'] ?? -2)
            ) {
                return '';
            }
            $contents = @\stream_get_contents($handle, 129);
        } finally {
            @\fclose($handle);
        }
        $after = @\lstat($path);
        if (!\is_string($contents)
            || \strlen($contents) > 128
            || !\is_array($after)
            || \is_link($path)
            || (int)($after['dev'] ?? -1) !== (int)($named['dev'] ?? -2)
            || (int)($after['ino'] ?? -1) !== (int)($named['ino'] ?? -2)
        ) {
            return '';
        }

        return $contents;
    }

    /**
     * Read kern.boottime.
     *
     * Prefer in-process FFI sysctlbyname: Master may inherit many FDs, and the
     * bounded process-group helper can then return a polluted or mismatched
     * payload that still looks like a timeval. A wrong host_boot_id baked into
     * the Master lease makes every Worker fail closed with "another host boot".
     * Subprocess probes are fallbacks only for builds without FFI, and must
     * agree across two independent reads before they may mint lease identity.
     */
    private static function readDarwinBootTime(): string
    {
        $pattern = '/\A\{\s*sec\s*=\s*\d+,\s*usec\s*=\s*\d+\s*\}/';
        $viaFfi = self::readDarwinBootTimeViaFfi();
        if ($viaFfi !== '' && \preg_match($pattern, $viaFfi) === 1) {
            return $viaFfi;
        }

        $bounded = '';
        try {
            $result = GatewayBoundedCommandRunner::run([
                '/usr/sbin/sysctl',
                '-n',
                'kern.boottime',
            ], 3.0);
            $bounded = \trim((string)($result['stdout'] ?? $result['output'] ?? ''));
        } catch (\Throwable) {
            $bounded = '';
        }
        $direct = self::readDarwinBootTimeDirect();
        $boundedToken = self::normalizeDarwinBootTimeToken($bounded);
        $directToken = self::normalizeDarwinBootTimeToken($direct);
        if ($boundedToken !== ''
            && $directToken !== ''
            && \hash_equals($boundedToken, $directToken)
        ) {
            // Prefer the direct probe's raw payload: the bounded runner can
            // append inherited FD noise after a still-parseable timeval.
            return $direct !== '' ? $direct : $bounded;
        }

        // Single-source subprocess evidence is not lease-safe under Master FD
        // inheritance. Fail closed so callers restart instead of baking a
        // polluted host_boot_id that permanently rejects every Worker.
        return '';
    }

    /**
     * Collapse `{ sec = N, usec = M } …optional noise` to a comparable token.
     */
    private static function normalizeDarwinBootTimeToken(string $bootTime): string
    {
        if ($bootTime === ''
            || \preg_match(
                '/\A\{\s*sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)\s*\}/',
                $bootTime,
                $matches,
            ) !== 1
        ) {
            return '';
        }

        return 'darwin-' . (int)$matches[1] . '-' . (int)$matches[2];
    }

    /**
     * In-process kern.boottime via libc sysctlbyname (no child process).
     */
    private static function readDarwinBootTimeViaFfi(): string
    {
        if (!\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || PHP_INT_SIZE < 8
        ) {
            return '';
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true)) {
            return '';
        }
        try {
            $ffi = \FFI::cdef(
                <<<'CDEF'
typedef long time_t;
typedef int suseconds_t;
typedef unsigned long size_t;
struct timeval {
    time_t tv_sec;
    suseconds_t tv_usec;
};
int sysctlbyname(const char *name, void *oldp, size_t *oldlenp, void *newp, size_t newlen);
CDEF,
                'libc.dylib',
            );
            $timeval = $ffi->new('struct timeval');
            $length = $ffi->new('size_t');
            $length->cdata = \FFI::sizeof($timeval);
            $status = $ffi->sysctlbyname(
                'kern.boottime',
                \FFI::addr($timeval),
                \FFI::addr($length),
                null,
                0,
            );
            $status = self::ffiScalarInt($status);
            $seconds = self::ffiScalarInt($timeval->tv_sec);
            $microseconds = self::ffiScalarInt($timeval->tv_usec);
            if ($status !== 0 || $seconds < 1 || $microseconds < 0 || $microseconds > 999_999) {
                return '';
            }

            return '{ sec = ' . $seconds . ', usec = ' . $microseconds . ' }';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function ffiScalarInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if ($value instanceof \FFI\CData) {
            return (int)$value->cdata;
        }

        return (int)$value;
    }

    private static function readDarwinBootTimeDirect(): string
    {
        if (!\function_exists('proc_open')
            || !\is_file('/usr/sbin/sysctl')
            || !\is_executable('/usr/sbin/sysctl')
        ) {
            return '';
        }
        $pipes = [];
        $process = @\proc_open(
            ['/usr/sbin/sysctl', '-n', 'kern.boottime'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return '';
        }
        $stdout = '';
        try {
            if (isset($pipes[1]) && \is_resource($pipes[1])) {
                $chunk = @\stream_get_contents($pipes[1], 4096);
                if (\is_string($chunk)) {
                    $stdout = $chunk;
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            @\proc_close($process);
        }

        return \trim($stdout);
    }

    public static function validate(string $bootId): string
    {
        $bootId = \strtolower(\trim($bootId));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $bootId) !== 1) {
            throw new \InvalidArgumentException(
                'WLS Gateway host boot identity must be a SHA-256 digest.',
            );
        }

        return $bootId;
    }
}
