<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\ServerInstanceManager;

/**
 * Project-local CAS fence for gateway registration mutations.
 *
 * A missing receipt is deliberately not treated as proof that register never
 * committed. Every register/renew mutation first moves this exact Master
 * launch to REGISTERING. Stop atomically moves the launch to RETIRING before
 * it inspects authenticated own-status, preventing a later Agent replay from
 * racing an apparently empty status response.
 */
final class GatewayRegistrationLifecycle
{
    public const SCHEMA_VERSION = 1;

    public const STATE_NEVER_ATTEMPTED = 'NEVER_ATTEMPTED';
    public const STATE_REGISTERING = 'REGISTERING';
    public const STATE_REGISTERED = 'REGISTERED';
    public const STATE_UNCERTAIN = 'UNCERTAIN';
    public const STATE_RETIRING = 'RETIRING';
    public const STATE_RETIRED = 'RETIRED';
    public const STATE_UNKNOWN = 'UNKNOWN';

    private const MUTATIONS = ['register', 'renew'];

    private const MAX_ATOMIC_JSON_BYTES = 16 * 1024 * 1024;

    private const RECOVERY_LOCK_WAIT_SECONDS = 0.25;

    private const CLEANUP_LOCK_WAIT_SECONDS = 0.25;

    private const CLEANUP_DEADLINE_SECONDS = 1.0;

    /** @var \Closure(string,callable,float):bool */
    private readonly \Closure $atomicUpdater;

    /** @var \Closure():float */
    private readonly \Closure $monotonicClock;

    public function __construct(
        private readonly ?\Closure $instanceFileResolver = null,
        ?\Closure $atomicUpdater = null,
        ?\Closure $monotonicClock = null,
    ) {
        $this->atomicUpdater = $atomicUpdater ?? static fn (
            string $file,
            callable $modifier,
            float $timeout,
        ): bool => ServerInstanceManager::updateJsonFileAtomically(
            $file,
            $modifier,
            $timeout,
        );
        $this->monotonicClock = $monotonicClock
            ?? static fn (): float => \hrtime(true) / 1_000_000_000;
    }

    /**
     * @return array<string,mixed>
     */
    public static function initial(
        string $projectUuid,
        string $instanceId,
        int $instanceGeneration,
        string $launchId,
        ?int $now = null,
    ): array {
        self::assertIdentity(
            $projectUuid,
            $instanceId,
            $instanceGeneration,
            $launchId,
        );
        return self::seal([
            'schema_version' => self::SCHEMA_VERSION,
            'state' => self::STATE_NEVER_ATTEMPTED,
            'project_uuid' => \strtolower($projectUuid),
            'instance_id' => $instanceId,
            'instance_generation' => $instanceGeneration,
            'launch_id' => \strtolower($launchId),
            'master_pid' => 0,
            'master_epoch' => 0,
            'attempt_sequence' => 0,
            'mutation' => '',
            'previous_state' => '',
            'retirement_nonce' => '',
            'reason' => '',
            'updated_timestamp' => $now ?? \time(),
        ]);
    }

