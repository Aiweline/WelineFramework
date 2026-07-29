<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

use Weline\Framework\App\Env;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * Master-side memory pressure controller: sample → FSM → broadcast / shrink / recover.
 */
final class MemoryPressureController
{
    private HostMemorySampler $sampler;
    private MemoryPressureStateMachine $fsm;
    private float $lastTickAt = 0.0;
    private bool $shrinkInProgress = false;
    private int $budgetCeiling = 1;
    private int $startupExplicitCount = 0;

    public function __construct(
        ?HostMemorySampler $sampler = null,
        ?MemoryPressureStateMachine $fsm = null
    ) {
        $this->sampler = $sampler ?? new HostMemorySampler();
        $this->fsm = $fsm ?? new MemoryPressureStateMachine();
    }

    public function setBudgetCeiling(int $ceiling): void
    {
        $this->budgetCeiling = \max(1, $ceiling);
    }

    public function setStartupExplicitCount(int $count): void
    {
        $this->startupExplicitCount = \max(0, $count);
    }

    public function getBudgetCeiling(): int
    {
        return $this->budgetCeiling;
    }

    public function getLevel(): string
    {
        return $this->fsm->getLevel();
    }

    public function isShrinkInProgress(): bool
    {
        return $this->shrinkInProgress;
    }

    public function setShrinkInProgress(bool $inProgress): void
    {
        $this->shrinkInProgress = $inProgress;
    }

    public function isEnabled(): bool
    {
        try {
            if (!\defined('BP') && !\defined('Weline\\Framework\\App\\BP')) {
                return true;
            }
            $config = Env::get('wls.memory_pressure', []);
        } catch (\Throwable) {
            return true;
        }
        if (!\is_array($config) || !\array_key_exists('enabled', $config)) {
            return true;
        }

        return (bool)$config['enabled'];
    }

    public function sampleIntervalSec(): float
    {
        try {
            $config = (\defined('BP') || \defined('Weline\\Framework\\App\\BP'))
                ? Env::get('wls.memory_pressure', [])
                : [];
        } catch (\Throwable) {
            $config = [];
        }
        $value = \is_array($config) ? ($config['sample_interval_sec'] ?? 3) : 3;
        $sec = \is_numeric($value) ? (float)$value : 3.0;

        return $sec > 0 ? $sec : 3.0;
    }

    /**
     * @return array<string, mixed>|null tick result when a sample ran
     */
    public function maybeTick(float $now, ServiceOrchestrator $orchestrator): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if (($now - $this->lastTickAt) < $this->sampleIntervalSec()) {
            return null;
        }
        $this->lastTickAt = $now;
        $sample = $this->sampler->sample();
        $tick = $this->fsm->tick($sample, $now);

        if (!empty($tick['changed']) || !empty($tick['should_snapshot'])) {
            WlsLogger::info_(
                '[MemoryPressure] level=' . $tick['level']
                . ' source=' . $tick['pressure_source']
                . ' ratio=' . \sprintf('%.3f', $tick['pressure_ratio'])
                . ' green_streak=' . $tick['green_streak']
                . ' desired_hint=' . $this->budgetCeiling
            );
        }

        if (!empty($tick['changed'])) {
            $orchestrator->broadcastMemoryPressureLevel(
                (string)$tick['level'],
                (int)$tick['reclaim_stagger_ms_per_worker']
            );
        }

        if (!empty($tick['should_shrink'])) {
            $did = $orchestrator->scaleDownOneWorkerForMemoryPressure($this);
            if ($did) {
                $this->fsm->markScaleDown($now);
                if ($this->startupExplicitCount > 0) {
                    WlsLogger::warning_('[MemoryPressure] emergency_scale_down_override');
                }
            }
        }

        if (!empty($tick['should_recover'])) {
            $did = $orchestrator->scaleUpOneWorkerForMemoryPressure($this);
            if ($did) {
                $this->fsm->markRecover($now);
            }
        }

        return $tick + ['sample' => $sample];
    }

    public function getStateMachine(): MemoryPressureStateMachine
    {
        return $this->fsm;
    }

    public function buildBroadcastMessage(string $level, int $staggerMsPerWorker): string
    {
        return ControlMessage::memoryPressure($level, $staggerMsPerWorker);
    }
}
