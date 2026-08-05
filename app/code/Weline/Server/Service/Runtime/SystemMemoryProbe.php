<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

/** Bounded, shell-free physical-memory probes used by long-lived Workers. */
final class SystemMemoryProbe
{
    private const MEMINFO_PATH = '/proc/meminfo';
    private const MAX_MEMINFO_BYTES = 65_536;
    private const COMMAND_TIMEOUT_SECONDS = 0.25;
    private const DARWIN_VM_STAT = '/usr/bin/vm_stat';
    private const DARWIN_SYSCTL = '/usr/sbin/sysctl';
    private const DEFAULT_TOTAL_BYTES = 4 * 1024 * 1024 * 1024;

    public static function freeBytes(): int
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return 0;
        }
        $meminfo = self::readMeminfo();
        $availableKb = self::meminfoKilobytes($meminfo, 'MemAvailable');
        if ($availableKb > 0) {
            return self::kilobytesToBytes($availableKb);
        }
        $fallbackKb = self::checkedSum([
            self::meminfoKilobytes($meminfo, 'MemFree'),
            self::meminfoKilobytes($meminfo, 'Cached'),
            self::meminfoKilobytes($meminfo, 'Buffers'),
        ]);
        if ($fallbackKb > 0) {
            return self::kilobytesToBytes($fallbackKb);
        }
        if (\PHP_OS_FAMILY !== 'Darwin') {
            return 0;
        }

        $output = self::run([self::DARWIN_VM_STAT]);
        if ($output === '') {
            return 0;
        }
        $pageSize = 4096;
        if (\preg_match('/page size of\s+([0-9]+)\s+bytes/i', $output, $match) === 1) {
            $candidate = self::boundedDecimal($match[1]);
            if ($candidate >= 4096 && $candidate <= 65_536) {
                $pageSize = $candidate;
            }
        }
        $pages = self::checkedSum([
            self::vmStatPages($output, 'Pages free'),
            self::vmStatPages($output, 'Pages inactive'),
            self::vmStatPages($output, 'Pages speculative'),
        ]);
        if ($pages <= 0 || $pages > \intdiv(\PHP_INT_MAX, $pageSize)) {
            return 0;
        }

        return $pages * $pageSize;
    }

    public static function totalBytes(): int
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return self::DEFAULT_TOTAL_BYTES;
        }
        $totalKb = self::meminfoKilobytes(self::readMeminfo(), 'MemTotal');
        if ($totalKb > 0) {
            return self::kilobytesToBytes($totalKb, self::DEFAULT_TOTAL_BYTES);
        }
        if (\PHP_OS_FAMILY !== 'Darwin') {
            return self::DEFAULT_TOTAL_BYTES;
        }
        $output = \trim(self::run([self::DARWIN_SYSCTL, '-n', 'hw.memsize']));
        $bytes = self::boundedDecimal($output);

        return $bytes > 0 ? $bytes : self::DEFAULT_TOTAL_BYTES;
    }

    private static function readMeminfo(): string
    {
        if (!\is_readable(self::MEMINFO_PATH) || \is_link(self::MEMINFO_PATH)) {
            return '';
        }
        $handle = @\fopen(self::MEMINFO_PATH, 'rb');
        if (!\is_resource($handle)) {
            return '';
        }
        try {
            $contents = @\stream_get_contents($handle, self::MAX_MEMINFO_BYTES + 1);
            return \is_string($contents)
                && \strlen($contents) <= self::MAX_MEMINFO_BYTES
                    ? $contents
                    : '';
        } finally {
            @\fclose($handle);
        }
    }

    private static function run(array $command): string
    {
        if (!\is_executable($command[0])) {
            return '';
        }
        try {
            $result = GatewayBoundedCommandRunner::run(
                $command,
                self::COMMAND_TIMEOUT_SECONDS,
            );
        } catch (\Throwable) {
            return '';
        }

        return (int)($result['code'] ?? 1) === 0
            ? (string)($result['output'] ?? '')
            : '';
    }

    private static function meminfoKilobytes(string $contents, string $field): int
    {
        if ($contents === ''
            || \preg_match('/^' . \preg_quote($field, '/') . ':\s*([0-9]+)\s*kB\s*$/mi', $contents, $match) !== 1
        ) {
            return 0;
        }

        return self::boundedDecimal($match[1]);
    }

    private static function vmStatPages(string $contents, string $field): int
    {
        if (\preg_match('/^' . \preg_quote($field, '/') . ':\s*([0-9,.]+)\s*$/mi', $contents, $match) !== 1) {
            return 0;
        }

        return self::boundedDecimal(\str_replace([',', '.'], '', $match[1]));
    }

    private static function boundedDecimal(string $value): int
    {
        $value = \trim($value);
        $maximum = (string)\PHP_INT_MAX;
        if ($value === ''
            || \preg_match('/\A[0-9]+\z/D', $value) !== 1
            || \strlen($value) > \strlen($maximum)
            || (\strlen($value) === \strlen($maximum)
                && \strcmp($value, $maximum) > 0)
        ) {
            return 0;
        }
        $number = (int)$value;

        return $number > 0 ? $number : 0;
    }

    private static function kilobytesToBytes(int $kilobytes, int $fallback = 0): int
    {
        if ($kilobytes < 1 || $kilobytes > \intdiv(\PHP_INT_MAX, 1024)) {
            return $fallback;
        }

        return $kilobytes * 1024;
    }

    /** @param list<int> $values */
    private static function checkedSum(array $values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            if ($value < 0 || $value > \PHP_INT_MAX - $sum) {
                return 0;
            }
            $sum += $value;
        }

        return $sum;
    }
}