    /**
     * Fence the exact launch before a register or renew can mutate the host.
     *
     * @return array{attempt_sequence:int,mutation:string,fact:array<string,mixed>}
     */
    public function beginMutation(
        string $instanceName,
        string $mutation,
        float $waitTimeoutSeconds = 5.0,
        ?float $deadlineMonotonic = null,
    ): array
    {
        self::assertInstanceName($instanceName);
        $mutation = \strtolower(\trim($mutation));
        if (!\in_array($mutation, self::MUTATIONS, true)) {
            throw new \InvalidArgumentException(
                'Gateway lifecycle mutation must be register or renew.',
            );
        }
        $result = [];
        $this->update(
            $instanceName,
            static function (array $endpoint) use ($mutation, &$result): array {
                $identity = self::endpointIdentity($endpoint);
                $lifecycleState = \strtolower(\trim((string)(
                    $endpoint['lifecycle_state'] ?? ''
                )));
                if (\in_array($lifecycleState, ['stopping', 'stopped'], true)) {
                    throw new \RuntimeException(
                        'Gateway registration is fenced by project shutdown.',
                    );
                }
                $fact = self::factForMutation($endpoint);
                $state = (string)($fact['state'] ?? self::STATE_UNKNOWN);
                if (\in_array($state, [
                    self::STATE_RETIRING,
                    self::STATE_RETIRED,
                ], true)) {
                    throw new \RuntimeException(
                        'Gateway registration is fenced by project retirement.',
                    );
                }
                if ($state === self::STATE_REGISTERING) {
                    throw new \RuntimeException(
                        'Another gateway registration mutation is already in progress.',
                    );
                }
                $sequence = \max(0, (int)($fact['attempt_sequence'] ?? 0)) + 1;
                $next = self::seal([
                    'schema_version' => self::SCHEMA_VERSION,
                    'state' => self::STATE_REGISTERING,
                    'project_uuid' => $identity['project_uuid'],
                    'instance_id' => $identity['instance_id'],
                    'instance_generation' => $identity['instance_generation'],
                    'launch_id' => $identity['launch_id'],
                    'master_pid' => $identity['master_pid'],
                    'master_epoch' => $identity['master_epoch'],
                    'attempt_sequence' => $sequence,
                    'mutation' => $mutation,
                    'previous_state' => '',
                    'retirement_nonce' => '',
                    'reason' => '',
                    'updated_timestamp' => \time(),
                ]);
                $endpoint['gateway']['registration_lifecycle'] = $next;
                $result = [
                    'attempt_sequence' => $sequence,
                    'mutation' => $mutation,
                    'fact' => $next,
                ];
                return $endpoint;
            },
            $waitTimeoutSeconds,
            $deadlineMonotonic,
            static function (array $endpoint) use (&$result): ?bool {
                $expected = \is_array($result['fact'] ?? null)
                    ? $result['fact']
                    : [];
                return $expected === []
                    ? null
                    : self::endpointContainsExactFact($endpoint, $expected);
            },
        );
        if ($result === []) {
            throw new \RuntimeException('Gateway registration lifecycle was not published.');
        }
        return $result;
    }

    public function markRegistered(
        string $instanceName,
        int $attemptSequence,
        string $mutation,
        float $waitTimeoutSeconds = 5.0,
        ?float $deadlineMonotonic = null,
    ): bool {
        return $this->finishMutation(
            $instanceName,
            $attemptSequence,
            $mutation,
            self::STATE_REGISTERED,
            '',
            $waitTimeoutSeconds,
            $deadlineMonotonic,
        );
    }

    public function markUncertain(
        string $instanceName,
        int $attemptSequence,
        string $mutation,
        string $reason,
        float $waitTimeoutSeconds = self::CLEANUP_LOCK_WAIT_SECONDS,
        ?float $cleanupDeadlineMonotonic = null,
    ): bool {
        // This is failure compensation, not continuation of the expired host
        // mutation. Give it an independent bounded local deadline so the
        // exact REGISTERING fact cannot be stranded indefinitely.
        $cleanupDeadlineMonotonic ??= $this->monotonicNow()
            + self::CLEANUP_DEADLINE_SECONDS;
        return $this->finishMutation(
            $instanceName,
            $attemptSequence,
            $mutation,
            self::STATE_UNCERTAIN,
            $reason,
            $waitTimeoutSeconds,
            $cleanupDeadlineMonotonic,
        );
    }

