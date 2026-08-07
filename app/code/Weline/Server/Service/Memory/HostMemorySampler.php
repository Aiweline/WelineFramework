<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

/**
 * Whole-host / cgroup memory pressure sampling.
 * Pressure source is homologous with the capacity limit (cgroup current/max or Available/MemTotal).
 */
final class HostMemorySampler
{
    private const MAX_PROBE_BYTES = 65536;
    private const COMMAND_TIMEOUT_SECONDS = 0.25;

    /**
     * @return array{
     *   pressure_ratio:float,
     *   pressure_source:string,
     *   limit_mb:int,
     *   used_mb:int,
     *   available_mb:?int,
     *   mem_total_mb:?int,
     *   swap_used_mb:?int,
     *   psi_some_avg10:?float,
     *   cgroup_max_mb:?int,
     *   cgroup_current_mb:?int
     * }
     */
    public function sample(): array
    {
        $meminfo = $this->readMeminfo();
        $cgroup = $this->readCgroupMemory();
        $psi = $this->readPsiSomeAvg10();

        $memTotalMb = $meminfo['mem_total_mb'];
        $availableMb = $meminfo['mem_available_mb'];
        $swapUsedMb = $meminfo['swap_used_mb'];

        $cgroupMax = $cgroup['max_mb'];
        $cgroupCurrent = $cgroup['current_mb'];

        if ($cgroupMax !== null && $cgroupMax > 0 && $cgroupCurrent !== null && $cgroupCurrent >= 0) {
            $limitMb = $cgroupMax;
            $usedMb = \min($cgroupCurrent, $cgroupMax);
            $pressure = $limitMb > 0 ? ($usedMb / $limitMb) : 0.0;
            $source = 'cgroup';
        } else {
            $limitMb = $memTotalMb ?? 0;
            if ($limitMb <= 0) {
                return [
                    'pressure_ratio' => 0.0,
                    'pressure_source' => 'unknown',
                    'limit_mb' => 0,
                    'used_mb' => 0,
                    'available_mb' => $availableMb,
                    'mem_total_mb' => $memTotalMb,
                    'swap_used_mb' => $swapUsedMb,
                    'psi_some_avg10' => $psi,
                    'cgroup_max_mb' => $cgroupMax,
                    'cgroup_current_mb' => $cgroupCurrent,
                ];
            }
            // Fail open on capacity when Available cannot be observed. Treating a
            // missing Darwin vm_stat probe as "0 MB free" falsely trips CRITICAL
            // and drains worker#2 ~15s after every Master start.
            if ($availableMb === null) {
                return [
                    'pressure_ratio' => 0.0,
                    'pressure_source' => 'unknown',
                    'limit_mb' => $limitMb,
                    'used_mb' => 0,
                    'available_mb' => null,
                    'mem_total_mb' => $memTotalMb,
                    'swap_used_mb' => $swapUsedMb,
                    'psi_some_avg10' => $psi,
                    'cgroup_max_mb' => $cgroupMax,
                    'cgroup_current_mb' => $cgroupCurrent,
                ];
            }
            $available = \max(0, \min($availableMb, $limitMb));
            $pressure = 1.0 - ($available / $limitMb);
            $usedMb = $limitMb - $available;
            $source = 'meminfo';
        }

        return [
            'pressure_ratio' => \max(0.0, \min(1.0, $pressure)),
            'pressure_source' => $source,
            'limit_mb' => $limitMb,
            'used_mb' => $usedMb,
            'available_mb' => $availableMb,
            'mem_total_mb' => $memTotalMb,
            'swap_used_mb' => $swapUsedMb,
            'psi_some_avg10' => $psi,
            'cgroup_max_mb' => $cgroupMax,
            'cgroup_current_mb' => $cgroupCurrent,
        ];
    }

    /**
     * Detect usable cgroup memory max in MB; null when unlimited / unavailable.
     */
    public function detectUsableCgroupMaxMb(): ?int
    {
        $cgroup = $this->readCgroupMemory();
        return $cgroup['max_mb'];
    }

