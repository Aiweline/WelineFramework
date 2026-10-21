<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\SystemConfig\Api\ConfigReader;

/**
 * Index scheduling policy.
 *
 * All keys are global backend SystemConfig values owned by Weline_Database.
 * Missing rows fall back to the defaults below, so auto indexing is enabled
 * out of the box.
 */
final class SchemaIndexConfig
{
    public const MODULE = 'Weline_Database';
    public const AREA = ConfigReader::area_BACKEND;

    public function __construct(
        private readonly ?ConfigReader $reader = null,
        private readonly ?bool $deferOnUpgradeOverride = null,
        private readonly ?string $windowOverride = null,
        private readonly ?bool $autoApplyOverride = null,
        private readonly ?float $loadThresholdOverride = null,
    ) {
    }

    public function deferOnUpgrade(): bool
    {
        return $this->deferOnUpgradeOverride ?? $this->boolValue('index/defer_on_upgrade', true);
    }

    public function autoApply(): bool
    {
        return $this->autoApplyOverride ?? $this->boolValue('index/auto_apply', true);
    }

    public function window(): string
    {
        return $this->windowOverride ?? $this->stringValue('index/window', '01:00-05:00');
    }

    public function maxConcurrent(): int
    {
        return max(1, $this->intValue('index/max_concurrent', 1));
    }

    public function maxPerTableWindow(): int
    {
        return max(1, $this->intValue('index/max_per_table_window', 1));
    }

    public function cooldownSeconds(): int
    {
        return max(0, $this->intValue('index/cooldown_seconds', 300));
    }

    public function loadThreshold(): float
    {
        return $this->loadThresholdOverride !== null
            ? max(0.1, $this->loadThresholdOverride)
            : max(0.1, $this->floatValue('index/load_threshold', 3.0));
    }

    public function maintenanceWorkMem(): string
    {
        return $this->stringValue('index/maintenance_work_mem', '256MB');
    }

    public function maxParallelWorkers(): int
    {
        return max(0, min(32, $this->intValue('index/max_parallel_workers', 0)));
    }

    public function retryLimit(): int
    {
        return max(0, $this->intValue('index/retry_limit', 3));
    }

    public function isWithinWindow(?\DateTimeImmutable $now = null): bool
    {
        $window = trim($this->window());
        if ($window === '' || $window === '*' || $window === 'always') {
            return true;
        }
        if (preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $window, $matches) !== 1) {
            return true;
        }

        $start = ((int)$matches[1]) * 60 + (int)$matches[2];
        $end = ((int)$matches[3]) * 60 + (int)$matches[4];
        if ($start === $end) {
            return true;
        }

        $now ??= new \DateTimeImmutable();
        $current = ((int)$now->format('H')) * 60 + (int)$now->format('i');

        return $start < $end
            ? ($current >= $start && $current < $end)
            : ($current >= $start || $current < $end);
    }

    public function isLoadAcceptable(?float $load = null): bool
    {
        if ($load === null) {
            $loads = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
            $load = is_array($loads) && isset($loads[0]) ? (float)$loads[0] : 0.0;
        }

        return $load <= $this->loadThreshold();
    }

    private function value(string $key, mixed $default): mixed
    {
        if ($this->reader === null) {
            return $default;
        }

        return $this->reader->get($key, self::MODULE, self::AREA, $default, ConfigReader::SCOPE_GLOBAL);
    }

    private function boolValue(string $key, bool $default): bool
    {
        $value = filter_var($this->value($key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    private function intValue(string $key, int $default): int
    {
        $value = $this->value($key, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }

        return $default;
    }

    private function floatValue(string $key, float $default): float
    {
        $value = $this->value($key, $default);
        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }

        return $default;
    }

    private function stringValue(string $key, string $default): string
    {
        $value = $this->value($key, $default);
        if (is_scalar($value)) {
            $value = trim((string)$value);

            return $value !== '' ? $value : $default;
        }

        return $default;
    }
}
