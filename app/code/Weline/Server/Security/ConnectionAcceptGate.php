<?php

declare(strict_types=1);

namespace Weline\Server\Security;

use Weline\Framework\Runtime\Policy\PolicyStage;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Server\Service\Contract\MemoryStateFacadeInterface;

/**
 * Shared L4 admission semantics for Dispatcher and POSIX direct Workers.
 *
 * Dispatcher constructs the gate with one partition. Direct Workers use the
 * READY Worker count and stable slot ordinal. Active connection capacity is
 * partitioned conservatively, so the sum of all local limits never exceeds
 * the instance limit and the fresh-connection path needs no shared IPC.
 *
 * Slowloris accounting is delayed until a connection exceeds the configured
 * grace period. Normal fresh connections therefore perform no shared
 * incomplete-connection operation. Only genuinely slow connections enter the
 * shared per-IP counter; when SharedState is unavailable, a conservative
 * largest-remainder local partition is used instead.
 */
final class ConnectionAcceptGate
{
    private const INCOMPLETE_NAMESPACE = 'wls.policy.incomplete_connections';

    /** @var array<string, true> */
    private array $denyExact = [];

    /** @var list<array{network:string,bytes:int,remainder:int,mask:int}> */
    private array $denyNetworks = [];

    /** @var array<string, true> */
    private array $whitelistExact = [];

    /** @var list<array{network:string,bytes:int,remainder:int,mask:int}> */
    private array $whitelistNetworks = [];

    /** @var array<string, true> */
    private array $trustedExact = [];

    /** @var list<array{network:string,bytes:int,remainder:int,mask:int}> */
    private array $trustedNetworks = [];

    /**
     * @var array<string, array{
     *     peer_ip:string,
     *     whitelisted:bool,
     *     incomplete:bool,
     *     accepted_at:float,
     *     grace_at:float,
     *     deadline:float,
     *     slow_mode:''|'shared'|'local'
     * }>
     */
    private array $connections = [];

    /** @var array<string, int> */
    private array $localSlowByIp = [];

    private int $readyWorkers;

    private int $workerOrdinal;

    private int $localConnectionLimit;

    private int $localSlowLimit;

    private int $accepted = 0;

    private int $rejected = 0;

    private int $slowPromotions = 0;

    private int $slowTimeouts = 0;

    private int $slowLimitRejects = 0;

    /**
     * Earliest possibly actionable slow-connection deadline.
     *
     * It may remain stale in the early direction after completion or close;
     * the first due sweep repairs it. Ordinary loop ticks stay O(1).
     */
    private ?float $nextSweepAt = null;

    private function __construct(
        private readonly string $instanceName,
        private readonly string $policyDigest,
        private readonly CanonicalClientIdentity $identity,
        private GlobalRateLimiter $rateLimiter,
        private ?MemoryStateFacadeInterface $state,
        private readonly int $maxActiveConnections,
        private readonly bool $connectionRateEnabled,
        private readonly int $connectionRateWindow,
        private readonly int $instanceConnectionRate,
        private readonly int $perIpConnectionRate,
        private readonly int $connectionRateBlockDuration,
        private readonly bool $slowlorisEnabled,
        private readonly int $maxIncompletePerIp,
        private readonly float $incompleteGraceSeconds,
        private readonly float $incompleteTimeoutSeconds,
        int $readyWorkers,
        int $workerOrdinal,
    ) {
        $this->readyWorkers = \max(1, $readyWorkers);
        $this->workerOrdinal = $this->normalizeWorkerOrdinal($workerOrdinal, $this->readyWorkers);
        $this->recalculatePartitions();
    }

