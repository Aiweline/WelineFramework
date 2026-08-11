<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * One retirement transaction shared by ordinary Stop and failed Start.
 *
 * The local CAS fence is always claimed before reading host state. A caller
 * may restore the prior local state when it intends to keep WLS running
 * (ordinary non-force Stop), or retain RETIRING when local startup is already
 * doomed and the host lease must be allowed to expire fail-closed.
 */
final class GatewayRegistrationRetirementCoordinator
{
    private const FAILURE_COMPENSATION_LOCK_WAIT_SECONDS = 0.25;

    private const FAILURE_COMPENSATION_DEADLINE_SECONDS = 1.0;

    private const DEFAULT_UNREGISTER_TIMEOUT_SECONDS = 180.0;

    private const DEFAULT_OPERATION_MARGIN_SECONDS = 15.0;

    private readonly GatewayHostManager $host;

    private readonly GatewayRegistrationLifecycle $lifecycle;

    /** @var \Closure():float */
    private readonly \Closure $monotonicClock;

    public function __construct(
        ?GatewayHostManager $host = null,
        ?GatewayRegistrationLifecycle $lifecycle = null,
        private readonly ?\Closure $statusResolver = null,
        private readonly ?\Closure $receiptValidator = null,
        private readonly ?\Closure $drainResolver = null,
        private readonly ?\Closure $staleDrainResolver = null,
        private readonly string $currentHostBootId = '',
        ?\Closure $monotonicClock = null,
    ) {
        $this->host = $host ?? new GatewayHostManager();
        $this->lifecycle = $lifecycle ?? new GatewayRegistrationLifecycle();
        $this->monotonicClock = $monotonicClock
            ?? static fn (): float => \hrtime(true) / 1_000_000_000;
    }

