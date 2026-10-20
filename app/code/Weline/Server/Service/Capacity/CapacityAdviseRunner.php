<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Memory\HostMemorySampler;

/**
 * Master tick entry: evaluate → in-memory sample → optional w_msg.
 * Must never call scale, mutate Desired, or touch the capacity history file.
 */
final class CapacityAdviseRunner
{
    public function __construct(
        private readonly CapacityAdvisorConfigService $configService = new CapacityAdvisorConfigService(),
        private readonly CapacityAdvisor $advisor = new CapacityAdvisor(),
        private readonly CapacityAdviceNotifier $notifier = new CapacityAdviceNotifier(),
        private readonly ?HostMemorySampler $hostSampler = null,
    ) {
    }

    /**
     * Evaluate → sample → optional notify → CapacityIntent.
     * Must never call scale / mutate Desired (Applier owns that).
     *
     * @param list<array{report:array<string,mixed>, at:float|int}> $workerReports
     * @param array{
     *   current_desired?:int,
     *   baseline?:int,
     *   max_workers?:int,
     *   auto_scale_enabled?:bool,
     *   idle_active_max?:int,
     *   idle_pressure_max?:float,
     *   last_scale_up_at?:float,
     *   scale_down_cooldown_sec?:float,
     *   idle_since?:float,
     *   t_down_sec?:float
     * } $scaleContext
     * @return array<string, mixed>
     */
    public function tick(
        string $instance,
        array $workerReports,
        ?float $hostPressureRatio = null,
        ?float $hostAvailableRatio = null,
        array $scaleContext = [],
    ): array {
        $config = $this->configService->getCached();
        if (empty($config['enabled'])) {
            $noop = CapacityIntent::noop($instance !== '' ? $instance : 'default', 'disabled');

            return [
                'skipped' => true,
                'reason' => 'disabled',
                'intent' => $noop->toArray(),
                'intent_object' => $noop,
            ];
        }

        $now = \microtime(true);
        if ($hostPressureRatio === null) {
            $host = $this->sampleHost();
            $hostPressureRatio = (float)($host['pressure_ratio'] ?? 0.0);
            $avail = $host['available_mb'] ?? null;
            $total = $host['mem_total_mb'] ?? null;
            if (\is_numeric($avail) && \is_numeric($total) && (float)$total > 0) {
                $hostAvailableRatio = (float)$avail / (float)$total;
            }
        }

        // evaluateIntent calls evaluate once (ring append); do not double-evaluate.
        $intent = $this->advisor->evaluateIntent(
            $instance,
            $workerReports,
            (float)$hostPressureRatio,
            $hostAvailableRatio,
            $config,
            $now,
            $scaleContext,
        );
        $sample = $intent->sample;
        $reasonCodes = $intent->reasonCodes;
        $advice = $intent->advice;
        $triggered = $reasonCodes !== [];
        $summary = [
            'instance' => $instance !== '' ? $instance : 'default',
            'avg_pressure' => (float)($sample['worker_pressure_avg'] ?? 0.0),
            'max_pressure' => (float)($sample['worker_pressure_max'] ?? 0.0),
            'host_pressure' => (float)($sample['host_pressure'] ?? 0.0),
            'intent_action' => $intent->action,
            'intent_reason' => $intent->reason,
            'intent_target' => $intent->target,
        ];

        $notified = false;
        try {
            $notified = $this->notifier->notifyIfNeeded(
                (bool)$config['notify_enabled'],
                $triggered,
                $reasonCodes,
                $advice,
                $summary,
            );
        } catch (\Throwable $e) {
            WlsLogger::warning_('[CapacityAdvisor] notify failed: ' . $e->getMessage());
        }

        return [
            'skipped' => false,
            'triggered' => $triggered,
            'reason_codes' => $reasonCodes,
            'advice' => $advice,
            'notified' => $notified,
            'summary' => $summary,
            'intent' => $intent->toArray(),
            'intent_object' => $intent,
        ];
    }

    public function intervalSec(): int
    {
        $config = $this->configService->getCached();

        return \max(60, (int)($config['interval_sec'] ?? 60));
    }

    /** @return array<string, mixed> */
    private function sampleHost(): array
    {
        try {
            $sampler = $this->hostSampler ?? new HostMemorySampler();

            return $sampler->sample();
        } catch (\Throwable) {
            return ['pressure_ratio' => 0.0];
        }
    }
}