    public static function fromBundle(
        RuntimePolicyBundle $bundle,
        string $topology,
        string $instanceName,
        ?MemoryStateFacadeInterface $state,
        int $readyWorkers,
        int $workerOrdinal,
    ): self {
        $topology = \strtolower(\trim($topology));
        if (!\in_array($topology, ['direct', 'dispatcher'], true)) {
            throw new \InvalidArgumentException('Connection accept topology must be direct or dispatcher.');
        }
        if (!$bundle->supportsTopology($topology)) {
            throw new \RuntimeException("Runtime policy {$bundle->digest} does not support {$topology} accept.");
        }

        $matcher = null;
        foreach ($bundle->descriptors as $descriptor) {
            if ($descriptor->stage !== PolicyStage::CONNECTION
                || ($descriptor->matcher['type'] ?? '') !== 'ip_policy'
                || !\in_array($topology, $descriptor->supportedTopologies, true)
            ) {
                continue;
            }
            if ($matcher !== null) {
                throw new \RuntimeException('Only one effective ip_policy descriptor is supported per topology.');
            }
            $matcher = $descriptor->matcher;
        }
        if (!\is_array($matcher)) {
            throw new \RuntimeException('Runtime policy bundle is missing the critical connection ip_policy.');
        }

        return self::fromMatcher(
            matcher: $matcher,
            instanceName: $instanceName,
            policyDigest: $bundle->digest,
            state: $state,
            readyWorkers: $topology === 'dispatcher' ? 1 : $readyWorkers,
            workerOrdinal: $topology === 'dispatcher' ? 0 : $workerOrdinal,
        );
    }

    /**
     * Build from the immutable descriptor matcher. Kept public for startup
     * probes and transport adapters that already hold the selected descriptor.
     *
     * @param array<string, mixed> $matcher
     */
    public static function fromMatcher(
        array $matcher,
        string $instanceName,
        string $policyDigest,
        ?MemoryStateFacadeInterface $state,
        int $readyWorkers,
        int $workerOrdinal,
    ): self {
        if (($matcher['type'] ?? '') !== 'ip_policy') {
            throw new \InvalidArgumentException('ConnectionAcceptGate requires an ip_policy matcher.');
        }

        $readyWorkers = \max(1, $readyWorkers);
        $identity = new CanonicalClientIdentity();
        $rate = \is_array($matcher['connection_rate'] ?? null) ? $matcher['connection_rate'] : [];
        $slow = \is_array($matcher['slowloris'] ?? null) ? $matcher['slowloris'] : [];
        $maxActive = \max(1, (int)($matcher['max_active_connections'] ?? 10_000));
        $rateWindow = \max(1, (int)($rate['window'] ?? 1));
        $instanceRate = \max(0, (int)($rate['max_connections'] ?? 0));
        $perIpRate = \max(0, (int)($rate['per_ip_max_connections'] ?? 0));
        $slowTimeout = \max(0.1, (float)($slow['incomplete_timeout'] ?? 30));
        $slowGrace = \max(0.01, (float)($slow['grace_seconds'] ?? 0.25));
        $slowGrace = \min($slowGrace, $slowTimeout);

        $gate = new self(
            instanceName: $instanceName !== '' ? $instanceName : 'default',
            policyDigest: \strtolower(\trim($policyDigest)),
            identity: $identity,
            rateLimiter: new GlobalRateLimiter($state, $readyWorkers, $instanceName),
            state: $state,
            maxActiveConnections: $maxActive,
            connectionRateEnabled: (bool)($rate['enabled'] ?? false)
                && ($instanceRate > 0 || $perIpRate > 0),
            connectionRateWindow: $rateWindow,
            instanceConnectionRate: $instanceRate,
            perIpConnectionRate: $perIpRate,
            connectionRateBlockDuration: \max(1, (int)($rate['block_duration'] ?? 30)),
            slowlorisEnabled: (bool)($slow['enabled'] ?? true),
            maxIncompletePerIp: \max(1, (int)($slow['max_incomplete_conns'] ?? 10)),
            incompleteGraceSeconds: $slowGrace,
            incompleteTimeoutSeconds: $slowTimeout,
            readyWorkers: $readyWorkers,
            workerOrdinal: $workerOrdinal,
        );
        [$gate->denyExact, $gate->denyNetworks] = $gate->compileCidrSet(
            $matcher['deny_cidrs'] ?? [],
            'deny_cidrs',
        );
        [$gate->whitelistExact, $gate->whitelistNetworks] = $gate->compileCidrSet(
            $matcher['whitelist_cidrs'] ?? [],
            'whitelist_cidrs',
        );
        [$gate->trustedExact, $gate->trustedNetworks] = $gate->compileCidrSet(
            $matcher['trusted_proxy_cidrs'] ?? [],
            'trusted_proxy_cidrs',
        );

        return $gate;
    }

