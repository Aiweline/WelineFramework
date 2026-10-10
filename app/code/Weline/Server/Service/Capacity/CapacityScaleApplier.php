<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

use Weline\Framework\App\Env;
use Weline\Server\Log\WlsLogger;

/**
 * Sole Desired writer for automatic capacity changes (advisor + memory pressure).
 *
 * Envelope: runtime Desired ∈ [serveFloor, max_workers] where serveFloor defaults to
 * max(baseline, scaling.min_workers, 1). Emergency pressure may dip below baseline when
 * allowed by the caller flag.
 */
final class CapacityScaleApplier
{
    /** pressure_reclaim beats advisor scale_up (UC-W2-11). */
    public const PRIORITY_PRESSURE_RECLAIM = 100;
    public const PRIORITY_PRESSURE_RECOVER = 80;
    public const PRIORITY_ADVISOR = 10;

    /** @var array{at:float, reason:string, direction:string}|null */
    private static ?array $lastApplied = null;

    /**
     * @param array{
     *   instance:string,
     *   current_desired:int,
     *   baseline:int,
     *   max_workers:int,
     *   min_workers?:int,
     *   ops_mutex?:bool,
     *   batch_in_progress?:bool,
     *   cooldown?:bool,
     *   allow_below_baseline?:bool,
     *   env?:array<string,mixed>,
     *   pg_max_connections?:?int,
     *   sidecar_accept_budget?:?int,
     *   ms_memory_headroom_ratio?:?float,
     *   publish_route_table?:callable(string):void,
     *   persist_runtime?:callable(int):void,
     *   set_desired?:callable(int):void,
     *   execute?:callable(int,int,CapacityIntent):bool
     * } $context
     * @return array{
     *   applied:bool,
     *   reason:string,
     *   from:int,
     *   to:int,
     *   intent:array<string,mixed>
     * }
     */
    public function apply(CapacityIntent $intent, array $context): array
    {
        $instance = (string)($context['instance'] ?? '');
        $current = \max(0, (int)($context['current_desired'] ?? 0));
        $baseline = \max(1, (int)($context['baseline'] ?? 1));
        $maxWorkers = \max(1, (int)($context['max_workers'] ?? $baseline));
        $minWorkers = \max(1, (int)($context['min_workers'] ?? 1));
        $allowBelowBaseline = !empty($context['allow_below_baseline']);

        if ($intent->instance !== '' && $instance !== '' && $intent->instance !== $instance) {
            return $this->result(false, 'ops_mutex', $current, $current, $intent);
        }

        if (!$intent->isScale()) {
            return $this->result(false, $intent->reason !== '' ? $intent->reason : 'noop', $current, $current, $intent);
        }

        if (!empty($context['ops_mutex'])) {
            return $this->result(false, 'ops_mutex', $current, $current, $intent);
        }
        if (!empty($context['batch_in_progress'])) {
            return $this->result(false, 'batch_in_progress', $current, $current, $intent);
        }
        if (!empty($context['cooldown']) && $intent->reason !== 'pressure_reclaim') {
            return $this->result(false, 'cooldown', $current, $current, $intent);
        }

        $serveFloor = \max($minWorkers, 1);
        if (!$allowBelowBaseline) {
            $serveFloor = \max($serveFloor, $baseline);
        }
        $target = (int)$intent->target;
        $target = \max($serveFloor, \min($maxWorkers, $target));

        if ($target === $current) {
            $reason = $current <= $baseline ? 'at_baseline' : ($current >= $maxWorkers ? 'at_max_workers' : $intent->reason);
            return $this->result(false, $reason, $current, $current, $intent);
        }

        if ($target > $current && empty($context['skip_budget_gate'])) {
            $env = \is_array($context['env'] ?? null) ? $context['env'] : [];
            $budget = CapacityBudgetGate::checkScaleUp(
                $instance !== '' ? $instance : 'default',
                $target,
                $env,
                \array_key_exists('pg_max_connections', $context)
                    ? (isset($context['pg_max_connections']) ? (int)$context['pg_max_connections'] : null)
                    : null,
                CapacityBudgetGate::DEFAULT_RESERVED_CONNECTIONS,
                isset($context['sidecar_accept_budget']) ? (int)$context['sidecar_accept_budget'] : null,
                1,
                isset($context['ms_memory_headroom_ratio']) ? (float)$context['ms_memory_headroom_ratio'] : null,
            );
            if (!$budget['ok']) {
                return $this->result(false, (string)($budget['reason'] ?? 'db_budget'), $current, $current, $intent);
            }
        }

        $publish = $context['publish_route_table'] ?? null;
        $setDesired = $context['set_desired'] ?? null;
        if (\is_callable($setDesired)) {
            $setDesired($target);
        }
        // Scale-down: Desired first so route fence can suppress ids > target, then DRAIN.
        if (\is_callable($publish) && $target < $current) {
            $publish('before_scale_down');
        }

        $persist = $context['persist_runtime'] ?? null;
        if (\is_callable($persist)) {
            $persist($target);
        }

        $execute = $context['execute'] ?? null;
        $ok = true;
        if (\is_callable($execute)) {
            $ok = (bool)$execute($current, $target, $intent);
        }

        if (!$ok) {
            if (\is_callable($setDesired)) {
                $setDesired($current);
            }
            if (\is_callable($persist)) {
                $persist($current);
            }
            if (\is_callable($publish) && $target < $current) {
                $publish('after_scale_down_abort');
            }
            return $this->result(false, $intent->reason . '_aborted', $current, $current, $intent);
        }

        if (\is_callable($publish) && $target > $current) {
            $publish('after_scale_up');
        } elseif (\is_callable($publish) && $target < $current) {
            $publish('after_scale_down');
        }

        self::$lastApplied = [
            'at' => \microtime(true),
            'reason' => $intent->reason,
            'direction' => $target > $current ? 'up' : 'down',
        ];
        WlsLogger::info_(
            '[CapacityScaleApplier] applied reason=' . $intent->reason
            . ' desired=' . $current . '->' . $target
            . ' baseline=' . $baseline
            . ' max=' . $maxWorkers
        );

        return $this->result(true, $intent->reason, $current, $target, $intent);
    }

