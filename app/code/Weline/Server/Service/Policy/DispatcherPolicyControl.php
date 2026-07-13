<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\Runtime\Policy\PolicyStage;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Security\ConnectionAcceptGatePool;
use Weline\Server\Security\GlobalRateLimiter;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;

/** Dispatcher-side participant for immutable policy publication and L4 data. */
final class DispatcherPolicyControl
{
    private static string $instanceName = '';

    public static function boot(string $instanceName): string
    {
        self::$instanceName = $instanceName;
        try {
            $bundle = (new RuntimePolicyStore())->active($instanceName);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Dispatcher could not load the published active runtime policy bundle.',
                0,
                $throwable,
            );
        }
        if (!$bundle instanceof RuntimePolicyBundle) {
            throw new \RuntimeException(
                'Dispatcher startup requires a published active runtime policy bundle.'
            );
        }
        self::assertSupported($bundle);
        RoutingPolicyRegistry::prepare($bundle);
        if (!RoutingPolicyRegistry::activate($bundle->digest)) {
            throw new \RuntimeException('Dispatcher could not activate its startup policy bundle.');
        }
        return $bundle->digest;
    }

    public static function handle(array $message): ?string
    {
        $type = (string)($message['type'] ?? '');
        $digest = \strtolower(\trim((string)($message['digest'] ?? '')));
        try {
            switch ($type) {
                case ControlMessage::TYPE_POLICY_STATE_DELTA:
                    GlobalRateLimiter::applyBanDelta(
                        (string)($message['instance'] ?? ''),
                        (string)($message['ip'] ?? ''),
                        (int)($message['expires_at'] ?? 0),
                        self::$instanceName,
                    );
                    return null;

                case ControlMessage::TYPE_POLICY_PREPARE:
                    $bundle = $message['bundle'] ?? null;
                    if (!\is_array($bundle)) {
                        throw new \InvalidArgumentException('POLICY_PREPARE bundle is missing.');
                    }
                    $candidate = RuntimePolicyBundle::fromArray($bundle);
                    self::assertSupported($candidate);
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
                    // Dispatcher owns no application execution. Closing its
                    // public accept gate is therefore a complete L4 drain
                    // proof; Worker participants independently drain requests.
                    $prepared = RoutingPolicyRegistry::takePreparedDigestIfDrained(0, 0);
                    if ($prepared === null) {
                        throw new \RuntimeException('Dispatcher policy accept gate could not enter PREPARED state.');
                    }
                    if ($connectionGates !== null && !$connectionGates->hasPreparedBundle($staged)) {
                        throw new \RuntimeException('Dispatcher connection gate was not built during POLICY_PREPARE.');
                    }
                    return ControlMessage::policyPreparedAck($staged, true, '', [
                        'dispatcher_l4_policy',
                        'ip_cidr_guard',
                        'policy_accept_gate',
                        'topology:dispatcher',
                    ]);

                case ControlMessage::TYPE_POLICY_ACTIVATE:
                    if ($digest === '' || !RoutingPolicyRegistry::activatePreparedPolicy($digest)) {
                        throw new \RuntimeException('POLICY_ACTIVATE has no matching prepared Dispatcher digest.');
                    }
                    $connectionGates = ConnectionAcceptGatePool::instanceOrNull();
                    if ($connectionGates !== null && !$connectionGates->activatePreparedBundle($digest)) {
                        throw new \RuntimeException('Dispatcher POLICY_ACTIVATE connection gate was not prepared.');
                    }
                    return ControlMessage::policyActivatedAck($digest);

                case ControlMessage::TYPE_POLICY_COMMIT:
                    if ($digest === '' || !RoutingPolicyRegistry::commitPolicyTransition($digest)) {
                        throw new \RuntimeException('POLICY_COMMIT has no matching activated Dispatcher digest.');
                    }
                    return ControlMessage::policyCommittedAck($digest);

                case ControlMessage::TYPE_POLICY_ROLLBACK:
                    if (!empty($message['abort'])) {
                        if (!RoutingPolicyRegistry::abortPolicyTransition($digest !== '' ? $digest : null)) {
                            throw new \RuntimeException('POLICY_ROLLBACK could not restore the previous Dispatcher policy.');
                        }
                    } elseif ($digest === '' || !RoutingPolicyRegistry::activatePreparedPolicy($digest)) {
                        throw new \RuntimeException('POLICY_ROLLBACK has no matching prepared Dispatcher digest.');
                    }
                    $connectionGates = ConnectionAcceptGatePool::instanceOrNull();
                    $activeBundle = RoutingPolicyRegistry::getActiveBundle();
                    if ($connectionGates !== null) {
                        if (!$activeBundle instanceof RuntimePolicyBundle) {
                            throw new \RuntimeException('Dispatcher rollback connection bundle is unavailable.');
                        }
                        $connectionGates->activateBundle($activeBundle);
                    }
                    return ControlMessage::policyRollbackAck(RoutingPolicyRegistry::getActiveDigest());
            }
        } catch (\Throwable $throwable) {
            return match ($type) {
                ControlMessage::TYPE_POLICY_PREPARE => ControlMessage::policyPreparedAck(
                    $digest,
                    false,
                    $throwable->getMessage()
                ),
                ControlMessage::TYPE_POLICY_ACTIVATE => ControlMessage::policyActivatedAck(
                    $digest,
                    false,
                    $throwable->getMessage()
                ),
                ControlMessage::TYPE_POLICY_COMMIT => ControlMessage::policyCommittedAck(
                    $digest,
                    false,
                    $throwable->getMessage()
                ),
                ControlMessage::TYPE_POLICY_ROLLBACK => ControlMessage::policyRollbackAck(
                    $digest,
                    false,
                    $throwable->getMessage()
                ),
                default => null,
            };
        }
        return null;
    }

    public static function activeDigest(): string
    {
        return RoutingPolicyRegistry::getActiveDigest();
    }

    /**
     * Dispatcher READY may report only the immutable bundle activated in this
     * process. A compiled/staged candidate is never a readiness fact.
     *
     * @return array{policy_digest:string}
     */
    public static function readinessSnapshot(): array
    {
        $digest = \strtolower(\trim(self::activeDigest()));
        if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException(
                'Dispatcher READY requires an activated runtime policy digest.'
            );
        }

        return ['policy_digest' => $digest];
    }

    public static function canAcceptConnections(): bool
    {
        return RoutingPolicyRegistry::isApplicationGateOpen();
    }

    private static function assertSupported(RuntimePolicyBundle $bundle): void
    {
        (new RuntimePolicyValidator())->assertValid($bundle, 'dispatcher');
        foreach ($bundle->descriptors as $descriptor) {
            if ($descriptor->stage !== PolicyStage::CONNECTION || !$descriptor->critical) {
                continue;
            }
            $type = (string)($descriptor->matcher['type'] ?? '');
            if (!\in_array($type, ['ip_policy'], true)) {
                throw new \RuntimeException(
                    "Dispatcher does not support critical connection policy {$descriptor->id} ({$type})."
                );
            }
        }
    }

}
