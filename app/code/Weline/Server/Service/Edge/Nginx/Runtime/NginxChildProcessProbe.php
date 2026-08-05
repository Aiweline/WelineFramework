<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

/**
 * Bounded process-table probes shared by legacy and host-managed Nginx paths.
 */
final class NginxChildProcessProbe
{
    private const MAX_PROC_CHILDREN_BYTES = 64 * 1024;
    private const MAX_PROC_CMDLINE_BYTES = 64 * 1024;
    private const MAX_PROC_STAT_BYTES = 16 * 1024;
    private const MAX_CHILD_PROCESSES = 4096;
    private const PROCESS_TABLE_TIMEOUT_SECONDS = 2.0;

    /** @return list<int>|null */
    public static function workerPids(int $masterPid): ?array
    {
        if ($masterPid < 1) {
            return null;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        if (\PHP_OS_FAMILY === 'Linux') {
            $masterBefore = self::linuxStatIdentity($masterPid);
            if ($masterBefore === null) {
                return null;
            }
            $children = self::readPseudoFile(
                '/proc/' . $masterPid . '/task/' . $masterPid . '/children',
                self::MAX_PROC_CHILDREN_BYTES,
            );
            if ($children !== null) {
                $childPids = self::parsePidList($children);
                if ($childPids === null) {
                    return null;
                }
                $workers = [];
                foreach ($childPids as $childPid) {
                    $before = self::linuxStatIdentity($childPid);
                    if ($before === null || $before['ppid'] !== $masterPid) {
                        return null;
                    }
                    $command = self::readPseudoFile(
                        '/proc/' . $childPid . '/cmdline',
                        self::MAX_PROC_CMDLINE_BYTES,
                    );
                    if ($command === null) {
                        return null;
                    }
                    $after = self::linuxStatIdentity($childPid);
                    if ($after === null
                        || $after['ppid'] !== $masterPid
                        || !\hash_equals($before['start_ticks'], $after['start_ticks'])
                    ) {
                        return null;
                    }
                    $title = \str_replace("\0", ' ', $command);
                    if (\str_contains(\strtolower($title), 'nginx: worker process')) {
                        $workers[$childPid] = true;
                    }
                }
                $childrenAfter = self::readPseudoFile(
                    '/proc/' . $masterPid . '/task/' . $masterPid . '/children',
                    self::MAX_PROC_CHILDREN_BYTES,
                );
                $masterAfter = self::linuxStatIdentity($masterPid);
                if ($masterAfter === null
                    || !\hash_equals($masterBefore['start_ticks'], $masterAfter['start_ticks'])
                    || !\is_string($childrenAfter)
                    || self::parsePidList($childrenAfter) !== $childPids
                ) {
                    return null;
                }

                return self::sortedPids($workers);
            }
            return null;
        }

        $ps = self::processTableExecutable();
        $pgrep = self::childListExecutable();
        if ($ps === null || $pgrep === null) {
            return null;
        }
        $firstChildren = self::posixChildPids($pgrep, $masterPid);
        if ($firstChildren === null) {
            return null;
        }
        if ($firstChildren === []) {
            return [];
        }
        $result = GatewayBoundedCommandRunner::run([
            $ps,
            '-p',
            \implode(',', $firstChildren),
            '-o',
            'pid=,ppid=,command=',
        ], self::PROCESS_TABLE_TIMEOUT_SECONDS);
        if ((int)($result['code'] ?? 1) !== 0) {
            return null;
        }
        $lines = \preg_split('/\r?\n/', (string)($result['output'] ?? '')) ?: [];
        if (\count($lines) > self::MAX_CHILD_PROCESSES) {
            return null;
        }
        $workers = [];
        $seen = [];
        foreach ($lines as $line) {
            if (\trim($line) === '') {
                continue;
            }
            if (\preg_match('/\A\s*([1-9][0-9]*)\s+([1-9][0-9]*)\s+(.+)\z/D', $line, $match) !== 1
                || (int)$match[2] !== $masterPid
            ) {
                return null;
            }
            $pid = (int)$match[1];
            $seen[$pid] = true;
            if (\str_contains(\strtolower((string)$match[3]), 'nginx: worker process')) {
                $workers[$pid] = true;
            }
        }
        $secondChildren = self::posixChildPids($pgrep, $masterPid);
        if ($secondChildren === null
            || $secondChildren !== $firstChildren
            || self::sortedPids($seen) !== $firstChildren
        ) {
            return null;
        }

        return self::sortedPids($workers);
    }

    public static function linuxProcessStartTicks(int $pid): ?string
    {
        if (\PHP_OS_FAMILY !== 'Linux' || $pid < 1) {
            return null;
        }
        return self::linuxStatIdentity($pid)['start_ticks'] ?? null;
    }

    /** @param array<int,bool> $pids @return list<int> */
    private static function sortedPids(array $pids): array
    {
        $result = \array_keys($pids);
        \sort($result, SORT_NUMERIC);

        return $result;
    }

    private static function processTableExecutable(): ?string
    {
        foreach (['/bin/ps', '/usr/bin/ps'] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function childListExecutable(): ?string
    {
        foreach (['/usr/bin/pgrep', '/bin/pgrep'] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<int>|null */
    private static function posixChildPids(string $pgrep, int $masterPid): ?array
    {
        $result = GatewayBoundedCommandRunner::run(
            [$pgrep, '-P', (string)$masterPid],
            self::PROCESS_TABLE_TIMEOUT_SECONDS,
        );
        $code = (int)($result['code'] ?? 1);
        if ($code === 1 && \trim((string)($result['output'] ?? '')) === '') {
            return [];
        }
        if ($code !== 0) {
            return null;
        }
        $tokens = \preg_split('/\s+/', \trim((string)($result['stdout'] ?? ''))) ?: [];
        if (\count($tokens) > self::MAX_CHILD_PROCESSES) {
            return null;
        }
        $pids = [];
        foreach ($tokens as $token) {
            if ($token === '' || \preg_match('/\A[1-9][0-9]*\z/D', $token) !== 1) {
                return null;
            }
            $pids[(int)$token] = true;
        }

        return self::sortedPids($pids);
    }

    /** @return list<int>|null */
    private static function parsePidList(string $contents): ?array
    {
        $contents = \trim($contents);
        if ($contents === '') {
            return [];
        }
        $tokens = \preg_split('/\s+/', $contents) ?: [];
        if (\count($tokens) > self::MAX_CHILD_PROCESSES) {
            return null;
        }
        $pids = [];
        foreach ($tokens as $token) {
            if (\preg_match('/\A[1-9][0-9]*\z/D', $token) !== 1) {
                return null;
            }
            $pids[(int)$token] = true;
        }

        return self::sortedPids($pids);
    }

    /** @return array{ppid:int,start_ticks:string}|null */
    private static function linuxStatIdentity(int $pid): ?array
    {
        if ($pid < 1) {
            return null;
        }
        $stat = self::readPseudoFile('/proc/' . $pid . '/stat', self::MAX_PROC_STAT_BYTES);
        $closing = \is_string($stat) ? \strrpos($stat, ')') : false;
        if (!\is_int($closing)) {
            return null;
        }
        $fields = \preg_split('/\s+/', \trim(\substr($stat, $closing + 1))) ?: [];
        // The tail starts at field 3 (state): index 1 is PPID and index 19 is
        // field 22 (process start ticks).
        $ppid = (string)($fields[1] ?? '');
        $startTicks = (string)($fields[19] ?? '');
        if (\preg_match('/\A[0-9]+\z/D', $ppid) !== 1
            || \preg_match('/\A[0-9]+\z/D', $startTicks) !== 1
        ) {
            return null;
        }

        return ['ppid' => (int)$ppid, 'start_ticks' => $startTicks];
    }

    private static function readPseudoFile(string $path, int $maximumBytes): ?string
    {
        if ($maximumBytes < 1 || \str_contains($path, "\0")) {
            return null;
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
        } finally {
            @\fclose($handle);
        }
        if (!\is_string($contents) || \strlen($contents) > $maximumBytes) {
            return null;
        }

        return $contents;
    }
}