    public function accept(
        string $connectionId,
        string $transportPeer,
        ?float $now = null,
    ): ConnectionAcceptDecision {
        $connectionId = \trim($connectionId);
        if ($connectionId === '') {
            throw new \InvalidArgumentException('Connection id must not be empty.');
        }
        if (isset($this->connections[$connectionId])) {
            throw new \LogicException("Connection {$connectionId} is already tracked by the accept gate.");
        }

        $now ??= \microtime(true);
        $peerIp = $this->identity->normalizePeer($transportPeer);
        if ($peerIp === '') {
            return $this->reject('', 'invalid_peer');
        }
        $whitelisted = $this->matchesSet($peerIp, $this->whitelistExact, $this->whitelistNetworks);
        $trustedSource = $this->matchesSet($peerIp, $this->trustedExact, $this->trustedNetworks);

        if (!$whitelisted && $this->matchesSet($peerIp, $this->denyExact, $this->denyNetworks)) {
            return $this->reject($peerIp, 'ip_denied', false, $trustedSource);
        }
        if ($this->connectionRateEnabled
            && !$whitelisted
            && $this->perIpConnectionRate > 0
            && $this->rateLimiter->isBanned($peerIp)
        ) {
            return $this->reject($peerIp, 'connection_rate_ban', false, $trustedSource);
        }
        if (\count($this->connections) >= $this->localConnectionLimit) {
            return $this->reject($peerIp, 'connection_capacity', $whitelisted, $trustedSource);
        }
        if ($this->connectionRateEnabled) {
            if (!$whitelisted
                && $this->perIpConnectionRate > 0
                && !$this->rateLimiter->allow(
                    'connection:peer',
                    $peerIp,
                    $this->perIpConnectionRate,
                    $this->connectionRateWindow,
                )
            ) {
                $this->rateLimiter->ban($peerIp, $this->connectionRateBlockDuration);
                return $this->reject($peerIp, 'connection_rate_peer', false, $trustedSource);
            }
            if ($this->instanceConnectionRate > 0
                && !$this->rateLimiter->allow(
                    'connection:instance',
                    'all',
                    $this->instanceConnectionRate,
                    $this->connectionRateWindow,
                )
            ) {
                return $this->reject($peerIp, 'connection_rate_instance', $whitelisted, $trustedSource);
            }
        }

        $deadline = $this->slowlorisEnabled ? $now + $this->incompleteTimeoutSeconds : 0.0;
        $this->connections[$connectionId] = [
            'peer_ip' => $peerIp,
            'whitelisted' => $whitelisted,
            'incomplete' => $this->slowlorisEnabled,
            'accepted_at' => $now,
            'grace_at' => $this->slowlorisEnabled ? $now + $this->incompleteGraceSeconds : 0.0,
            'deadline' => $deadline,
            'slow_mode' => '',
        ];
        $this->accepted++;
        if ($this->slowlorisEnabled) {
            $this->scheduleSweepAt($now + $this->incompleteGraceSeconds);
        }

        return ConnectionAcceptDecision::allow($peerIp, $whitelisted, $trustedSource, $deadline);
    }

    /** Start slow-request accounting again for a keep-alive request. */
    public function beginRequest(string $connectionId, ?float $now = null): bool
    {
        if (!$this->slowlorisEnabled || !isset($this->connections[$connectionId])) {
            return false;
        }
        if ($this->connections[$connectionId]['incomplete']) {
            return true;
        }
        $now ??= \microtime(true);
        $this->connections[$connectionId]['incomplete'] = true;
        $this->connections[$connectionId]['accepted_at'] = $now;
        $this->connections[$connectionId]['grace_at'] = $now + $this->incompleteGraceSeconds;
        $this->connections[$connectionId]['deadline'] = $now + $this->incompleteTimeoutSeconds;
        $this->connections[$connectionId]['slow_mode'] = '';
        $this->scheduleSweepAt($this->connections[$connectionId]['grace_at']);
        return true;
    }

