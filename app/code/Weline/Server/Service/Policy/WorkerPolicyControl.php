<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Security\ConnectionAcceptGatePool;
use Weline\Server\Security\GlobalRateLimiter;
use Weline\Server\Security\WorkerPolicyKernel;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;

/** Worker-side two-phase policy control handler. */
final class WorkerPolicyControl
{
    public static function handle(array $message, string $topology, string $instanceName = ''): ?string
    {
        $type = (string)($message['type'] ?? '');
        $digest = \strtolower(\trim((string)($message['digest'] ?? '')));
        try {
            switch ($type) {
                case ControlMessage::TYPE_SECURITY_UNBLOCK:
                    WorkerPolicyKernel::instance()->clearSecurityBans(
                        isset($message['ip']) ? (string)$message['ip'] : null,
                        !empty($message['clear_all']),
                    );
                    return null;

                case ControlMessage::TYPE_POLICY_STATE_DELTA:
                    GlobalRateLimiter::applyBanDelta(
                        (string)($message['instance'] ?? ''),
                        (string)($message['ip'] ?? ''),
                        (int)($message['expires_at'] ?? 0),
                        $instanceName,
                    );
                    return null;

                case ControlMessage::TYPE_POLICY_PREPARE:
                    $bundle = $message['bundle'] ?? null;
                    if (!\is_array($bundle)) {
                        throw new \InvalidArgumentException('POLICY_PREPARE bundle is missing.');
                    }
                    $candidate = RuntimePolicyBundle::fromArray($bundle);
                    if (!$candidate->supportsTopology($topology)) {
                        throw new \RuntimeException("Policy bundle does not support Worker topology {$topology}.");
                    }
                    $connectionGates = ConnectionAcceptGatePool::instanceOrNull();
                    try {
                        $connectionGates?->prepareBundle($candidate);
                        $staged = RoutingPolicyRegistry::beginPolicyTransition($candidate);
                    } catch (\Throwable $throwable) {
                        $connectionGates?->abortPreparedBundle($candidate->digest);
                        throw $throwable;
                    }
                    if ($digest !== '' && !\hash_equals($digest, $staged)) {
                        $connectionGates?->abortPreparedBundle($candidate->digest);
                        throw new \RuntimeException('POLICY_PREPARE digest mismatch.');
                    }
                    // PREPARED_ACK is emitted by pollAfterApplicationDrain().
                    // Returning it here would allow a still-running old-policy
                    // Fiber to overlap the new digest on another Worker.
                    return null;

                case ControlMessage::TYPE_POLICY_ACTIVATE:
                    if ($digest === '' || !RoutingPolicyRegistry::activatePreparedPolicy($digest)) {
                        throw new \RuntimeException('POLICY_ACTIVATE has no matching prepared digest.');
                    }
                    WorkerPolicyKernel::instance()->synchronizeActivatedBundle($digest);
                    $connectionGates = ConnectionAcceptGatePool::instanceOrNull();
                    if ($connectionGates !== null && !$connectionGates->activatePreparedBundle($digest)) {
                        throw new \RuntimeException('POLICY_ACTIVATE connection gate was not prepared.');
                    }
                    return ControlMessage::policyActivatedAck($digest);

                case ControlMessage::TYPE_POLICY_COMMIT:
                    if ($digest === '' || !RoutingPolicyRegistry::commitPolicyTransition($digest)) {
                        throw new \RuntimeException('POLICY_COMMIT has no matching activated digest.');
                    }
                    return ControlMessage::policyCommittedAck($digest);

                case ControlMessage::TYPE_POLICY_ROLLBACK:
                    if (!empty($message['abort'])) {
                        if (!RoutingPolicyRegistry::abortPolicyTransition($digest !== '' ? $digest : null)) {
                            throw new \RuntimeException('POLICY_ROLLBACK could not safely restore the previous policy.');
                        }
                    } elseif ($digest === '' || !RoutingPolicyRegistry::activatePreparedPolicy($digest)) {
                        throw new \RuntimeException('POLICY_ROLLBACK has no matching prepared digest.');
                    }
                    $connectionGates = ConnectionAcceptGatePool::instanceOrNull();
                    $activeBundle = RoutingPolicyRegistry::getActiveBundle();
                    if ($connectionGates !== null) {
                        if (!$activeBundle instanceof RuntimePolicyBundle) {
                            throw new \RuntimeException('POLICY_ROLLBACK active connection bundle is unavailable.');
                        }
                        $connectionGates->activateBundle($activeBundle);
                    }
                    $activeDigest = RoutingPolicyRegistry::getActiveDigest();
                    WorkerPolicyKernel::instance()->synchronizeActivatedBundle($activeDigest);
                    return ControlMessage::policyRollbackAck($activeDigest);
            }
        } catch (\Throwable $throwable) {
            return match ($type) {
                ControlMessage::TYPE_POLICY_PREPARE => ControlMessage::policyPreparedAck($digest, false, $throwable->getMessage()),
                ControlMessage::TYPE_POLICY_ACTIVATE => ControlMessage::policyActivatedAck($digest, false, $throwable->getMessage()),
                ControlMessage::TYPE_POLICY_COMMIT => ControlMessage::policyCommittedAck($digest, false, $throwable->getMessage()),
                ControlMessage::TYPE_POLICY_ROLLBACK => ControlMessage::policyRollbackAck($digest, false, $throwable->getMessage()),
                default => null,
            };
        }
        return null;
    }

    /**
     * Called from the transport loop after it has stopped accepts/request
     * dispatch and progressed existing Fibers. A PREPARED_ACK is impossible
     * until both counters prove the old application generation is idle.
     */
    public static function pollAfterApplicationDrain(
        int $activeRequests,
        int $activeFibers = 0,
        int $pendingResponses = 0,
    ): ?string {
        $digest = RoutingPolicyRegistry::takePreparedDigestIfDrained(
            $activeRequests,
            $activeFibers,
            $pendingResponses,
        );
        if ($digest === null) {
            return null;
        }

        $connectionGates = ConnectionAcceptGatePool::instanceOrNull();
        if ($connectionGates !== null && !$connectionGates->hasPreparedBundle($digest)) {
            return ControlMessage::policyPreparedAck(
                $digest,
                false,
                'Connection accept gate was not built during POLICY_PREPARE.'
            );
        }

        return ControlMessage::policyPreparedAck($digest, true, '', [
            'worker_policy_kernel',
            'policy_application_drain',
            'policy_accept_gate',
            'shared_atomic_state',
            'token_lease',
            'cache_epoch',
        ]);
    }

    public static function isApplicationGateOpen(): bool
    {
        return RoutingPolicyRegistry::isApplicationGateOpen();
    }
}