    /**
     * Prevent later Agent mutations before Stop queries own-status.
     *
     * @return array{nonce:string,previous_state:string,attempt_sequence:int,fact:array<string,mixed>,endpoint:array<string,mixed>}
     */
    public function claimRetirement(
        string $instanceName,
        float $waitTimeoutSeconds = 5.0,
        ?float $deadlineMonotonic = null,
    ): array {
        self::assertInstanceName($instanceName);
        $nonce = \bin2hex(\random_bytes(16));
        $result = [];
        $this->update(
            $instanceName,
            static function (array $endpoint) use ($nonce, &$result): array {
                $identity = self::endpointIdentity($endpoint);
                $fact = self::factForMutation($endpoint);
                $state = (string)($fact['state'] ?? self::STATE_UNKNOWN);
                $previous = $state === self::STATE_RETIRING
                    ? (string)($fact['previous_state'] ?? self::STATE_UNKNOWN)
                    : $state;
                if (!\in_array($previous, [
                    self::STATE_NEVER_ATTEMPTED,
                    self::STATE_REGISTERING,
                    self::STATE_REGISTERED,
                    self::STATE_UNCERTAIN,
                    self::STATE_RETIRED,
                    self::STATE_UNKNOWN,
                ], true)) {
                    $previous = self::STATE_UNKNOWN;
                }
                $retiringMutation = $previous === self::STATE_REGISTERING
                    && \in_array((string)($fact['mutation'] ?? ''), self::MUTATIONS, true)
                        ? (string)$fact['mutation']
                        : '';
                $next = self::seal([
                    'schema_version' => self::SCHEMA_VERSION,
                    'state' => self::STATE_RETIRING,
                    'project_uuid' => $identity['project_uuid'],
                    'instance_id' => $identity['instance_id'],
                    'instance_generation' => $identity['instance_generation'],
                    'launch_id' => $identity['launch_id'],
                    'master_pid' => $identity['master_pid'],
                    'master_epoch' => $identity['master_epoch'],
                    'attempt_sequence' => \max(0, (int)(
                        $fact['attempt_sequence'] ?? 0
                    )),
                    'mutation' => $retiringMutation,
                    'previous_state' => $previous,
                    'retirement_nonce' => $nonce,
                    'reason' => '',
                    'updated_timestamp' => \time(),
                ]);
                $endpoint['gateway']['registration_lifecycle'] = $next;
                $result = [
                    'nonce' => $nonce,
                    'previous_state' => $previous,
                    'attempt_sequence' => (int)$next['attempt_sequence'],
                    'fact' => $next,
                    'endpoint' => $endpoint,
                ];
                return $endpoint;
            },
            $waitTimeoutSeconds,
            $deadlineMonotonic,
            static function (array $endpoint) use (&$result): ?bool {
                $expected = \is_array($result['fact'] ?? null)
                    ? $result['fact']
                    : [];
                return $expected === []
                    ? null
                    : self::endpointContainsExactFact($endpoint, $expected);
            },
        );
        if ($result === []) {
            throw new \RuntimeException('Gateway retirement fence was not published.');
        }
        return $result;
    }

