<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

use Weline\Framework\System\Process\Native\DarwinProcessProbe;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

/**
 * Bounded process-table probes shared by legacy and host-managed Nginx paths.
 *
 * ## Darwin 分支（2026-09-26 新增）
 *
 * 此前 macOS 落在「通用 Unix」分支上，即 `pgrep -P` + `ps -p … -o pid=,ppid=,command=`。
 * `ps` 一旦不可用（沙箱 / 加固运行时 / 受限 PATH），`workerPids()` 返回 `null`、
 * `processIsRunning()` 返回 `null`，于是：
 *
 * - `ManagedNginxTlsSessionResumptionVerifier::detectEffectiveWorkerCount()` 得到 0
 *   ⇒ `managed nginx effective worker count could not be verified` ⇒ **托管 Nginx 整体起不来**；
 * - `SslCertificateService` 的旧版 Nginx 退役校验直接抛
 *   `Unable to enumerate the exact legacy Nginx worker generation.`。
 *
 * 现改为走 {@see DarwinProcessProbe}（libproc / sysctl，**零子进程**）：
 * `proc_listchildpids` 取子进程、`bsdInfo` 校 `ppid`、`commandLine` 认标题、`liveness` 判存活。
 * FFI 不可用时逐条回落到既有的 `ps`/`pgrep` 通路，行为与今天一致。
 */
final class NginxChildProcessProbe
{
    private const MAX_PROC_CHILDREN_BYTES = 64 * 1024;
    private const MAX_PROC_CMDLINE_BYTES = 64 * 1024;
    private const MAX_PROC_STAT_BYTES = 16 * 1024;
    private const MAX_CHILD_PROCESSES = 4096;
    private const PROCESS_TABLE_TIMEOUT_SECONDS = 2.0;

    /** @return list<int>|null */
    public static function workerPids(
        int $masterPid,
        ?float $deadlineMonotonic = null,
    ): ?array
    {
        self::remainingDeadline($deadlineMonotonic);
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
                    self::remainingDeadline($deadlineMonotonic);
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

        if (\PHP_OS_FAMILY === 'Darwin') {
            $native = self::darwinWorkerPids($masterPid, $deadlineMonotonic);
            if ($native !== null) {
                return $native;
            }
        }

        $ps = self::processTableExecutable();
        $pgrep = self::childListExecutable();
        if ($ps === null || $pgrep === null) {
            return null;
        }
        $firstChildren = self::posixChildPids(
            $pgrep,
            $masterPid,
            $deadlineMonotonic,
        );
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
        ], self::commandTimeout($deadlineMonotonic));
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
            self::remainingDeadline($deadlineMonotonic);
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
        $secondChildren = self::posixChildPids(
            $pgrep,
            $masterPid,
            $deadlineMonotonic,
        );
        if ($secondChildren === null
            || $secondChildren !== $firstChildren
            || self::sortedPids($seen) !== $firstChildren
        ) {
            return null;
        }

        return self::sortedPids($workers);
    }

    /**
     * Darwin 原生 worker 枚举：`proc_listchildpids` + `bsdInfo(ppid)` + `commandLine(标题)`。
     *
     * 与 Linux 分支保持**同样的稳定性契约**：采样前后各取一次子进程列表并要求逐字节相等，
     * 否则视为「代际不可证」返回 `null`，由调用方保持 fail-closed。
     *
     * `null`（问不出来 ⇒ 回落 `ps`/`pgrep`）与 `[]`（确认没有子进程）语义严格区分。
     *
     * @return list<int>|null
     */
    private static function darwinWorkerPids(
        int $masterPid,
        ?float $deadlineMonotonic,
    ): ?array {
        if (!DarwinProcessProbe::available()) {
            return null;
        }

        $firstChildren = DarwinProcessProbe::childPids($masterPid);
        if ($firstChildren === null) {
            return null;
        }
        if ($firstChildren === []) {
            return [];
        }

        $workers = [];
        foreach ($firstChildren as $childPid) {
            self::remainingDeadline($deadlineMonotonic);
            $info = DarwinProcessProbe::bsdInfo($childPid);
            if ($info === null || (int)$info['ppid'] !== $masterPid) {
                // 子进程在采样窗口内消失或被 reparent ⇒ 无法证明代际，不表态。
                return null;
            }
            $title = DarwinProcessProbe::commandLine($childPid);
            if ($title === null) {
                return null;
            }
            if (\str_contains(\strtolower($title), 'nginx: worker process')) {
                $workers[$childPid] = true;
            }
        }

        $secondChildren = DarwinProcessProbe::childPids($masterPid);
        if ($secondChildren === null || $secondChildren !== $firstChildren) {
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

    /** Return null when the bounded OS probe cannot prove either state. */
    public static function processIsRunning(
        int $pid,
        ?float $deadlineMonotonic = null,
    ): ?bool {
        if ($pid < 1) {
            return false;
        }
        self::remainingDeadline($deadlineMonotonic);
        if (\PHP_OS_FAMILY === 'Linux') {
            $directory = '/proc/' . $pid;
            if (!\is_dir($directory)) {
                return false;
            }
            $status = self::readPseudoFile(
                $directory . '/status',
                self::MAX_PROC_STAT_BYTES,
            );
            self::remainingDeadline($deadlineMonotonic);
            if ($status === null) {
                return null;
            }
            return \preg_match('/^State:\s+Z/m', $status) !== 1;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            $native = DarwinProcessProbe::liveness($pid);
            if ($native === DarwinProcessProbe::LIVENESS_RUNNING) {
                return true;
            }
            if ($native === DarwinProcessProbe::LIVENESS_ZOMBIE
                || $native === DarwinProcessProbe::LIVENESS_EXITED
            ) {
                return false;
            }
            // unknown（FFI 不可用 / EPERM / 无 posix_kill）⇒ 回落既有 ps 通路。
        }
        $ps = self::processTableExecutable();
        if ($ps === null) {
            return null;
        }
        $result = GatewayBoundedCommandRunner::run([
            $ps,
            '-p',
            (string)$pid,
            '-o',
            'state=',
        ], self::commandTimeout($deadlineMonotonic));
        self::remainingDeadline($deadlineMonotonic);
        $state = \trim((string)($result['stdout'] ?? ''));
        $code = (int)($result['code'] ?? 1);
        if ($code === 1 && $state === '') {
            return false;
        }
        if ($code !== 0 || \preg_match('/\A([A-Za-z])/', $state, $match) !== 1) {
            return null;
        }
        return \strtoupper((string)$match[1]) !== 'Z';
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
    private static function posixChildPids(
        string $pgrep,
        int $masterPid,
        ?float $deadlineMonotonic,
    ): ?array
    {
        $result = GatewayBoundedCommandRunner::run(
            [$pgrep, '-P', (string)$masterPid],
            self::commandTimeout($deadlineMonotonic),
        );
        self::remainingDeadline($deadlineMonotonic);
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

    private static function commandTimeout(?float $deadlineMonotonic): float
    {
        return \min(
            self::PROCESS_TABLE_TIMEOUT_SECONDS,
            self::remainingDeadline($deadlineMonotonic),
        );
    }

    private static function remainingDeadline(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return self::PROCESS_TABLE_TIMEOUT_SECONDS;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException('Nginx process probe deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('Nginx process probe deadline was exhausted.');
        }
        return $remaining;
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