    public function markRequestComplete(string $connectionId): bool
    {
        if (!isset($this->connections[$connectionId])) {
            return false;
        }
        if (!$this->connections[$connectionId]['incomplete']) {
            return true;
        }
        $this->releaseSlowTracking($this->connections[$connectionId]);
        $this->connections[$connectionId]['incomplete'] = false;
        $this->connections[$connectionId]['accepted_at'] = 0.0;
        $this->connections[$connectionId]['grace_at'] = 0.0;
        $this->connections[$connectionId]['deadline'] = 0.0;
        $this->connections[$connectionId]['slow_mode'] = '';
        return true;
    }

    public function close(string $connectionId): bool
    {
        if (!isset($this->connections[$connectionId])) {
            return false;
        }
        $entry = $this->connections[$connectionId];
        unset($this->connections[$connectionId]);
        if ($entry['incomplete']) {
            $this->releaseSlowTracking($entry);
        }
        if ($this->connections === []) {
            $this->nextSweepAt = null;
        }
        return true;
    }

    /**
     * Promote only connections that outlive the grace period into shared
     * slowloris accounting and return transport-neutral close instructions.
     *
     * @return list<ConnectionCloseDirective>
     */
    public function sweep(?float $now = null): array
    {
        if (!$this->slowlorisEnabled || $this->connections === []) {
            $this->nextSweepAt = null;
            return [];
        }
        $now ??= \microtime(true);
        if ($this->nextSweepAt !== null && $now < $this->nextSweepAt) {
            return [];
        }
        $close = [];
        $next = null;
        foreach (\array_keys($this->connections) as $connectionKey) {
            $connectionId = (string)$connectionKey;
            $entry = $this->connections[$connectionId] ?? null;
            if (!\is_array($entry) || !$entry['incomplete']) {
                continue;
            }
            if ($entry['deadline'] > 0.0 && $now >= $entry['deadline']) {
                $close[] = new ConnectionCloseDirective($connectionId, 'slowloris_timeout', $entry['peer_ip']);
                $this->slowTimeouts++;
                $this->close($connectionId);
                continue;
            }
            if ($entry['slow_mode'] === '' && $now >= $entry['grace_at']) {
                if (!$this->promoteSlowConnection($connectionId)) {
                    $close[] = new ConnectionCloseDirective($connectionId, 'slowloris_limit', $entry['peer_ip']);
                    $this->slowLimitRejects++;
                    $this->close($connectionId);
                    continue;
                }
            }

            $entry = $this->connections[$connectionId] ?? null;
            if (!\is_array($entry) || !$entry['incomplete']) {
                continue;
            }
            $candidate = $entry['slow_mode'] === '' ? $entry['grace_at'] : $entry['deadline'];
            if ($candidate > 0.0 && ($next === null || $candidate < $next)) {
                $next = $candidate;
            }
        }
        $this->nextSweepAt = $next;
        return $close;
    }

    public function attachState(?MemoryStateFacadeInterface $state): void
    {
        $this->state = $state;
        $this->rateLimiter->attachState($state);
    }

    public function clearBans(?string $ip = null, bool $clearAll = false): bool
    {
        return $this->rateLimiter->clearBans($ip, $clearAll);
    }

