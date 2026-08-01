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
    private ?HostMemoryPressureCoordinator $hostCoordinator = null;
    private string $hostOwner = '';
    private bool $hostCoordinationRequired = false;
    private float $lastHostCoordinationDeferralAt = -1e12;
    private float $lastHostCoordinationFailureAt = -1e12;

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

    public function configureHostCapacityCoordination(
        HostMemoryPressureCoordinator $coordinator,
        string $owner,
    ): void {
        $owner = \strtolower(\trim($owner));
        if (\preg_match('/^[a-f0-9]{64}$/D', $owner) !== 1) {
            throw new \InvalidArgumentException(
                'Host memory-pressure coordination owner must be a SHA-256 identity.'
            );
        }
        $this->hostCoordinator = $coordinator;
        $this->hostOwner = $owner;
    }

    public function requireHostCapacityCoordination(): void
    {
        $this->hostCoordinationRequired = true;
    }

    public function hasHostCapacityCoordination(): bool
    {
        return $this->hostCoordinator !== null && $this->hostOwner !== '';
    }

    /**
     * @return non-empty-string|null Opaque claim token, or null when another
     *   project owns the current host mutation window.
     */
    public function claimHostCapacityMutation(
        string $action,
        float $now,
        float $holdSeconds,
    ): ?string {
        $action = \strtolower(\trim($action));
        if (!\in_array($action, ['scale_down', 'scale_up'], true)) {
            throw new \InvalidArgumentException(
                'Unsupported host memory-pressure capacity mutation.'
            );
        }
        if (!$this->hasHostCapacityCoordination()) {
            if ($this->hostCoordinationRequired && $action === 'scale_up') {
                if (($now - $this->lastHostCoordinationFailureAt) >= 30.0) {
                    $this->lastHostCoordinationFailureAt = $now;
                    WlsLogger::warning_(
                        '[MemoryPressure] host_capacity_coordination_unavailable'
                        . ' action=scale_up fallback=defer error=not_configured'
                    );
                }
                return null;
            }
            return 'local-uncoordinated';
        }
        try {
            $token = $this->hostCoordinator?->claim(
                $this->hostOwner,
                $action,
                $now,
                $holdSeconds,
            );
            if ($token === null
                && ($now - $this->lastHostCoordinationDeferralAt) >= 10.0
            ) {
                $this->lastHostCoordinationDeferralAt = $now;
                WlsLogger::info_(
                    '[MemoryPressure] host_capacity_mutation_deferred action=' . $action
                );
            }

            return $token;
        } catch (\Throwable $throwable) {
            // Critical shrink must still protect the host when derived state is
            // temporarily unavailable. Recovery is the opposite: fail closed
            // so several projects cannot restore capacity at the same time.
            $fallback = $action === 'scale_down' ? 'local' : 'defer';
            if (($now - $this->lastHostCoordinationFailureAt) >= 30.0) {
                $this->lastHostCoordinationFailureAt = $now;
                WlsLogger::warning_(
                    '[MemoryPressure] host_capacity_coordination_unavailable'
                    . ' action=' . $action
                    . ' fallback=' . $fallback
                    . ' error=' . $throwable->getMessage()
                );
            }

            return $action === 'scale_down' ? 'local-uncoordinated' : null;
        }
    }

    public function releaseHostCapacityMutation(string $token): void
    {
        if (!$this->hasHostCapacityCoordination()
            || $token === 'local-uncoordinated'
        ) {
            return;
        }
        try {
            $this->hostCoordinator?->release($this->hostOwner, $token);
        } catch (\Throwable $throwable) {
            WlsLogger::warning_(
                '[MemoryPressure] host_capacity_claim_release_failed'
                . ' error=' . $throwable->getMessage()
            );
        }
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
