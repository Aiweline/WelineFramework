<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

/** Bounded, shell-free logical CPU probe shared by WLS runtime policies. */
final class SystemCpuProbe
{
    private const MAX_CPU_COUNT = 256;
    private const COMMAND_TIMEOUT_SECONDS = 0.5;
    private const FALLBACK_CPU_COUNT = 4;
    private const MAX_CPU_LIST_BYTES = 4096;
    private const MAX_PROC_STAT_BYTES = 262_144;

    public static function logicalCount(int $fallback = self::FALLBACK_CPU_COUNT): int
    {
        $fallback = \min(self::MAX_CPU_COUNT, \max(1, $fallback));
        $candidates = [];
        if (\function_exists('swoole_cpu_num')) {
            $count = (int)\swoole_cpu_num();
            if ($count > 0) {
                $candidates[] = \min(self::MAX_CPU_COUNT, $count);
            }
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $environmentCount = self::parseCount((string)\getenv('NUMBER_OF_PROCESSORS'));
            if ($environmentCount !== null) {
                $candidates[] = $environmentCount;
            }

            return $candidates !== [] ? \min($candidates) : $fallback;
        }
        if (\PHP_OS_FAMILY === 'Linux') {
            foreach ([
                '/proc/self/status',
                '/sys/fs/cgroup/cpuset.cpus.effective',
                '/sys/fs/cgroup/cpuset/cpuset.cpus',
            ] as $path) {
                $value = self::readBoundedFile($path, self::MAX_CPU_LIST_BYTES);
                if ($value === null) {
                    continue;
                }
                if ($path === '/proc/self/status') {
                    if (\preg_match('/^Cpus_allowed_list:\s*([^\r\n]+)$/m', $value, $match) !== 1) {
                        continue;
                    }
                    $value = (string)$match[1];
                }
                $count = self::parseCpuList($value);
                if ($count !== null) {
                    $candidates[] = $count;
                }
            }
            $procStat = self::readBoundedFile('/proc/stat', self::MAX_PROC_STAT_BYTES);
            $procCount = \is_string($procStat) ? self::parseProcStat($procStat) : null;
            if ($procCount !== null) {
                $candidates[] = $procCount;
            }
            if ($candidates !== []) {
                return \min($candidates);
            }
        }
        $commands = \PHP_OS_FAMILY === 'Darwin'
            ? [['/usr/sbin/sysctl', '-n', 'hw.ncpu']]
            : [
                ['/usr/bin/nproc'],
                ['/bin/nproc'],
                ['/usr/bin/getconf', '_NPROCESSORS_ONLN'],
            ];
        foreach ($commands as $command) {
            if (!\is_file($command[0]) || !\is_executable($command[0])) {
                continue;
            }
            try {
                $result = GatewayBoundedCommandRunner::run(
                    $command,
                    self::COMMAND_TIMEOUT_SECONDS,
                );
            } catch (\Throwable) {
                continue;
            }
            if ((int)($result['code'] ?? 1) !== 0) {
                continue;
            }
            $count = self::parseCount((string)($result['output'] ?? ''));
            if ($count !== null) {
                $candidates[] = $count;
                break;
            }
        }

        return $candidates !== [] ? \min($candidates) : $fallback;
    }

    private static function parseCount(string $value): ?int
    {
        $value = \trim($value);
        if (\preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }
        $count = (int)$value;

        return $count > 0
            ? \min(self::MAX_CPU_COUNT, $count)
            : null;
    }

    private static function parseCpuList(string $value): ?int
    {
        $value = \trim($value);
        if ($value === '' || \strlen($value) > self::MAX_CPU_LIST_BYTES) {
            return null;
        }
        $tokens = \explode(',', $value);
        if (\count($tokens) > 1024) {
            return null;
        }
        $ranges = [];
        foreach ($tokens as $token) {
            $token = \trim($token);
            if (\preg_match('/\A([0-9]+)(?:-([0-9]+))?\z/D', $token, $match) !== 1) {
                return null;
            }
            $start = (int)$match[1];
            $end = isset($match[2]) && $match[2] !== '' ? (int)$match[2] : $start;
            if ($start > $end || $end > 1_048_575) {
                return null;
            }
            $ranges[] = [$start, $end];
        }
        \usort($ranges, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $count = 0;
        $currentStart = -1;
        $currentEnd = -1;
        foreach ($ranges as [$start, $end]) {
            if ($currentStart < 0) {
                [$currentStart, $currentEnd] = [$start, $end];
                continue;
            }
            if ($start <= $currentEnd + 1) {
                $currentEnd = \max($currentEnd, $end);
                continue;
            }
            $count += $currentEnd - $currentStart + 1;
            if ($count >= self::MAX_CPU_COUNT) {
                return self::MAX_CPU_COUNT;
            }
            [$currentStart, $currentEnd] = [$start, $end];
        }
        if ($currentStart >= 0) {
            $count += $currentEnd - $currentStart + 1;
        }

        return $count > 0 ? \min(self::MAX_CPU_COUNT, $count) : null;
    }

    private static function parseProcStat(string $contents): ?int
    {
        $matches = [];
        $count = \preg_match_all('/^cpu([0-9]+)\s/m', $contents, $matches);
        if (!\is_int($count) || $count < 1) {
            return null;
        }
        $unique = \array_unique($matches[1] ?? []);

        return \count($unique) === $count
            ? \min(self::MAX_CPU_COUNT, $count)
            : null;
    }

    private static function readBoundedFile(string $path, int $maximumBytes): ?string
    {
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
        } finally {
            @\fclose($handle);
        }

        return \is_string($contents) && \strlen($contents) <= $maximumBytes
            ? $contents
            : null;
    }
}
