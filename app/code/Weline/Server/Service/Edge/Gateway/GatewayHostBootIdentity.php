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
    private static ?string $resolvedPlatformToken = null;

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
        return self::$resolvedPlatformToken ??= self::resolvePlatformToken();
    }

    /**
     * Resolve the raw token once per PHP process. A host reboot terminates the
     * process, so a successful value cannot outlive the boot it identifies.
     */
    private static function resolvePlatformToken(): string
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
                '/\A\{\s*sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)\s*\}\z/D',
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
        $pattern = '/\A\{\s*sec\s*=\s*\d+,\s*usec\s*=\s*\d+\s*\}\z/D';
        $viaFfi = self::readDarwinBootTimeViaFfi();
        if ($viaFfi !== '' && \preg_match($pattern, $viaFfi) === 1) {
            return $viaFfi;
        }

        $first = self::readDarwinBootTimeBounded();
        $second = self::readDarwinBootTimeBounded();
        $firstToken = self::normalizeDarwinBootTimeToken($first);
        $secondToken = self::normalizeDarwinBootTimeToken($second);
        if ($firstToken !== ''
            && $secondToken !== ''
            && \hash_equals($firstToken, $secondToken)
        ) {
            return $second;
        }

        // Single-source subprocess evidence is not lease-safe under Master FD
        // inheritance. Fail closed so callers restart instead of baking a
        // polluted host_boot_id that permanently rejects every Worker.
        return '';
    }

    /**
     * Collapse one exact `{ sec = N, usec = M }` record to a comparable token.
     */
    private static function normalizeDarwinBootTimeToken(string $bootTime): string
    {
        if ($bootTime === ''
            || \preg_match(
                '/\A\{\s*sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)\s*\}\z/D',
                $bootTime,
                $matches,
            ) !== 1
        ) {
            return '';
        }

        $seconds = (int)$matches[1];
        $microseconds = (int)$matches[2];
        if ($seconds < 1 || $microseconds < 0 || $microseconds > 999_999) {
            return '';
        }

        return 'darwin-' . $seconds . '-' . $microseconds;
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

    private static function readDarwinBootTimeBounded(): string
    {
        if (!\is_file('/usr/sbin/sysctl')
            || !\is_executable('/usr/sbin/sysctl')
        ) {
            return '';
        }
        try {
            $result = GatewayBoundedCommandRunner::run([
                '/usr/sbin/sysctl',
                '-n',
                'kern.boottime',
            ], 3.0);
            if (($result['truncated'] ?? true) === true
                || !\in_array((int)($result['code'] ?? 1), [0, 125], true)
            ) {
                return '';
            }
            $stdout = \trim((string)($result['stdout'] ?? ''));
            if (\strlen($stdout) > 4096
                || \preg_match(
                    '/\A\{\s*sec\s*=\s*([0-9]+),\s*usec\s*=\s*([0-9]+)\s*\}'
                        . '(?:[ \t]+[A-Z][a-z]{2}[ \t]+[A-Z][a-z]{2}[ \t]+'
                        . '[0-9]{1,2}[ \t]+[0-9]{2}:[0-9]{2}:[0-9]{2}[ \t]+'
                        . '[0-9]{4})?\z/D',
                    $stdout,
                    $matches,
                ) !== 1
            ) {
                return '';
            }
            // macOS appends ctime(3) text to kern.boottime on some releases.
            // Validate that suffix exactly, then return only the canonical
            // timeval so the two-read comparison cannot absorb pipe noise.
            $canonical = '{ sec = ' . (string)$matches[1]
                . ', usec = ' . (string)$matches[2] . ' }';
            return self::normalizeDarwinBootTimeToken($canonical) !== ''
                ? $canonical
                : '';
        } catch (\Throwable) {
            return '';
        }
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