    public function cancelRetirement(
        string $instanceName,
        string $nonce,
        float $waitTimeoutSeconds = self::CLEANUP_LOCK_WAIT_SECONDS,
        ?float $cleanupDeadlineMonotonic = null,
    ): bool {
        self::assertInstanceName($instanceName);
        $nonce = \strtolower(\trim($nonce));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            return false;
        }
        // Cancellation is failure compensation after the retirement operation
        // has stopped. It must not inherit an already-exhausted host deadline,
        // but it also must never regain the old five-minute lock default.
        $cleanupDeadlineMonotonic ??= $this->monotonicNow()
            + self::CLEANUP_DEADLINE_SECONDS;
        $cancelled = false;
        $expectedFact = [];
        $this->update(
            $instanceName,
            static function (array $endpoint) use (
                $nonce,
                &$cancelled,
                &$expectedFact,
            ): array {
                $fact = self::factForEndpoint($endpoint);
                if (!\hash_equals(self::STATE_RETIRING, (string)($fact['state'] ?? ''))
                    || !\hash_equals($nonce, (string)(
                        $fact['retirement_nonce'] ?? ''
                    ))
                ) {
                    return $endpoint;
                }
                $identity = self::endpointIdentity($endpoint);
                $previous = (string)($fact['previous_state'] ?? self::STATE_UNKNOWN);
                if (!\in_array($previous, [
                    self::STATE_NEVER_ATTEMPTED,
                    self::STATE_REGISTERING,
                    self::STATE_REGISTERED,
                    self::STATE_UNCERTAIN,
                    self::STATE_RETIRED,
                    self::STATE_UNKNOWN,
                ], true)) {
                    $previous = self::STATE_UNKNOWN;
                }
                $restoredMutation = $previous === self::STATE_REGISTERING
                    && \in_array((string)($fact['mutation'] ?? ''), self::MUTATIONS, true)
                        ? (string)$fact['mutation']
                        : '';
                // The host mutation may have completed while RETIRING prevented
                // its terminal markRegistered/markUncertain write. Restoring
                // REGISTERING would invent a still-live executor and can wedge
                // this launch forever. UNCERTAIN permits a full idempotent
                // replay while retaining the attempt and mutation for audit.
                $restoredState = $previous === self::STATE_REGISTERING
                    ? self::STATE_UNCERTAIN
                    : $previous;
                $next = self::seal([
                    'schema_version' => self::SCHEMA_VERSION,
                    'state' => $restoredState,
                    'project_uuid' => $identity['project_uuid'],
                    'instance_id' => $identity['instance_id'],
                    'instance_generation' => $identity['instance_generation'],
                    'launch_id' => $identity['launch_id'],
                    'master_pid' => $identity['master_pid'],
                    'master_epoch' => $identity['master_epoch'],
                    'attempt_sequence' => \max(0, (int)(
                        $fact['attempt_sequence'] ?? 0
                    )),
                    'mutation' => $restoredMutation,
                    'previous_state' => '',
                    'retirement_nonce' => '',
                    'reason' => $previous === self::STATE_REGISTERING
                        ? 'retirement_cancelled_inflight_outcome_uncertain'
                        : 'retirement_cancelled',
                    'updated_timestamp' => \time(),
                ]);
                $endpoint['gateway']['registration_lifecycle'] = $next;
                $expectedFact = $next;
                $cancelled = true;
                return $endpoint;
            },
            $waitTimeoutSeconds,
            $cleanupDeadlineMonotonic,
            static function (array $endpoint) use (&$expectedFact): ?bool {
                return $expectedFact === []
                    ? null
                    : self::endpointContainsExactFact($endpoint, $expectedFact);
            },
        );
        return $cancelled;
    }

    /**
     * Persist that host state for this exact launch is already absent. Stop
     * calls this only after authenticated absence or a committed unregister.
     * The exact retirement nonce prevents an older Stop attempt from retiring
     * a launch after another actor has replaced its fence.
     */
    public function completeRetirement(
        string $instanceName,
        string $nonce,
        string $reason,
        float $waitTimeoutSeconds = self::CLEANUP_LOCK_WAIT_SECONDS,
        ?float $cleanupDeadlineMonotonic = null,
    ): bool {
        self::assertInstanceName($instanceName);
        $nonce = \strtolower(\trim($nonce));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            return false;
        }
        // Host retirement/absence is already authoritative at this point.
        // Persist the local terminal fact under a fresh, deliberately tiny
        // compensation budget instead of laundering the expired host budget
        // or waiting long enough for a later launch to replace the fence.
        $cleanupDeadlineMonotonic ??= $this->monotonicNow()
            + self::CLEANUP_DEADLINE_SECONDS;
        $reason = self::boundedReason($reason);
        $completed = false;
        $expectedFact = [];
        $this->update(
            $instanceName,
            static function (array $endpoint) use (
                $nonce,
                $reason,
                &$completed,
                &$expectedFact,
            ): array {
                $fact = self::factForEndpoint($endpoint);
                if ($fact === []
                    || !\hash_equals(self::STATE_RETIRING, (string)$fact['state'])
                    || !\hash_equals(
                        $nonce,
                        (string)($fact['retirement_nonce'] ?? ''),
                    )
                ) {
                    return $endpoint;
                }
                $identity = self::endpointIdentity($endpoint);
                $next = self::seal([
                    'schema_version' => self::SCHEMA_VERSION,
                    'state' => self::STATE_RETIRED,
                    'project_uuid' => $identity['project_uuid'],
                    'instance_id' => $identity['instance_id'],
                    'instance_generation' => $identity['instance_generation'],
                    'launch_id' => $identity['launch_id'],
                    'master_pid' => $identity['master_pid'],
                    'master_epoch' => $identity['master_epoch'],
                    'attempt_sequence' => \max(0, (int)(
                        $fact['attempt_sequence'] ?? 0
                    )),
                    'mutation' => '',
                    'previous_state' => '',
                    'retirement_nonce' => '',
                    'reason' => $reason,
                    'updated_timestamp' => \time(),
                ]);
                $endpoint['gateway']['registration_lifecycle'] = $next;
                $expectedFact = $next;
                $completed = true;
                return $endpoint;
            },
            $waitTimeoutSeconds,
            $cleanupDeadlineMonotonic,
            static function (array $endpoint) use (&$expectedFact): ?bool {
                return $expectedFact === []
                    ? null
                    : self::endpointContainsExactFact($endpoint, $expectedFact);
            },
        );
        return $completed;
    }

    /** @param array<string,mixed> $endpoint */
    public static function factForEndpoint(array $endpoint): array
    {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $fact = \is_array($gateway['registration_lifecycle'] ?? null)
            ? $gateway['registration_lifecycle']
            : [];
        if (!self::factMatchesEndpoint($fact, $endpoint)) {
            return [];
        }
        return $fact;
    }

    /** @param array<string,mixed> $endpoint */
    private static function factForMutation(array $endpoint): array
    {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        if (!\array_key_exists('registration_lifecycle', $gateway)) {
            return [];
        }
        $fact = self::factForEndpoint($endpoint);
        if ($fact === []) {
            throw new \RuntimeException(
                'Gateway registration lifecycle fact is invalid.',
            );
        }
        return $fact;
    }

    /** @param array<string,mixed> $endpoint */
    public static function provesNeverAttemptedRetirement(array $endpoint): bool
    {
        $fact = self::factForEndpoint($endpoint);
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        return $fact !== []
            && \hash_equals(self::STATE_RETIRING, (string)$fact['state'])
            && \hash_equals(
                self::STATE_NEVER_ATTEMPTED,
                (string)$fact['previous_state'],
            )
            && (int)$fact['attempt_sequence'] === 0
            && !\is_array($gateway['lease_receipt'] ?? null)
            && !\is_array($gateway['runtime_project_proof'] ?? null)
            && !GatewayRuntimeServingProjection::gatewayIsServing($endpoint);
    }

    private function finishMutation(
        string $instanceName,
        int $attemptSequence,
        string $mutation,
        string $state,
        string $reason,
        float $waitTimeoutSeconds,
        ?float $deadlineMonotonic,
    ): bool {
        self::assertInstanceName($instanceName);
        $mutation = \strtolower(\trim($mutation));
        if ($attemptSequence < 1
            || !\in_array($mutation, self::MUTATIONS, true)
            || !\in_array($state, [
                self::STATE_REGISTERED,
                self::STATE_UNCERTAIN,
            ], true)
        ) {
            throw new \InvalidArgumentException(
                'Gateway lifecycle completion fence is invalid.',
            );
        }
        $reason = self::boundedReason($reason);
        $finished = false;
        $expectedFact = [];
        $this->update(
            $instanceName,
            static function (array $endpoint) use (
                $attemptSequence,
                $mutation,
                $state,
                $reason,
                &$finished,
                &$expectedFact,
            ): array {
                $fact = self::factForEndpoint($endpoint);
                if ($fact === []
                    || !\hash_equals(self::STATE_REGISTERING, (string)$fact['state'])
                    || (int)$fact['attempt_sequence'] !== $attemptSequence
                    || !\hash_equals($mutation, (string)$fact['mutation'])
                ) {
                    return $endpoint;
                }
                $identity = self::endpointIdentity($endpoint);
                $next = self::seal([
                    'schema_version' => self::SCHEMA_VERSION,
                    'state' => $state,
                    'project_uuid' => $identity['project_uuid'],
                    'instance_id' => $identity['instance_id'],
                    'instance_generation' => $identity['instance_generation'],
                    'launch_id' => $identity['launch_id'],
                    'master_pid' => $identity['master_pid'],
                    'master_epoch' => $identity['master_epoch'],
                    'attempt_sequence' => $attemptSequence,
                    'mutation' => $mutation,
                    'previous_state' => '',
                    'retirement_nonce' => '',
                    'reason' => $reason,
                    'updated_timestamp' => \time(),
                ]);
                $endpoint['gateway']['registration_lifecycle'] = $next;
                $expectedFact = $next;
                $finished = true;
                return $endpoint;
            },
            $waitTimeoutSeconds,
            $deadlineMonotonic,
            static function (array $endpoint) use (&$expectedFact): ?bool {
                return $expectedFact === []
                    ? null
                    : self::endpointContainsExactFact($endpoint, $expectedFact);
            },
        );
        return $finished;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @param (callable(array<string,mixed>):?bool)|null $commitVerifier
     */
    private function update(
        string $instanceName,
        callable $callback,
        float $waitTimeoutSeconds = 5.0,
        ?float $deadlineMonotonic = null,
        ?callable $commitVerifier = null,
    ): void
    {
        $file = $this->instanceFileResolver !== null
            ? (string)($this->instanceFileResolver)($instanceName)
            : (new ServerInstanceManager())->getInstanceFile($instanceName);
        if (!\is_file($file) || \is_link($file)) {
            throw new \RuntimeException(
                'Gateway registration lifecycle endpoint is unavailable or unsafe.',
            );
        }
        $effectiveWaitTimeout = $this->effectiveWaitTimeout(
            $waitTimeoutSeconds,
            $deadlineMonotonic,
        );
        $callbackFailure = null;
        $atomicFailure = null;
        try {
            $updated = ($this->atomicUpdater)(
                $file,
                function (array $endpoint) use (
                    $instanceName,
                    $callback,
                    $deadlineMonotonic,
                    &$callbackFailure,
                ): array {
                    try {
                        $this->assertDeadline($deadlineMonotonic);
                        self::assertEndpointInstance($endpoint, $instanceName);
                        return $callback($endpoint);
                    } catch (\Throwable $throwable) {
                        $callbackFailure = $throwable;
                        throw $throwable;
                    }
                },
                $effectiveWaitTimeout,
            );
        } catch (\Throwable $throwable) {
            $updated = false;
            $atomicFailure = $throwable;
        }
        if ($updated === true) {
            // A successful atomic helper result is the durable commit result.
            // Crossing the business deadline during rename/fsync cannot turn
            // that committed fact back into an apparent failure.
            return;
        }
        if ($callbackFailure instanceof \Throwable) {
            throw $callbackFailure;
        }
        if ($commitVerifier !== null) {
            $recovered = $this->recoverPublicationOutcome(
                $file,
                $commitVerifier,
                $atomicFailure,
            );
            if ($recovered === true) {
                return;
            }
            if ($recovered === false) {
                throw new \RuntimeException(
                    'Gateway registration lifecycle publication outcome is uncertain.',
                    previous: $atomicFailure,
                );
            }
        }
        throw new \RuntimeException(
            'Gateway registration lifecycle CAS publication failed.',
            previous: $atomicFailure,
        );
    }

    private function assertDeadline(?float $deadlineMonotonic): void
    {
        if ($deadlineMonotonic === null) {
            return;
        }
        if (!\is_finite($deadlineMonotonic)
            || $this->monotonicNow() >= $deadlineMonotonic
        ) {
            throw new \RuntimeException(
                'Gateway registration lifecycle deadline was exhausted.',
            );
        }
    }

    private function effectiveWaitTimeout(
        float $waitTimeoutSeconds,
        ?float $deadlineMonotonic,
    ): float {
        if (!\is_finite($waitTimeoutSeconds) || $waitTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Gateway registration lifecycle lock wait must be positive and finite.',
            );
        }
        $waitTimeoutSeconds = \min(300.0, $waitTimeoutSeconds);
        if ($deadlineMonotonic === null) {
            return $waitTimeoutSeconds;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException(
                'Gateway registration lifecycle deadline was exhausted.',
            );
        }
        $now = $this->monotonicNow();
        if ($now >= $deadlineMonotonic) {
            throw new \RuntimeException(
                'Gateway registration lifecycle deadline was exhausted.',
            );
        }
        return \min(
            $waitTimeoutSeconds,
            $deadlineMonotonic - $now,
        );
    }

    /**
     * Re-read the exact endpoint under a new bounded recovery lock. This
     * deadline is intentionally independent of the exhausted business
     * operation: it may only classify a side effect that might already have
     * committed, never authorize a new host mutation.
     *
     * @param callable(array<string,mixed>):?bool $commitVerifier
     */
    private function recoverPublicationOutcome(
        string $file,
        callable $commitVerifier,
        ?\Throwable $atomicFailure,
    ): ?bool {
        try {
            return GatewayProjectStateFilesystem::withExclusiveLock(
                $file . '.lock',
                static function () use ($file, $commitVerifier): ?bool {
                    $encoded = GatewayProjectStateFilesystem::readOptional(
                        $file,
                        self::MAX_ATOMIC_JSON_BYTES,
                        'gateway registration lifecycle recovery endpoint',
                    );
                    if ($encoded === null) {
                        return null;
                    }
                    $endpoint = \json_decode(
                        $encoded,
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                    if (!\is_array($endpoint)) {
                        throw new \RuntimeException(
                            'Gateway registration lifecycle recovery endpoint is invalid.',
                        );
                    }
                    return $commitVerifier($endpoint);
                },
                waitTimeoutSeconds: self::RECOVERY_LOCK_WAIT_SECONDS,
            );
        } catch (\Throwable $recoveryFailure) {
            throw new \RuntimeException(
                'Gateway registration lifecycle publication outcome is uncertain: '
                    . $recoveryFailure->getMessage(),
                previous: $atomicFailure ?? $recoveryFailure,
            );
        }
    }

    /** @param array<string,mixed> $endpoint @param array<string,mixed> $expected */
    private static function endpointContainsExactFact(
        array $endpoint,
        array $expected,
    ): bool {
        $actual = self::factForEndpoint($endpoint);
        if ($actual === [] || !self::validFactShape($expected)) {
            return false;
        }
        return \hash_equals(
            GatewayClient::canonicalJson($expected),
            GatewayClient::canonicalJson($actual),
        );
    }

    private function monotonicNow(): float
    {
        $now = (float)($this->monotonicClock)();
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException(
                'Gateway registration lifecycle monotonic clock is invalid.',
            );
        }
        return $now;
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array{project_uuid:string,instance_id:string,instance_generation:int,launch_id:string,master_pid:int,master_epoch:int}
     */
    private static function endpointIdentity(array $endpoint): array
    {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $projectUuid = \strtolower(\trim((string)(
            $gateway['project_uuid'] ?? ''
        )));
        $instanceId = \trim((string)($gateway['instance_id'] ?? ''));
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        $launchId = \strtolower(\trim((string)($gateway['launch_id'] ?? '')));
        self::assertIdentity(
            $projectUuid,
            $instanceId,
            $instanceGeneration,
            $launchId,
        );
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        if ($masterPid < 1 || $masterEpoch < 1) {
            throw new \RuntimeException(
                'Gateway registration lifecycle requires a current Master fence.',
            );
        }
        return [
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceId,
            'instance_generation' => $instanceGeneration,
            'launch_id' => $launchId,
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
        ];
    }

    /** @param array<string,mixed> $fact @param array<string,mixed> $endpoint */
    private static function factMatchesEndpoint(array $fact, array $endpoint): bool
    {
        if ($fact === [] || !self::validFactShape($fact)) {
            return false;
        }
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $factMasterPid = (int)($fact['master_pid'] ?? 0);
        $factMasterEpoch = (int)($fact['master_epoch'] ?? 0);
        return \hash_equals(
                \strtolower(\trim((string)($gateway['project_uuid'] ?? ''))),
                (string)$fact['project_uuid'],
            )
            && \hash_equals(
                \trim((string)($gateway['instance_id'] ?? '')),
                (string)$fact['instance_id'],
            )
            && (int)($gateway['instance_generation'] ?? 0)
                === (int)$fact['instance_generation']
            && \hash_equals(
                \strtolower(\trim((string)($gateway['launch_id'] ?? ''))),
                (string)$fact['launch_id'],
            )
            && ($factMasterPid === 0
                || $factMasterPid === (int)($endpoint['master_pid'] ?? 0))
            && ($factMasterEpoch === 0
                || $factMasterEpoch === (int)($endpoint['master_epoch'] ?? 0));
    }

    /** @param array<string,mixed> $fact */
    private static function validFactShape(array $fact): bool
    {
        $expected = [
            'schema_version',
            'state',
            'project_uuid',
            'instance_id',
            'instance_generation',
            'launch_id',
            'master_pid',
            'master_epoch',
            'attempt_sequence',
            'mutation',
            'previous_state',
            'retirement_nonce',
            'reason',
            'updated_timestamp',
            'fact_digest',
        ];
        $actual = \array_keys($fact);
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        $digest = \strtolower(\trim((string)($fact['fact_digest'] ?? '')));
        $unsigned = $fact;
        unset($unsigned['fact_digest']);
        $state = (string)($fact['state'] ?? '');
        return $actual === $expected
            && ($fact['schema_version'] ?? null) === self::SCHEMA_VERSION
            && \in_array($state, [
                self::STATE_NEVER_ATTEMPTED,
                self::STATE_REGISTERING,
                self::STATE_REGISTERED,
                self::STATE_UNCERTAIN,
                self::STATE_RETIRING,
                self::STATE_RETIRED,
                self::STATE_UNKNOWN,
            ], true)
            && self::validProjectUuid((string)($fact['project_uuid'] ?? ''))
            && \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', (string)(
                $fact['instance_id'] ?? ''
            )) === 1
            && \is_int($fact['instance_generation'] ?? null)
            && (int)$fact['instance_generation'] > 0
            && \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $fact['launch_id'] ?? ''
            )) === 1
            && \is_int($fact['master_pid'] ?? null)
            && (int)$fact['master_pid'] >= 0
            && \is_int($fact['master_epoch'] ?? null)
            && (int)$fact['master_epoch'] >= 0
            && \is_int($fact['attempt_sequence'] ?? null)
            && (int)$fact['attempt_sequence'] >= 0
            && \is_string($fact['mutation'] ?? null)
            && \is_string($fact['previous_state'] ?? null)
            && \is_string($fact['retirement_nonce'] ?? null)
            && \is_string($fact['reason'] ?? null)
            && \is_int($fact['updated_timestamp'] ?? null)
            && (int)$fact['updated_timestamp'] > 0
            && \preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1
            && \hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            );
    }

    /** @param array<string,mixed> $fact */
    private static function seal(array $fact): array
    {
        $fact['fact_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($fact),
        );
        if (!self::validFactShape($fact)) {
            throw new \RuntimeException('Gateway registration lifecycle fact is invalid.');
        }
        return $fact;
    }

    private static function assertIdentity(
        string $projectUuid,
        string $instanceId,
        int $instanceGeneration,
        string $launchId,
    ): void {
        if (!self::validProjectUuid(\strtolower($projectUuid))
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceId) !== 1
            || $instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', \strtolower($launchId)) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Gateway registration lifecycle identity is invalid.',
            );
        }
    }

    private static function assertInstanceName(string $instanceName): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('WLS instance name is invalid.');
        }
    }

    /** @param array<string,mixed> $endpoint */
    private static function assertEndpointInstance(
        array $endpoint,
        string $instanceName,
    ): void {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $endpointInstance = \trim((string)($gateway['instance_id'] ?? ''));
        if (!\hash_equals($instanceName, $endpointInstance)) {
            throw new \RuntimeException(
                'Gateway registration lifecycle endpoint does not match the requested instance.',
            );
        }
    }

    private static function validProjectUuid(string $projectUuid): bool
    {
        return \preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            \strtolower(\trim($projectUuid)),
        ) === 1;
    }

    private static function boundedReason(string $reason): string
    {
        $reason = \trim(\str_replace("\0", '', $reason));
        $reason = \preg_replace('/[\x01-\x1f\x7f]+/', ' ', $reason) ?? '';
        $reason = \trim(\preg_replace('/\s+/', ' ', $reason) ?? '');
        return \strlen($reason) <= 512 ? $reason : \substr($reason, 0, 512);
    }
}
