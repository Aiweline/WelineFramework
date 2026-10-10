<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * Host-aware process-pool sizing for deploy / theme solidify / template compile.
 *
 * Explicit override and numeric ENV still win; unset or "auto" ENV uses
 * logical CPUs + available memory with headroom for live MySQL / WLS.
 *
 * CPU / memory probes are OS-family aware:
 * - Linux: nproc / getconf /proc + MemAvailable
 * - Darwin (macOS): getconf / sysctl + vm_stat reclaimable pages
 * - Windows: NUMBER_OF_PROCESSORS + FreePhysicalMemory (PowerShell / WMIC)
 */
final class HostProcessPoolPolicy
{
    public const MB_PER_WORKER = 350;

    public function __construct(
        private readonly ?int $cpusOverride = null,
        private readonly ?int $availableMemoryMbOverride = null,
        private readonly bool $memoryOverrideIsNull = false,
    ) {
    }

    /**
     * Factory for tests: fixed CPU / memory (null memory = no mem cap).
     */
    public static function forTest(int $cpus, ?int $availableMemoryMb): self
    {
        return new self($cpus, $availableMemoryMb, $availableMemoryMb === null);
    }

    public function detectLogicalCpus(): int
    {
        if ($this->cpusOverride !== null) {
            return max(1, $this->cpusOverride);
        }

        foreach ($this->positiveIntEnvCandidates([
            'PHP_NPROCESSORS_ONLN',
            'NUMBER_OF_PROCESSORS', // Windows always exposes this
        ]) as $n) {
            return $n;
        }

        $fromShell = $this->detectLogicalCpusViaShell();
        if ($fromShell !== null) {
            return $fromShell;
        }

        $fromProc = $this->detectLogicalCpusFromLinuxProc();
        if ($fromProc !== null) {
            return $fromProc;
        }

        return 1;
    }

    public function detectAvailableMemoryMb(): ?int
    {
        if ($this->memoryOverrideIsNull) {
            return null;
        }
        if ($this->availableMemoryMbOverride !== null) {
            return max(0, $this->availableMemoryMbOverride);
        }

        return match (\PHP_OS_FAMILY) {
            'Windows' => $this->detectWindowsAvailableMemoryMb(),
            'Darwin' => $this->detectDarwinAvailableMemoryMb(),
            default => $this->detectLinuxAvailableMemoryMb(),
        };
    }

    public function suggest(int $autoCeiling = 10, int $max = 32): int
    {
        $autoCeiling = max(1, $autoCeiling);
        $max = max(1, $max);
        $cpus = $this->detectLogicalCpus();
        $reserve = $cpus <= 2
            ? max(0, $cpus - 1)
            : max(1, (int)floor($cpus * 0.25));
        $cpuBudget = max(1, $cpus - $reserve);

        $memMb = $this->detectAvailableMemoryMb();
        $memBudget = $memMb === null
            ? $autoCeiling
            : max(1, (int)floor($memMb / self::MB_PER_WORKER));

        $n = min($autoCeiling, $cpuBudget, $memBudget, $max);

        return max(1, $n);
    }

    /**
     * @return array{
     *     concurrency: int,
     *     source: 'override'|'env'|'host_auto',
     *     cpus: int,
     *     mem_avail_mb: int|null
     * }
     */
    public function resolve(
        ?int $override,
        ?string $envValue,
        int $autoCeiling = 10,
        int $max = 32,
    ): array {
        $cpus = $this->detectLogicalCpus();
        $memMb = $this->detectAvailableMemoryMb();
        $max = max(1, $max);
        $autoCeiling = max(1, min($autoCeiling, $max));

        if ($override !== null) {
            return [
                'concurrency' => max(1, min($max, $override)),
                'source' => 'override',
                'cpus' => $cpus,
                'mem_avail_mb' => $memMb,
            ];
        }

        $env = $envValue === null ? '' : trim($envValue);
        if ($env !== '' && strcasecmp($env, 'auto') !== 0) {
            $n = (int)$env;
            if ($n < 1) {
                $n = 1;
            }

            return [
                'concurrency' => min($max, $n),
                'source' => 'env',
                'cpus' => $cpus,
                'mem_avail_mb' => $memMb,
            ];
        }

        return [
            'concurrency' => $this->suggest($autoCeiling, $max),
            'source' => 'host_auto',
            'cpus' => $cpus,
            'mem_avail_mb' => $memMb,
        ];
    }

    public static function envRaw(string $envName): string
    {
        $env = getenv($envName);
        if ($env === false) {
            $env = $_ENV[$envName] ?? $_SERVER[$envName] ?? '';
        }

        return \is_string($env) ? $env : '';
    }

    /**
     * @internal parse helpers exposed for UT
     */
    public static function parseLinuxMeminfoAvailableMb(string $meminfo): ?int
    {
        if ($meminfo === '') {
            return null;
        }
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/mi', $meminfo, $m) === 1) {
            return (int)floor(((int)$m[1]) / 1024);
        }