    /**
     * @return array{mem_total_mb:?int,mem_available_mb:?int,swap_used_mb:?int}
     */
    private function readMeminfo(): array
    {
        if (PHP_OS_FAMILY === 'Linux' && \is_file('/proc/meminfo')) {
            $raw = $this->readBoundedFile('/proc/meminfo');
            if (!\is_string($raw) || $raw === '') {
                return ['mem_total_mb' => null, 'mem_available_mb' => null, 'swap_used_mb' => null];
            }
            $total = null;
            $available = null;
            $swapTotal = null;
            $swapFree = null;
            if (\preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $raw, $m)) {
                $total = (int)\floor(((int)$m[1]) / 1024);
            }
            if (\preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $raw, $m)) {
                $available = (int)\floor(((int)$m[1]) / 1024);
            }
            if (\preg_match('/^SwapTotal:\s+(\d+)\s+kB/m', $raw, $m)) {
                $swapTotal = (int)\floor(((int)$m[1]) / 1024);
            }
            if (\preg_match('/^SwapFree:\s+(\d+)\s+kB/m', $raw, $m)) {
                $swapFree = (int)\floor(((int)$m[1]) / 1024);
            }
            $swapUsed = ($swapTotal !== null && $swapFree !== null) ? \max(0, $swapTotal - $swapFree) : null;

