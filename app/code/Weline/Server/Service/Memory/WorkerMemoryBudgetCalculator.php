<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

use Weline\Framework\App\Env;

/**
 * Startup worker count from capacity budget (MemTotal/cgroup), not MemAvailable.
 */
final class WorkerMemoryBudgetCalculator
{
    public const DEFAULT_SYSTEM_RESERVE_MB = 550;
    public const DEFAULT_WLS_BASE_RESERVE_MB = 300;
    public const DEFAULT_EMERGENCY_RESERVE_MB = 200;
    public const DEFAULT_LOW_MEM_LIMIT_MB = 2300;
    public const DEFAULT_LOW_MEM_HARD_CAP = 2;
    public const MIN_RSS_ESTIMATE_MB = 192;

    /**
     * @param array<string, mixed> $budgetConfig
     * @return array{
     *   desired:int,
     *   budget_ceiling:int,
     *   limit_mb:int,
     *   limit_source:string,
     *   rss_estimate_mb:int,
     *   budget_mb:int,
     *   hard_cap:int,
     *   system_reserve_mb:int,
     *   wls_base_reserve_mb:int,
     *   emergency_reserve_mb:int,
     *   reason:string
     * }
     */
    public function calculate(
        int $cpuBasedCount,
        int $limitMb,
        string $limitSource,
        int $workerMemoryLimitMb,
        string $strategy = 'auto',
        ?array $budgetConfig = null
    ): array {
        $config = $budgetConfig ?? $this->loadConfig();
        $systemReserve = $this->positiveInt($config['system_reserve_mb'] ?? null, self::DEFAULT_SYSTEM_RESERVE_MB);
        $wlsBaseReserve = $this->positiveInt($config['wls_base_reserve_mb'] ?? null, self::DEFAULT_WLS_BASE_RESERVE_MB);
        $emergencyReserve = $this->positiveInt($config['emergency_reserve_mb'] ?? null, self::DEFAULT_EMERGENCY_RESERVE_MB);
        $lowMemLimit = $this->positiveInt($config['low_mem_limit_mb'] ?? null, self::DEFAULT_LOW_MEM_LIMIT_MB);
        $lowMemHardCap = $this->positiveInt($config['low_mem_hard_cap'] ?? null, self::DEFAULT_LOW_MEM_HARD_CAP);

        $rssMb = \max(self::MIN_RSS_ESTIMATE_MB, \max(1, $workerMemoryLimitMb));
        $budgetMb = $limitMb - $systemReserve - $wlsBaseReserve - $emergencyReserve;
        $cpuCap = \max(1, $cpuBasedCount);
        $hardCap = $limitMb <= $lowMemLimit ? $lowMemHardCap : $cpuCap;
        $byBudget = $budgetMb > 0 ? (int)\floor($budgetMb / $rssMb) : 0;
        $desired = \max(1, \min($cpuCap, $byBudget, $hardCap));
        if (\strtolower(\trim($strategy)) === 'stability') {
            $desired = \min($desired, 2);
        }

        $reason = \sprintf(
            'worker_budget limit_source=%s limit_mb=%d reserves=%d/%d/%d rss_estimate=%d hard_cap=%d cpu_based=%d budget_mb=%d desired=%d',
            $limitSource,
            $limitMb,
            $systemReserve,
            $wlsBaseReserve,
            $emergencyReserve,
            $rssMb,
            $hardCap,
            $cpuCap,
            \max(0, $budgetMb),
            $desired
        );

        return [
            'desired' => $desired,
            'budget_ceiling' => $desired,
            'limit_mb' => $limitMb,
            'limit_source' => $limitSource,
            'rss_estimate_mb' => $rssMb,
            'budget_mb' => \max(0, $budgetMb),
            'hard_cap' => $hardCap,
            'system_reserve_mb' => $systemReserve,
            'wls_base_reserve_mb' => $wlsBaseReserve,
            'emergency_reserve_mb' => $emergencyReserve,
            'reason' => $reason,
        ];
    }

    public function isEnabled(?array $budgetConfig = null): bool
    {
        $config = $budgetConfig ?? $this->loadConfig();
        if (!\array_key_exists('enabled', $config)) {
            return true;
        }

        return (bool)$config['enabled'];
    }

    /**
     * Historical integer without worker_count_requested: clamp when over hardCap on low-mem.
     */
    public function clampHistoricalCount(int $savedCount, int $limitMb, ?array $budgetConfig = null): array
    {
        $config = $budgetConfig ?? $this->loadConfig();
        $lowMemLimit = $this->positiveInt($config['low_mem_limit_mb'] ?? null, self::DEFAULT_LOW_MEM_LIMIT_MB);
        $lowMemHardCap = $this->positiveInt($config['low_mem_hard_cap'] ?? null, self::DEFAULT_LOW_MEM_HARD_CAP);
        $savedCount = \max(1, $savedCount);
        if (!$this->isEnabled($config) || $limitMb > $lowMemLimit || $savedCount <= $lowMemHardCap) {
            return [
                'count' => $savedCount,
                'clamped' => false,
                'hard_cap' => $lowMemHardCap,
            ];
        }

        return [
            'count' => $lowMemHardCap,
            'clamped' => true,
            'hard_cap' => $lowMemHardCap,
        ];
    }

    public static function memoryLimitToMb(mixed $limit): int
    {
        if (\is_int($limit) && $limit > 0) {
            return $limit;
        }
        $raw = \trim((string)$limit);
        if ($raw === '' || $raw === '-1') {
            return 256;
        }
        if (\ctype_digit($raw)) {
            return \max(1, (int)$raw);
        }
        if (!\preg_match('/^(\d+)\s*([KMG])?B?$/i', $raw, $m)) {
            return 256;
        }
        $value = (int)$m[1];
        $unit = \strtoupper((string)($m[2] ?? 'M'));
        return match ($unit) {
            'K' => \max(1, (int)\floor($value / 1024)),
            'G' => \max(1, $value * 1024),
            default => \max(1, $value),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function loadConfig(): array
    {
        try {
            if (!\defined('BP') && !\defined('Weline\\Framework\\App\\BP')) {
                return [];
            }
            $config = Env::get('wls.worker_budget', []);
            return \is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return $default;
    }
}