    /**
     * Apply a newly ACKed READY partition. Stable Worker slot ordinals are
     * required so largest-remainder quotas sum exactly to the instance limit.
     */
    public function updateWorkerPartition(int $readyWorkers, int $workerOrdinal): void
    {
        $this->readyWorkers = \max(1, $readyWorkers);
        $this->workerOrdinal = $this->normalizeWorkerOrdinal($workerOrdinal, $this->readyWorkers);
        $this->recalculatePartitions();
        // A pool-width change also changes the limiter's conservative
        // SharedState-down quota. Outstanding shared token reservations stay
        // counted until their short window expires, which can only under-use
        // the limit during the transition and can never multiply it.
        $this->rateLimiter = new GlobalRateLimiter(
            $this->state,
            $this->readyWorkers,
            $this->instanceName,
        );
    }

    public function nextSweepAt(): ?float
    {
        return $this->nextSweepAt;
    }

    /** @return array<string, int|string> */
    public function stats(): array
    {
        return [
            'policy_digest' => $this->policyDigest,
            'active_connections' => \count($this->connections),
            'local_connection_limit' => $this->localConnectionLimit,
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
            'slow_promotions' => $this->slowPromotions,
            'slow_timeouts' => $this->slowTimeouts,
            'slow_limit_rejects' => $this->slowLimitRejects,
        ];
    }

    private function promoteSlowConnection(string $connectionId): bool
    {
        $entry = $this->connections[$connectionId] ?? null;
        if (!\is_array($entry) || !$entry['incomplete'] || $entry['slow_mode'] !== '') {
            return true;
        }
        if ($entry['whitelisted']) {
            $this->connections[$connectionId]['slow_mode'] = 'local';
            return true;
        }

        $peerIp = $entry['peer_ip'];
        $ttl = (int)\ceil($this->incompleteTimeoutSeconds + 2.0);
        if ($this->state !== null) {
            try {
                $count = $this->state->incr(
                    self::INCOMPLETE_NAMESPACE,
                    $this->slowSharedKey($peerIp),
                    1,
                    $ttl,
                );
                if ($count !== null) {
                    if ($count > $this->maxIncompletePerIp) {
                        try {
                            $this->state->decr(
                                self::INCOMPLETE_NAMESPACE,
                                $this->slowSharedKey($peerIp),
                                1,
                                $ttl,
                            );
                        } catch (\Throwable) {
                        }
                        return false;
                    }
                    $this->connections[$connectionId]['slow_mode'] = 'shared';
                    $this->slowPromotions++;
                    return true;
                }
            } catch (\Throwable) {
                // Fall through to an exact conservative local partition.
            }
        }

        $local = (int)($this->localSlowByIp[$peerIp] ?? 0);
        if ($local >= $this->localSlowLimit) {
            return false;
        }
        $this->localSlowByIp[$peerIp] = $local + 1;
        $this->connections[$connectionId]['slow_mode'] = 'local';
        $this->slowPromotions++;
        return true;
    }

    private function scheduleSweepAt(float $candidate): void
    {
        if ($candidate <= 0.0) {
            return;
        }
        if ($this->nextSweepAt === null || $candidate < $this->nextSweepAt) {
            $this->nextSweepAt = $candidate;
        }
    }

    /**
     * @param array{peer_ip:string,whitelisted:bool,incomplete:bool,accepted_at:float,grace_at:float,deadline:float,slow_mode:''|'shared'|'local'} $entry
     */
    private function releaseSlowTracking(array $entry): void
    {
        $mode = $entry['slow_mode'];
        if ($mode === '') {
            return;
        }
        $peerIp = $entry['peer_ip'];
        if ($mode === 'local') {
            $count = (int)($this->localSlowByIp[$peerIp] ?? 0);
            if ($count <= 1) {
                unset($this->localSlowByIp[$peerIp]);
            } else {
                $this->localSlowByIp[$peerIp] = $count - 1;
            }
            return;
        }
        if ($this->state === null) {
            return;
        }
        try {
            $this->state->decr(
                self::INCOMPLETE_NAMESPACE,
                $this->slowSharedKey($peerIp),
                1,
                (int)\ceil($this->incompleteTimeoutSeconds + 2.0),
            );
        } catch (\Throwable) {
            // Counter TTL is the process-crash and short-disconnect fence.
        }
    }