    /**
     * Resolve envelope bounds from env / context helpers.
     *
     * @return array{baseline:int, max_workers:int, min_workers:int}
     */
    public static function resolveEnvelope(int $baseline, ?array $scaling = null): array
    {
        if ($scaling === null) {
            $scaling = [];
            if (\defined('BP') && \defined('DS')) {
                try {
                    $scaling = Env::get('wls.scaling', []);
                } catch (\Throwable) {
                    $scaling = [];
                }
            }
        }
        if (!\is_array($scaling)) {
            $scaling = [];
        }
        $min = isset($scaling['min_workers']) && \is_numeric($scaling['min_workers'])
            ? \max(1, (int)$scaling['min_workers'])
            : 1;
        $defaultMax = \max($baseline, $min, 1);
        $max = isset($scaling['max_workers']) && \is_numeric($scaling['max_workers'])
            ? \max(1, (int)$scaling['max_workers'])
            : \max($defaultMax, (int)(\function_exists('swoole_cpu_num') ? \swoole_cpu_num() * 2 : 8));

        return [
            'baseline' => \max(1, $baseline),
            'max_workers' => \max($min, $max),
            'min_workers' => $min,
        ];
    }

    /** @return array{at:float, reason:string, direction:string}|null */
    public static function lastAppliedForTests(): ?array
    {
        return self::$lastApplied;
    }

    /** @internal */
    public static function resetForTests(): void
    {
        self::$lastApplied = null;
    }

    /**
     * @return array{applied:bool, reason:string, from:int, to:int, intent:array<string,mixed>}
     */
    private function result(bool $applied, string $reason, int $from, int $to, CapacityIntent $intent): array
    {
        return [
            'applied' => $applied,
            'reason' => $reason,
            'from' => $from,
            'to' => $to,
            'intent' => $intent->toArray(),
        ];
    }
}
