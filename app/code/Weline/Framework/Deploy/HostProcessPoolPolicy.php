<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * Host-aware process-pool sizing for deploy / theme solidify / template compile.
 *
 * Explicit override and numeric ENV still win; unset or "auto" ENV uses
 * logical CPUs + MemAvailable with headroom for live MySQL / WLS.
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

        $fromEnv = getenv('PHP_NPROCESSORS_ONLN');
        if ($fromEnv !== false && $fromEnv !== '' && (int)$fromEnv > 0) {
            return (int)$fromEnv;
        }

        if (\function_exists('shell_exec')) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if (\is_string($nproc) && trim($nproc) !== '' && (int)trim($nproc) > 0) {
                return (int)trim($nproc);
            }
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (\is_string($cpuinfo) && $cpuinfo !== '') {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if ($count > 0) {
                return $count;
            }
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

        $meminfo = @file_get_contents('/proc/meminfo');
        if (!\is_string($meminfo) || $meminfo === '') {
            return null;
        }
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/mi', $meminfo, $m) === 1) {
            return (int)floor(((int)$m[1]) / 1024);
        }

        return null;
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
}