    private function recalculatePartitions(): void
    {
        $this->localConnectionLimit = $this->partitionShare(
            $this->maxActiveConnections,
            $this->readyWorkers,
            $this->workerOrdinal,
        );
        $this->localSlowLimit = $this->partitionShare(
            $this->maxIncompletePerIp,
            $this->readyWorkers,
            $this->workerOrdinal,
        );
    }

    private function partitionShare(int $limit, int $workers, int $ordinal): int
    {
        if ($limit <= 0) {
            return 0;
        }
        $base = \intdiv($limit, $workers);
        return $base + ($ordinal < ($limit % $workers) ? 1 : 0);
    }

    private function normalizeWorkerOrdinal(int $ordinal, int $workers): int
    {
        if ($ordinal < 0 || $ordinal >= $workers) {
            throw new \InvalidArgumentException(
                "Worker ordinal {$ordinal} must be within 0.." . ($workers - 1) . '.',
            );
        }
        return $ordinal;
    }

    private function slowSharedKey(string $peerIp): string
    {
        return \hash('xxh3', $this->instanceName) . ':' . \hash('xxh3', $peerIp);
    }

    private function reject(
        string $peerIp,
        string $reason,
        bool $whitelisted = false,
        bool $trustedSource = false,
    ): ConnectionAcceptDecision {
        $this->rejected++;
        return ConnectionAcceptDecision::deny($peerIp, $reason, $whitelisted, $trustedSource);
    }

    /**
     * @param mixed $values
     * @return array{0:array<string,true>,1:list<array{network:string,bytes:int,remainder:int,mask:int}>}
     */
    private function compileCidrSet(mixed $values, string $field): array
    {
        if (!\is_array($values)) {
            throw new \InvalidArgumentException("ip_policy.{$field} must be a list.");
        }
        $exact = [];
        $networks = [];
        foreach ($values as $value) {
            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (!\str_contains($value, '/')) {
                $normalized = $this->identity->normalizeIp($value);
                if ($normalized === '') {
                    throw new \InvalidArgumentException("ip_policy.{$field} contains invalid IP {$value}.");
                }
                $exact[$normalized] = true;
                continue;
            }
            [$network, $prefixRaw] = \explode('/', $value, 2);
            $packed = @\inet_pton($network);
            if (!\is_string($packed)
                || !\preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $prefixRaw)
            ) {
                throw new \InvalidArgumentException("ip_policy.{$field} contains invalid CIDR {$value}.");
            }
            $prefix = (int)$prefixRaw;
            $maxBits = \strlen($packed) * 8;
            if ($prefix < 0 || $prefix > $maxBits) {
                throw new \InvalidArgumentException("ip_policy.{$field} contains invalid CIDR {$value}.");
            }
            $bytes = \intdiv($prefix, 8);
            $remainder = $prefix % 8;
            $networks[] = [
                'network' => $packed,
                'bytes' => $bytes,
                'remainder' => $remainder,
                'mask' => $remainder === 0 ? 0 : ((0xff << (8 - $remainder)) & 0xff),
            ];
        }
        return [$exact, $networks];
    }

    /**
     * @param array<string, true> $exact
     * @param list<array{network:string,bytes:int,remainder:int,mask:int}> $networks
     */
    private function matchesSet(string $ip, array $exact, array $networks): bool
    {
        if (isset($exact[$ip])) {
            return true;
        }
        if ($networks === []) {
            return false;
        }
        $packed = @\inet_pton($ip);
        if (!\is_string($packed)) {
            return false;
        }
        $length = \strlen($packed);
        foreach ($networks as $network) {
            if (\strlen($network['network']) !== $length) {
                continue;
            }
            $bytes = $network['bytes'];
            if ($bytes > 0 && \substr($packed, 0, $bytes) !== \substr($network['network'], 0, $bytes)) {
                continue;
            }
            if ($network['remainder'] === 0
                || ((\ord($packed[$bytes]) & $network['mask'])
                    === (\ord($network['network'][$bytes]) & $network['mask']))
            ) {
                return true;
            }
        }
        return false;
    }
}
