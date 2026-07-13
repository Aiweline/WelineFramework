<?php

declare(strict_types=1);

namespace Weline\Server\Security;

use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Server\Service\Contract\MemoryStateFacadeInterface;

/**
 * Digest-aware owner registry for ConnectionAcceptGate.
 *
 * New accepts always use the currently activated bundle. Existing sockets
 * retain their original gate until close, so a policy pointer swap cannot
 * lose connection capacity or slowloris accounting from the previous digest.
 */
final class ConnectionAcceptGatePool
{
    private const RECONCILE_INTERVAL_SECONDS = 0.25;

    private static ?self $instance = null;

    /** @var array<string, ConnectionAcceptGate> */
    private array $gates = [];

    /** @var array<string, string> connection id => policy digest */
    private array $owners = [];

    private string $currentDigest;

    private string $preparedDigest = '';

    private float $nextReconcileAt = 0.0;

    private function __construct(
        private readonly string $topology,
        private readonly string $instanceName,
        private ?MemoryStateFacadeInterface $state,
        private readonly int $readyWorkers,
        private readonly int $workerOrdinal,
        RuntimePolicyBundle $initialBundle,
    ) {
        $this->currentDigest = \strtolower(\trim($initialBundle->digest));
        $this->gates[$this->currentDigest] = $this->buildGate($initialBundle);
    }

    public static function boot(
        string $topology,
        string $instanceName,
        ?MemoryStateFacadeInterface $state,
        int $readyWorkers,
        int $workerOrdinal,
        RuntimePolicyBundle $initialBundle,
    ): self {
        return self::$instance = new self(
            topology: $topology,
            instanceName: $instanceName,
            state: $state,
            readyWorkers: $readyWorkers,
            workerOrdinal: $workerOrdinal,
            initialBundle: $initialBundle,
        );
    }

