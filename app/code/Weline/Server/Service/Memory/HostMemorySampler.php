<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

/**
 * Whole-host / cgroup memory pressure sampling.
 * Pressure source is homologous with the capacity limit (cgroup current/max or Available/MemTotal).
 */
final class HostMemorySampler
{
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
            $available = $availableMb ?? 0;
            $available = \max(0, \min($available, $limitMb));
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
            $raw = @\file_get_contents('/proc/meminfo');
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
        $total = null;
        $available = null;
        if (\function_exists('shell_exec')) {
            $bytes = @\shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if (\is_string($bytes) && \trim($bytes) !== '' && \ctype_digit(\trim($bytes))) {
                $total = (int)\floor(((int)\trim($bytes)) / 1048576);
            }
            $vm = @\shell_exec('vm_stat 2>/dev/null');
            if (\is_string($vm) && $vm !== '') {
                $pageSize = 4096;
                if (\preg_match('/page size of\s+(\d+)\s+bytes/i', $vm, $m)) {
                    $pageSize = \max(1, (int)$m[1]);
                }
                $free = 0;
                $inactive = 0;
                $speculative = 0;
                if (\preg_match('/Pages free:\s+(\d+)/i', $vm, $m)) {
                    $free = (int)$m[1];
                }
                if (\preg_match('/Pages inactive:\s+(\d+)/i', $vm, $m)) {
                    $inactive = (int)$m[1];
                }
                if (\preg_match('/Pages speculative:\s+(\d+)/i', $vm, $m)) {
                    $speculative = (int)$m[1];
                }
                $available = (int)\floor((($free + $inactive + $speculative) * $pageSize) / 1048576);
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
        if (!\ctype_digit($raw)) {
            return null;
        }
        $bytes = (int)$raw;
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
        if ($raw === '' || !\ctype_digit($raw)) {
            return null;
        }

        return (int)\floor(((int)$raw) / 1048576);
    }

    private function readPsiSomeAvg10(): ?float
    {
        $path = '/proc/pressure/memory';
        if (!\is_file($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        if (!\preg_match('/^some\s+avg10=([0-9.]+)/m', $raw, $m)) {
            return null;
        }

        return (float)$m[1];
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
            $raw = @\file_get_contents($path);
            if (\is_string($raw) && $raw !== '') {
                return $raw;
            }
        }

        return null;
    }
}