    /**
     * @return array{
     *   action:string,
     *   reason:string,
     *   status_authenticated:bool,
     *   previous_state:string,
     *   drain:array<string,mixed>
     * }
     */
    public function retire(
        string $instanceName,
        int $drainSeconds = 300,
        bool $waitForConnections = true,
        bool $restoreOnFailure = true,
        ?float $deadlineMonotonic = null,
    ): array {
        $deadlineMonotonic = $this->operationDeadline(
            $deadlineMonotonic,
            $drainSeconds,
            $waitForConnections,
        );
        $retirement = [];
        $hostRetirementAuthoritative = false;
        try {
            $retirement = $this->lifecycle->claimRetirement(
                $instanceName,
                $this->operationLockWait($deadlineMonotonic, 5.0),
                $deadlineMonotonic,
            );
            $endpoint = \is_array($retirement['endpoint'] ?? null)
                ? $retirement['endpoint']
                : [];
            $status = $this->status($deadlineMonotonic);
            $decision = GatewayStopRegistrationPolicy::decide(
                $endpoint,
                $status,
                $this->currentHostBootId,
            );
            $action = (string)($decision['action'] ?? 'block');
            if ($action === GatewayStopRegistrationPolicy::ACTION_BLOCK) {
                throw new \RuntimeException((string)(
                    $decision['reason']
                    ?? 'Gateway registration state is not safe for retirement.'
                ));
            }
            if (!\in_array($action, [
                GatewayStopRegistrationPolicy::ACTION_LOCAL_ONLY,
                GatewayStopRegistrationPolicy::ACTION_DRAIN,
            ], true)) {
                throw new \RuntimeException(
                    'Gateway retirement policy returned an unknown action.',
                );
            }

            $drain = [];
            $reason = (string)($decision['reason'] ?? 'gateway_state_absent');
            if ($action === GatewayStopRegistrationPolicy::ACTION_LOCAL_ONLY) {
                // Authenticated exact-launch absence is terminal host authority
                // even though this invocation did not issue a mutation.
                $hostRetirementAuthoritative = true;
            }
            if ($action === GatewayStopRegistrationPolicy::ACTION_DRAIN) {
                $seconds = \max(1, \min(300, $drainSeconds));
                $hostInstanceStatus = \strtoupper(\trim((string)(
                    $decision['host_instance_status'] ?? ''
                )));
                if (\in_array($hostInstanceStatus, ['STALE', 'DRAINING'], true)) {
                    // A STALE/DRAINING launch no longer has a fresh lease receipt
                    // by definition. The host path must instead authenticate a
                    // fresh own-status snapshot with the current project
                    // credential and submit the same exact launch fence. Its
                    // irreversible drain is sufficient authority for local
                    // retirement; the Controller owns the terminal removal.
                    $drain = $this->staleDrain(
                        $instanceName,
                        $seconds,
                        $waitForConnections,
                        $deadlineMonotonic,
                    );
                    $hostRetirementAuthoritative =
                        ($drain['stale_drain_committed'] ?? false) === true
                        && ($drain['irreversible'] ?? false) === true
                        && \hash_equals(
                            'authenticated_own_status',
                            (string)($drain['retirement_authority'] ?? ''),
                        );
                    if (!$hostRetirementAuthoritative
                        || ($waitForConnections
                            && ($drain['unregistered'] ?? false) !== true)
                    ) {
                        throw new \RuntimeException(
                            'Gateway did not return an irreversible authenticated stale drain.',
                        );
                    }
                    $reason = 'gateway_stale_drain_committed';
                } else {
                    $this->validateReceipt($instanceName, $deadlineMonotonic);
                    $drain = $this->drain(
                        $instanceName,
                        $seconds,
                        $waitForConnections,
                        $deadlineMonotonic,
                    );
                    if (($drain['unregistered'] ?? false) === true) {
                        $hostRetirementAuthoritative = true;
                    } else {
                        if (($drain['already_removed'] ?? false) !== true) {
                            throw new \RuntimeException(
                                'Gateway did not return a committed unregister result.',
                            );
                        }
                        // `already_removed` can originate from an idempotent error
                        // response without a signed publication body. Require a
                        // fresh authenticated own-status absence before accepting
                        // it as authority to tear down the local backend.
                        $confirmation = GatewayStopRegistrationPolicy::decide(
                            $endpoint,
                            $this->status($deadlineMonotonic),
                            $this->currentHostBootId,
                        );
                        if (($confirmation['action'] ?? '')
                            !== GatewayStopRegistrationPolicy::ACTION_LOCAL_ONLY
                        ) {
                            throw new \RuntimeException(
                                'Gateway already-removed result lacks authenticated launch absence.',
                            );
                        }
                        $hostRetirementAuthoritative = true;
                    }
                    $reason = 'gateway_unregister_committed';
                }
            }

            // The host fact is already terminal/irreversible. Local completion
            // is failure compensation and therefore receives a fresh, tiny
            // budget; an expired host deadline must not make us cancel and
            // resurrect a route that the host has already retired.
            $completionDeadline = $this->failureCompensationDeadline();
            if (!$this->lifecycle->completeRetirement(
                $instanceName,
                (string)($retirement['nonce'] ?? ''),
                $reason,
                self::FAILURE_COMPENSATION_LOCK_WAIT_SECONDS,
                $completionDeadline,
            )) {
                throw new \RuntimeException(
                    'Gateway host retirement completed but the local retirement fence changed.',
                );
            }

            return [
                'action' => $action,
                'reason' => $reason,
                'status_authenticated' => ($decision['status_authenticated'] ?? false) === true,
                'previous_state' => (string)($retirement['previous_state'] ?? ''),
                'drain' => $drain,
            ];
        } catch (\Throwable $throwable) {
            if ($hostRetirementAuthoritative) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        1024,
                        'Gateway retirement local completion failed.',
                    ) . ' Host retirement is already authoritative; '
                    . 'the local fence remains fail-closed and was not restored.',
                    0,
                    $throwable,
                );
            }
            if (!$restoreOnFailure) {
                throw $throwable;
            }
            $nonce = \strtolower(\trim((string)($retirement['nonce'] ?? '')));
            if ($nonce === '') {
                throw $throwable;
            }
            try {
                if (!$this->lifecycle->cancelRetirement(
                    $instanceName,
                    $nonce,
                    self::FAILURE_COMPENSATION_LOCK_WAIT_SECONDS,
                    $this->failureCompensationDeadline(),
                )) {
                    throw new \RuntimeException(
                        'Gateway retirement fence changed before it could be restored.',
                    );
                }
            } catch (\Throwable $restoreFailure) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        1024,
                        'Gateway retirement failed.',
                    ) . ' Local retirement restoration also failed: '
                    . GatewayBoundedText::singleLine(
                        $restoreFailure->getMessage(),
                        512,
                        'state restoration failed',
                    ),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /** @return array<string,mixed> */
    private function status(float $deadlineMonotonic): array
    {
        $this->assertOperationDeadline($deadlineMonotonic);
        $status = $this->statusResolver !== null
            ? ($this->statusResolver)($deadlineMonotonic)
            : $this->host->status(1.0, $deadlineMonotonic);
        $this->assertOperationDeadline($deadlineMonotonic);
        return \is_array($status) ? $status : [];
    }

    private function validateReceipt(
        string $instanceName,
        float $deadlineMonotonic,
    ): void {
        $this->assertOperationDeadline($deadlineMonotonic);
        if ($this->receiptValidator !== null) {
            ($this->receiptValidator)($instanceName, $deadlineMonotonic);
            $this->assertOperationDeadline($deadlineMonotonic);
            return;
        }
        $this->host->validatedLeaseReceiptForInstance(
            $instanceName,
            $deadlineMonotonic,
        );
        $this->assertOperationDeadline($deadlineMonotonic);
    }

    /** @return array<string,mixed> */
    private function drain(
        string $instanceName,
        int $seconds,
        bool $waitForConnections,
        float $deadlineMonotonic,
    ): array {
        $this->assertOperationDeadline($deadlineMonotonic);
        $drain = $this->drainResolver !== null
            ? ($this->drainResolver)(
                $instanceName,
                $seconds,
                $waitForConnections,
                $deadlineMonotonic,
            )
            : $this->host->drain(
                $instanceName,
                $seconds,
                $waitForConnections,
                $deadlineMonotonic,
            );
        if (!\is_array($drain)) {
            throw new \RuntimeException('Gateway drain returned an invalid result.');
        }
        // Do not reject a returned terminal mutation result merely because
        // the clock crossed the caller deadline while the host committed it.
        // The caller classifies irreversible authority before deciding whether
        // local cancellation is still legal.
        return $drain;
    }

    /** @return array<string,mixed> */
    private function staleDrain(
        string $instanceName,
        int $seconds,
        bool $waitForConnections,
        float $deadlineMonotonic,
    ): array {
        $this->assertOperationDeadline($deadlineMonotonic);
        $drain = $this->staleDrainResolver !== null
            ? ($this->staleDrainResolver)(
                $instanceName,
                $seconds,
                $waitForConnections,
                $deadlineMonotonic,
            )
            : $this->host->drainStaleRegistration(
                $instanceName,
                $seconds,
                $waitForConnections,
                $deadlineMonotonic,
            );
        if (!\is_array($drain)) {
            throw new \RuntimeException('Gateway stale drain returned an invalid result.');
        }
        // As above, a returned irreversible result remains authoritative even
        // when its acknowledgement arrives at the deadline boundary.
        return $drain;
    }

    private function operationDeadline(
        ?float $deadlineMonotonic,
        int $drainSeconds,
        bool $waitForConnections,
    ): float {
        $now = $this->monotonicNow();
        if ($deadlineMonotonic !== null) {
            if (!\is_finite($deadlineMonotonic) || $now >= $deadlineMonotonic) {
                throw new \RuntimeException(
                    'Gateway retirement operation deadline was exhausted.',
                );
            }
            return $deadlineMonotonic;
        }
        $seconds = (float)\max(1, \min(300, $drainSeconds));
        $budget = \min(90.0, $seconds)
            + 90.0
            + ($waitForConnections ? $seconds : 0.0)
            + ($waitForConnections
                ? self::DEFAULT_UNREGISTER_TIMEOUT_SECONDS
                : 5.0)
            + self::DEFAULT_OPERATION_MARGIN_SECONDS;
        return $now + $budget;
    }

    private function operationLockWait(
        float $deadlineMonotonic,
        float $maximumSeconds,
    ): float {
        $remaining = $this->remainingOperationSeconds($deadlineMonotonic);
        return \min($maximumSeconds, $remaining);
    }

    private function failureCompensationDeadline(): float
    {
        return $this->monotonicNow()
            + self::FAILURE_COMPENSATION_DEADLINE_SECONDS;
    }

    private function assertOperationDeadline(float $deadlineMonotonic): void
    {
        $this->remainingOperationSeconds($deadlineMonotonic);
    }

    private function remainingOperationSeconds(float $deadlineMonotonic): float
    {
        $now = $this->monotonicNow();
        if (!\is_finite($deadlineMonotonic) || $now >= $deadlineMonotonic) {
            throw new \RuntimeException(
                'Gateway retirement operation deadline was exhausted.',
            );
        }
        return $deadlineMonotonic - $now;
    }

    private function monotonicNow(): float
    {
        $now = (float)($this->monotonicClock)();
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException(
                'Gateway retirement monotonic clock is invalid.',
            );
        }
        return $now;
    }
}