            return [
                'mem_total_mb' => $total,
                'mem_available_mb' => $available,
                'swap_used_mb' => $swapUsed,
            ];
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->readDarwinMemory();
        }

        return ['mem_total_mb' => null, 'mem_available_mb' => null, 'swap_used_mb' => null];
    }

    /**
     * @return array{mem_total_mb:?int,mem_available_mb:?int,swap_used_mb:?int}
     */
    private function readDarwinMemory(): array
    {
        static $cachedTotalMb = null;
        static $cachedPageSize = null;
        $total = \is_int($cachedTotalMb) && $cachedTotalMb > 0
            ? $cachedTotalMb
            : null;
        $available = null;
        if ($total === null) {
            $bytes = $this->runProbe(['/usr/sbin/sysctl', '-n', 'hw.memsize']);
            $totalBytes = $this->boundedDecimal(\trim($bytes));
            if ($totalBytes !== null) {
                $total = (int)\floor($totalBytes / 1048576);
                if ($total > 0) {
                    $cachedTotalMb = $total;
                }
            }
        }
        $pageSize = \is_int($cachedPageSize) && $cachedPageSize > 0
            ? $cachedPageSize
            : null;
        if ($pageSize === null) {
            $pageSizeRaw = $this->runProbe(['/usr/sbin/sysctl', '-n', 'hw.pagesize']);
            $parsedPageSize = $this->boundedDecimal(\trim($pageSizeRaw));
            if ($parsedPageSize !== null
                && $parsedPageSize >= 4096
                && $parsedPageSize <= 65536
            ) {
                $pageSize = $parsedPageSize;
                $cachedPageSize = $pageSize;
            }
        }
        $vm = $this->runProbe(['/usr/bin/vm_stat']);
        $vmStat = $this->parseDarwinVmStat($vm);
        if ($vmStat !== null) {
            $parsedPageSize = $vmStat['page_size'];
            if ($parsedPageSize >= 4096 && $parsedPageSize <= 65536) {
                $pageSize = $parsedPageSize;
                $cachedPageSize = $pageSize;
            }
            // Without an observed page size, Apple Silicon (16 KiB) would be
            // under-counted 4x with the historical 4 KiB default and trip CRITICAL.
            if ($pageSize === null) {
                return [
                    'mem_total_mb' => $total,
                    'mem_available_mb' => null,
                    'swap_used_mb' => null,
                ];
            }
            $pages = $this->checkedSum([
                $vmStat['pages_free'],
                $vmStat['pages_inactive'],
                $vmStat['pages_speculative'],
                $vmStat['pages_purgeable'],
            ]);
            if ($pages !== null && $pages <= \intdiv(PHP_INT_MAX, $pageSize)) {
                $available = (int)\floor(($pages * $pageSize) / 1048576);
            }
        }

        return [
            'mem_total_mb' => $total,
            'mem_available_mb' => $available,
            'swap_used_mb' => null,
        ];
    }

    /**
     * @return array{max_mb:?int,current_mb:?int}
     */
    private function readCgroupMemory(): array
    {
        $v2Max = $this->readFirstExistingFile([
            '/sys/fs/cgroup/memory.max',
            '/sys/fs/cgroup/memory/memory.max',
        ]);
        $v2Current = $this->readFirstExistingFile([
            '/sys/fs/cgroup/memory.current',
            '/sys/fs/cgroup/memory/memory.current',
        ]);
        if ($v2Max !== null) {
            $maxMb = $this->parseCgroupMaxToMb($v2Max);
            $currentMb = $this->parseBytesToMb($v2Current);
            return ['max_mb' => $maxMb, 'current_mb' => $currentMb];
        }

        $v1Limit = $this->readFirstExistingFile([
            '/sys/fs/cgroup/memory/memory.limit_in_bytes',
        ]);
        $v1Usage = $this->readFirstExistingFile([
            '/sys/fs/cgroup/memory/memory.usage_in_bytes',
        ]);
        if ($v1Limit !== null) {
            return [
                'max_mb' => $this->parseCgroupMaxToMb($v1Limit),
                'current_mb' => $this->parseBytesToMb($v1Usage),
            ];
        }

        return ['max_mb' => null, 'current_mb' => null];
    }

    private function parseCgroupMaxToMb(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $raw = \trim($raw);
        if ($raw === '' || \strtolower($raw) === 'max') {
            return null;
        }
        $bytes = $this->boundedDecimal($raw);
        if ($bytes === null) {
            return null;
        }
        // Guard absurd host-wide "unlimited" sentinels (near 2^63).
        if ($bytes <= 0 || $bytes >= (PHP_INT_MAX >> 1)) {
            return null;
        }
        $mb = (int)\floor($bytes / 1048576);
        if ($mb <= 0) {
            return null;
        }

        $meminfo = $this->readMeminfo();
        $hostTotal = $meminfo['mem_total_mb'];
        if ($hostTotal !== null && $hostTotal > 0 && $mb > ($hostTotal * 2)) {
            // Likely an unrestricted cgroup inheriting a huge default.
            return null;
        }

        return $mb;
    }

    private function parseBytesToMb(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $raw = \trim($raw);
        $bytes = $this->boundedDecimal($raw);
        if ($bytes === null) {
            return null;
        }

        return (int)\floor($bytes / 1048576);
    }

    private function readPsiSomeAvg10(): ?float
    {
        $path = '/proc/pressure/memory';
        if (!\is_file($path)) {
            return null;
        }
        $raw = $this->readBoundedFile($path);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        if (!\preg_match('/^some\s+avg10=([0-9.]+)/m', $raw, $m)) {
            return null;
        }

        $value = (float)$m[1];
        return \is_finite($value) && $value >= 0.0 && $value <= 100.0 ? $value : null;
    }

    /**
     * @param list<string> $paths
     */
    private function readFirstExistingFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (!\is_file($path)) {
                continue;
            }
            $raw = $this->readBoundedFile($path);
            if (\is_string($raw) && $raw !== '') {
                return $raw;
            }
        }

        return null;
    }

    /** @param list<string> $command */
    private function runProbe(array $command): string
    {
        if (!isset($command[0]) || !\is_file($command[0]) || !\is_executable($command[0])) {
            return '';
        }
        $timeout = self::COMMAND_TIMEOUT_SECONDS;
        if (PHP_OS_FAMILY === 'Darwin') {
            // Master inherits many FDs; 250ms is too tight for vm_stat/sysctl and
            // empty samples previously collapsed into CRITICAL false positives.
            $timeout = 1.5;
        }
        $stdout = '';
        for ($attempt = 0; $attempt < 2 && $stdout === ''; ++$attempt) {
            try {
                $result = GatewayBoundedCommandRunner::run($command, $timeout);
                $candidate = \trim((string)($result['stdout'] ?? $result['output'] ?? ''));
                // Under Master FD inheritance the bounded runner can return
                // 125 after the probe already emitted a complete payload.
                if (($result['truncated'] ?? true) !== true
                    && \in_array((int)($result['code'] ?? 1), [0, 125], true)
                    && $this->looksLikeDarwinProbeOutput($command, $candidate)
                ) {
                    $stdout = $candidate;
                }
            } catch (\Throwable) {
                $stdout = '';
            }
        }

        return $stdout;
    }

    /** @param list<string> $command */
    private function looksLikeDarwinProbeOutput(array $command, string $stdout): bool
    {
        $binary = (string)($command[0] ?? '');
        if (\str_ends_with($binary, '/vm_stat')) {
            return $this->parseDarwinVmStat($stdout) !== null;
        }
        if (\str_ends_with($binary, '/sysctl')) {
            return \preg_match('/\A[0-9]+\z/D', $stdout) === 1;
        }

        return false;
    }

    /**
     * @return array{
     *   page_size:int,
     *   pages_free:int,
     *   pages_inactive:int,
     *   pages_speculative:int,
     *   pages_purgeable:int
     * }|null
     */
    private function parseDarwinVmStat(string $stdout): ?array
    {
        if ($stdout === ''
            || \strlen($stdout) > self::MAX_PROBE_BYTES
            || \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $stdout) === 1
        ) {
            return null;
        }
        $normalized = \str_replace(["\r\n", "\r"], "\n", \trim($stdout));
        $lines = \explode("\n", $normalized);
        $header = \array_shift($lines);
        if (!\is_string($header)
            || \preg_match(
                '/\AMach Virtual Memory Statistics: \(page size of ([0-9]+) bytes\)\z/D',
                $header,
                $headerMatches,
            ) !== 1
        ) {
            return null;
        }
        $pageSize = $this->boundedDecimal((string)$headerMatches[1]);
        if ($pageSize === null) {
            return null;
        }

        $values = [];
        foreach ($lines as $line) {
            if ($line === ''
                || \preg_match(
                    '/\A(?:"([A-Za-z][A-Za-z0-9 ()_-]*)"|'
                        . '([A-Za-z][A-Za-z0-9 ()_-]*)):[ \t]+([0-9]+)\.\z/D',
                    $line,
                    $matches,
                ) !== 1
            ) {
                return null;
            }
            $key = \strtolower((string)(($matches[1] ?? '') !== ''
                ? $matches[1]
                : ($matches[2] ?? '')));
            $value = $this->boundedDecimal((string)($matches[3] ?? ''));
            if ($value === null || isset($values[$key])) {
                return null;
            }
            $values[$key] = $value;
        }
        foreach (['pages free', 'pages inactive', 'pages speculative', 'pages purgeable'] as $required) {
            if (!isset($values[$required])) {
                return null;
            }
        }

        return [
            'page_size' => $pageSize,
            'pages_free' => $values['pages free'],
            'pages_inactive' => $values['pages inactive'],
            'pages_speculative' => $values['pages speculative'],
            'pages_purgeable' => $values['pages purgeable'],
        ];
    }

    private function readBoundedFile(string $path): ?string
    {
        if ($path === '' || \str_contains($path, "\0") || \is_link($path)) {
            return null;
        }
        $before = @\lstat($path);
        if (!\is_array($before)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
        ) {
            return null;
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened)
                || (int)($opened['dev'] ?? -1) !== (int)($before['dev'] ?? -2)
                || (int)($opened['ino'] ?? -1) !== (int)($before['ino'] ?? -2)
                || ((((int)($opened['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($opened['nlink'] ?? 0) !== 1
            ) {
                return null;
            }
            $contents = @\stream_get_contents($handle, self::MAX_PROBE_BYTES + 1);
        } finally {
            @\fclose($handle);
        }
        $after = @\lstat($path);

        return \is_string($contents)
            && \strlen($contents) <= self::MAX_PROBE_BYTES
            && \is_array($after)
            && !\is_link($path)
            && (int)($after['dev'] ?? -1) === (int)($before['dev'] ?? -2)
            && (int)($after['ino'] ?? -1) === (int)($before['ino'] ?? -2)
                ? $contents
                : null;
    }

    private function boundedDecimal(string $value): ?int
    {
        $value = \trim($value);
        $maximum = (string)PHP_INT_MAX;
        if ($value === ''
            || \preg_match('/\A[0-9]+\z/D', $value) !== 1
            || \strlen($value) > \strlen($maximum)
            || (\strlen($value) === \strlen($maximum) && \strcmp($value, $maximum) > 0)
        ) {
            return null;
        }

        return (int)$value;
    }

    /** @param list<int> $values */
    private function checkedSum(array $values): ?int
    {
        $sum = 0;
        foreach ($values as $value) {
            if ($value < 0 || $value > PHP_INT_MAX - $sum) {
                return null;
            }
            $sum += $value;
        }

        return $sum;
    }
}
