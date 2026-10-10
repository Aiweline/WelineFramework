<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Memory\HostMemorySampler;

/**
 * Master tick entry: evaluate → sample row → optional w_msg.
 * Must never call scale / mutate Desired.
 */
final class CapacityAdviseRunner
{
    private static float $lastCleanupAt = 0.0;

    public function __construct(
        private readonly CapacityAdvisorConfigService $configService = new CapacityAdvisorConfigService(),
        private readonly CapacityAdvisor $advisor = new CapacityAdvisor(),
        private readonly CapacitySampleStore $sampleStore = new CapacitySampleStore(),
        private readonly CapacityAdviceNotifier $notifier = new CapacityAdviceNotifier(),
        private readonly ?HostMemorySampler $hostSampler = null,
    ) {
    }

    /**
     * @param list<array{report:array<string,mixed>, at:float|int}> $workerReports
     * @return array<string, mixed>
     */
    public function tick(
        string $instance,
        array $workerReports,
        ?float $hostPressureRatio = null,
        ?float $hostAvailableRatio = null,
    ): array {
        $config = $this->configService->getCached();
        if (empty($config['enabled'])) {
            return ['skipped' => true, 'reason' => 'disabled'];
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

        $result = $this->advisor->evaluate(
            $instance,
            $workerReports,
            (float)$hostPressureRatio,
            $hostAvailableRatio,
            $config,
            $now,
        );

        try {
            $this->sampleStore->persist($instance !== '' ? $instance : 'default', $result['sample']);
        } catch (\Throwable $e) {
            WlsLogger::warning_('[CapacityAdvisor] sample persist failed: ' . $e->getMessage());
        }

        if (($now - self::$lastCleanupAt) >= 3600.0) {
            self::$lastCleanupAt = $now;
            try {
                $this->sampleStore->cleanup((int)$config['sample_retain_days']);
            } catch (\Throwable) {
            }
        }

        $notified = false;
        try {
            $notified = $this->notifier->notifyIfNeeded(
                (bool)$config['notify_enabled'],
                (bool)$result['triggered'],
                $result['reason_codes'],
                $result['advice'],
                $result['summary'],
            );
        } catch (\Throwable $e) {
            WlsLogger::warning_('[CapacityAdvisor] notify failed: ' . $e->getMessage());
        }

        return [
            'skipped' => false,
            'triggered' => (bool)$result['triggered'],
            'reason_codes' => $result['reason_codes'],
            'advice' => $result['advice'],
            'notified' => $notified,
            'summary' => $result['summary'],
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