        return null;
    }

    /**
     * @internal Darwin vm_stat: free + inactive + speculative + purgeable.
     */
    public static function parseDarwinVmStatAvailableMb(string $vmStat, int $fallbackPageSize = 4096): ?int
    {
        if ($vmStat === '') {
            return null;
        }
        $pageSize = $fallbackPageSize > 0 ? $fallbackPageSize : 4096;
        if (preg_match('/page size of (\d+) bytes/i', $vmStat, $m) === 1) {
            $pageSize = max(1, (int)$m[1]);
        }

        $pages = 0;
        $matched = 0;
        foreach (['Pages free', 'Pages inactive', 'Pages speculative', 'Pages purgeable'] as $key) {
            if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\./m', $vmStat, $m) === 1) {
                $pages += (int)$m[1];
                ++$matched;
            }
        }
        if ($matched === 0) {
            return null;
        }

        return (int)floor(($pages * $pageSize) / 1048576);
    }

    /**
     * @internal Windows FreePhysicalMemory from PowerShell plain KB or WMIC /Value.
     */
    public static function parseWindowsFreePhysicalMemoryMb(string $output): ?int
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }
        if (preg_match('/FreePhysicalMemory\s*=\s*(\d+)/i', $output, $m) === 1) {
            return (int)floor(((int)$m[1]) / 1024);
        }
        if (preg_match('/^\s*(\d+)\s*$/m', $output, $m) === 1) {
            return (int)floor(((int)$m[1]) / 1024);
        }

        return null;
    }

    /**
     * @param list<string> $names
     * @return list<int>
     */
    private function positiveIntEnvCandidates(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $raw = self::envRaw($name);
            if ($raw === '') {
                continue;
            }
            $n = (int)$raw;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return $out;
    }

    private function detectLogicalCpusViaShell(): ?int
    {
        if (!\function_exists('shell_exec')) {
            return null;
        }

        // Portable POSIX (Linux + macOS): getconf
        $n = $this->positiveIntFromShell('getconf _NPROCESSORS_ONLN 2>/dev/null');
        if ($n !== null) {
            return $n;
        }

        // Linux util-linux
        $n = $this->positiveIntFromShell('nproc 2>/dev/null');
        if ($n !== null) {
            return $n;
        }

        // Darwin
        if (\PHP_OS_FAMILY === 'Darwin') {
            $n = $this->positiveIntFromShell('sysctl -n hw.logicalcpu 2>/dev/null');
            if ($n !== null) {
                return $n;
            }
            $n = $this->positiveIntFromShell('sysctl -n hw.ncpu 2>/dev/null');
            if ($n !== null) {
                return $n;
            }
        }

        // Windows: env usually wins; shell fallbacks if env stripped
        if (\PHP_OS_FAMILY === 'Windows') {
            $n = $this->positiveIntFromShell(
                'powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors" 2>NUL'
            );
            if ($n !== null) {
                return $n;
            }
            $wmic = $this->shellOutput('wmic cpu get NumberOfLogicalProcessors /Value 2>NUL');
            if ($wmic !== null && preg_match('/NumberOfLogicalProcessors\s*=\s*(\d+)/i', $wmic, $m) === 1) {
                $n = (int)$m[1];
                if ($n > 0) {
                    return $n;
                }
            }
        }

        return null;
    }

    private function detectLogicalCpusFromLinuxProc(): ?int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (\is_string($cpuinfo) && $cpuinfo !== '') {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if ($count > 0) {
                return $count;
            }
        }

        $online = @file_get_contents('/sys/devices/system/cpu/online');
        if (\is_string($online) && preg_match('/^(\d+)(?:-(\d+))?/', trim($online), $m) === 1) {
            $start = (int)$m[1];
            $end = isset($m[2]) ? (int)$m[2] : $start;

            return max(1, $end - $start + 1);
        }

        return null;
    }

    private function detectLinuxAvailableMemoryMb(): ?int
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (!\is_string($meminfo) || $meminfo === '') {
            return null;
        }

        return self::parseLinuxMeminfoAvailableMb($meminfo);
    }

    private function detectDarwinAvailableMemoryMb(): ?int
    {
        if (!\function_exists('shell_exec')) {
            return null;
        }
        $vmStat = $this->shellOutput('vm_stat 2>/dev/null');
        if ($vmStat === null) {
            return null;
        }
        $pageSize = $this->positiveIntFromShell('sysctl -n hw.pagesize 2>/dev/null') ?? 4096;

        return self::parseDarwinVmStatAvailableMb($vmStat, $pageSize);
    }

    private function detectWindowsAvailableMemoryMb(): ?int
    {
        if (!\function_exists('shell_exec')) {
            return null;
        }
        $ps = $this->shellOutput(
            'powershell -NoProfile -Command "(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory" 2>NUL'
        );
        $parsed = $ps !== null ? self::parseWindowsFreePhysicalMemoryMb($ps) : null;
        if ($parsed !== null) {
            return $parsed;
        }
        $wmic = $this->shellOutput('wmic OS get FreePhysicalMemory /Value 2>NUL');
        if ($wmic === null) {
            return null;
        }

        return self::parseWindowsFreePhysicalMemoryMb($wmic);
    }

    private function positiveIntFromShell(string $command): ?int
    {
        $out = $this->shellOutput($command);
        if ($out === null) {
            return null;
        }
        if (preg_match('/(\d+)/', $out, $m) !== 1) {
            return null;
        }
        $n = (int)$m[1];

        return $n > 0 ? $n : null;
    }

    private function shellOutput(string $command): ?string
    {
        if (!\function_exists('shell_exec')) {
            return null;
        }
        $raw = @shell_exec($command);
        if (!\is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
