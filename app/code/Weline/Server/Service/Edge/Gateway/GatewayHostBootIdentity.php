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
            $bootSessionUuid = self::readDarwinBootSessionUuid();
            if ($bootSessionUuid !== '') {
                return 'darwin-' . $bootSessionUuid;
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

    private static function readDarwinBootSessionUuid(): string
    {
        $viaFfi = self::normalizeDarwinBootSessionUuid(
            self::readDarwinBootSessionUuidViaFfi(),
        );
        if ($viaFfi !== '') {
            return $viaFfi;
        }

        $first = self::normalizeDarwinBootSessionUuid(
            self::readDarwinBootSessionUuidBounded(),
        );
        $second = self::normalizeDarwinBootSessionUuid(
            self::readDarwinBootSessionUuidBounded(),
        );
        if ($first !== ''
            && $second !== ''
            && \hash_equals($first, $second)
        ) {
            return $first;
        }

        return '';
    }

    private static function normalizeDarwinBootSessionUuid(string $uuid): string
    {
        $uuid = \strtolower(\trim($uuid));

        return \preg_match(
            '/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/D',
            $uuid,
        ) === 1 ? $uuid : '';
    }

    private static function readDarwinBootSessionUuidViaFfi(): string
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
typedef unsigned long size_t;
int sysctlbyname(const char *name, void *oldp, size_t *oldlenp, void *newp, size_t newlen);
CDEF,
                'libc.dylib',
            );
            $uuid = $ffi->new('char[64]');
            $length = $ffi->new('size_t');
            $length->cdata = \FFI::sizeof($uuid);
            $status = $ffi->sysctlbyname(
                'kern.bootsessionuuid',
                $uuid,
                \FFI::addr($length),
                null,
                0,
            );
            if ((int)$status !== 0 || (int)$length->cdata !== 37) {
                return '';
            }

            return \FFI::string($uuid);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function readDarwinBootSessionUuidBounded(): string
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
                'kern.bootsessionuuid',
            ], 3.0);
            if (($result['truncated'] ?? true) === true
                || !\in_array((int)($result['code'] ?? 1), [0, 125], true)
            ) {
                return '';
            }
            $stdout = \trim((string)($result['stdout'] ?? ''));
            if (\strlen($stdout) > 64) {
                return '';
            }

            return $stdout;
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