    public static function instanceOrNull(): ?self
    {
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function accept(
        string $connectionId,
        string $transportPeer,
        ?float $now = null,
    ): ConnectionAcceptDecision {
        $connectionId = \trim($connectionId);
        if (isset($this->owners[$connectionId])) {
            // PHP stream/socket resource ids may be reused immediately after a
            // legacy close branch. A still-live duplicate cannot exist in one
            // process, so release the stale owner before admitting the new fd.
            $this->close($connectionId);
        }
        $gate = $this->currentGate();
        $decision = $gate->accept($connectionId, $transportPeer, $now);
        if ($decision->allowed) {
            $this->owners[$connectionId] = $this->currentDigest;
        }
        return $decision;
    }

    public function beginRequest(string $connectionId, ?float $now = null): bool
    {
        $gate = $this->ownerGate($connectionId);
        return $gate?->beginRequest($connectionId, $now) ?? false;
    }

    public function markRequestComplete(string $connectionId): bool
    {
        $gate = $this->ownerGate($connectionId);
        return $gate?->markRequestComplete($connectionId) ?? false;
    }

    public function close(string $connectionId): bool
    {
        $digest = $this->owners[$connectionId] ?? null;
        if (!\is_string($digest)) {
            return false;
        }
        unset($this->owners[$connectionId]);
        $closed = ($this->gates[$digest] ?? null)?->close($connectionId) ?? false;
        $this->removeUnusedOldGate($digest);
        return $closed;
    }

    /**
     * @return list<ConnectionCloseDirective>
     */
    public function sweep(?float $now = null): array
    {
        $now ??= \microtime(true);
        $directives = [];
        foreach ($this->gates as $digest => $gate) {
            $next = $gate->nextSweepAt();
            if ($next === null || $now < $next) {
                continue;
            }
            foreach ($gate->sweep($now) as $directive) {
                unset($this->owners[$directive->connectionId]);
                $directives[] = $directive;
            }
            $this->removeUnusedOldGate($digest);
        }
        return $directives;
    }

    public function nextSweepAt(): ?float
    {
        $next = null;
        foreach ($this->gates as $gate) {
            $candidate = $gate->nextSweepAt();
            if ($candidate !== null && ($next === null || $candidate < $next)) {
                $next = $candidate;
            }
        }
        return $next;
    }

    /**
     * Release owners whose transport adapter has already discarded its socket.
     * This keeps close accounting correct without forcing every legacy error
     * branch to duplicate gate cleanup code.
     *
     * @param array<int, int|string> $liveConnectionIds
     */
    public function reconcile(array $liveConnectionIds): void
    {
        $live = [];
        foreach ($liveConnectionIds as $connectionId) {
            $live[(string)$connectionId] = true;
        }
        foreach (\array_keys($this->owners) as $connectionKey) {
            $connectionId = (string)$connectionKey;
            if (!isset($live[$connectionId])) {
                $this->close($connectionId);
            }
        }
    }

    /**
     * Low-frequency safety net for legacy close branches. Transport adapters
     * should call close() directly where they own a common close funnel.
     *
     * @param array<int, int|string> $liveConnectionIds
     */
    public function reconcileIfDue(array $liveConnectionIds, ?float $now = null): void
    {
        $now ??= \microtime(true);
        if ($now < $this->nextReconcileAt) {
            return;
        }
        $this->nextReconcileAt = $now + self::RECONCILE_INTERVAL_SECONDS;
        $this->reconcile($liveConnectionIds);
    }

    /**
     * Map-oriented hot-loop variant. Passing the transport maps is O(1)
     * copy-on-write; keys are materialized only after the interval is due.
     *
     * @param array<int|string, mixed> ...$liveConnectionMaps
     */
    public function reconcileMapsIfDue(array ...$liveConnectionMaps): void
    {
        $now = \microtime(true);
        if ($now < $this->nextReconcileAt) {
            return;
        }
        $this->nextReconcileAt = $now + self::RECONCILE_INTERVAL_SECONDS;

        $live = [];
        foreach ($liveConnectionMaps as $map) {
            foreach ($map as $connectionId => $_value) {
                $live[(string)$connectionId] = true;
            }
        }
        foreach (\array_keys($this->owners) as $connectionKey) {
            $connectionId = (string)$connectionKey;
            if (!isset($live[$connectionId])) {
                $this->close($connectionId);
            }
        }
    }

    /** Build and validate the candidate gate before PREPARED_ACK. */
    public function prepareBundle(RuntimePolicyBundle $bundle): string
    {
        $digest = \strtolower(\trim($bundle->digest));
        if ($digest === '') {
            throw new \InvalidArgumentException('Prepared connection policy digest must not be empty.');
        }
        if ($this->preparedDigest !== '' && !\hash_equals($this->preparedDigest, $digest)) {
            throw new \RuntimeException('Another connection policy gate is already prepared.');
        }
        $this->gates[$digest] ??= $this->buildGate($bundle);
        $this->preparedDigest = $digest;
        return $digest;
    }

    public function hasPreparedBundle(string $digest): bool
    {
        $digest = \strtolower(\trim($digest));
        return $digest !== ''
            && \hash_equals($this->preparedDigest, $digest)
            && isset($this->gates[$digest]);
    }

    /** Atomically switch only to a gate that PREPARE already built. */
    public function activatePreparedBundle(string $digest): bool
    {
        $digest = \strtolower(\trim($digest));
        if ($digest !== '' && \hash_equals($this->currentDigest, $digest)) {
            $this->preparedDigest = '';
            return true;
        }
        if (!$this->hasPreparedBundle($digest)) {
            return false;
        }
        $previousDigest = $this->currentDigest;
        $this->currentDigest = $digest;
        $this->preparedDigest = '';
        $this->removeUnusedOldGate($previousDigest);
        return true;
    }

    /** Restore an active immutable bundle after rollback. */
    public function activateBundle(RuntimePolicyBundle $bundle): void
    {
        $digest = \strtolower(\trim($bundle->digest));
        if ($digest === '') {
            throw new \InvalidArgumentException('Active connection policy digest must not be empty.');
        }
        $this->gates[$digest] ??= $this->buildGate($bundle);
        $previousDigest = $this->currentDigest;
        $this->currentDigest = $digest;
        $this->preparedDigest = '';
        $this->removeUnusedOldGate($previousDigest);
    }

    public function abortPreparedBundle(?string $digest = null): void
    {
        $digest = \strtolower(\trim((string)$digest));
        if ($digest !== '' && $this->preparedDigest !== '' && !\hash_equals($this->preparedDigest, $digest)) {
            return;
        }
        $candidate = $this->preparedDigest;
        $this->preparedDigest = '';
        if ($candidate !== '') {
            $this->removeUnusedOldGate($candidate);
        }
    }

    public function attachState(?MemoryStateFacadeInterface $state): void
    {
        $this->state = $state;
        foreach ($this->gates as $gate) {
            $gate->attachState($state);
        }
    }

    public function clearBans(?string $ip = null, bool $clearAll = false): bool
    {
        $cleared = true;
        foreach ($this->gates as $gate) {
            $cleared = $gate->clearBans($ip, $clearAll) && $cleared;
        }

        return $cleared;
    }

    /** @return array{current_digest:string,prepared_digest:string,connections:int,gates:int} */
    public function stats(): array
    {
        return [
            'current_digest' => $this->currentDigest,
            'prepared_digest' => $this->preparedDigest,
            'connections' => \count($this->owners),
            'gates' => \count($this->gates),
        ];
    }

    private function currentGate(): ConnectionAcceptGate
    {
        $gate = $this->gates[$this->currentDigest] ?? null;
        if (!$gate instanceof ConnectionAcceptGate) {
            throw new \LogicException('Active connection accept gate is unavailable.');
        }
        return $gate;
    }

    private function ownerGate(string $connectionId): ?ConnectionAcceptGate
    {
        $digest = $this->owners[$connectionId] ?? null;
        return \is_string($digest) ? ($this->gates[$digest] ?? null) : null;
    }

    private function buildGate(RuntimePolicyBundle $bundle): ConnectionAcceptGate
    {
        return ConnectionAcceptGate::fromBundle(
            bundle: $bundle,
            topology: $this->topology,
            instanceName: $this->instanceName,
            state: $this->state,
            readyWorkers: $this->readyWorkers,
            workerOrdinal: $this->workerOrdinal,
        );
    }

    private function removeUnusedOldGate(string $digest): void
    {
        if (\hash_equals($this->currentDigest, $digest)) {
            return;
        }
        if ($this->preparedDigest !== '' && \hash_equals($this->preparedDigest, $digest)) {
            return;
        }
        foreach ($this->owners as $ownerDigest) {
            if (\hash_equals($ownerDigest, $digest)) {
                return;
            }
        }
        unset($this->gates[$digest]);
    }
}
