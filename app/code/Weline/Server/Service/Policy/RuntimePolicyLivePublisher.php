<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;

/**
 * Compile + activate runtime policy after security-rules mutations.
 *
 * Worker attack_guard matchers (including domain_overrides crawler_block) are
 * baked into the immutable RuntimePolicyBundle. AttackDetector::updateRules alone
 * is not enough for live Worker enforcement.
 */
final class RuntimePolicyLivePublisher
{
    public function __construct(
        private readonly RuntimePolicyControlService $policyControl = new RuntimePolicyControlService(),
    ) {
    }

    /**
     * @return array{success:bool,mode:string,digest:string,message:string}
     */
    public function publishAfterSecurityRulesChange(string $instance = 'default', string $topology = 'both'): array
    {
        $instance = \trim($instance) !== '' ? \trim($instance) : 'default';
        $bundle = $this->policyControl->stage($instance, null, $topology);
        $digest = $bundle->digest;

        $gateway = new IpcControlGateway();
        $status = $gateway->getStatusBrief($instance, 1.0);
        $masterReachable = !empty($status['success']);
        if (!$masterReachable) {
            $this->policyControl->activate($instance, $digest);

            return [
                'success' => true,
                'mode' => 'offline',
                'digest' => $digest,
                'message' => 'Runtime policy staged for next start.',
            ];
        }

        $result = $gateway->command(
            $instance,
            ControlMessage::ACTION_POLICY_PUBLISH,
            '',
            ['digest' => $digest],
            6.0,
        );
        if (empty($result['success'])) {
            return [
                'success' => false,
                'mode' => 'ipc',
                'digest' => $digest,
                'message' => (string)($result['message'] ?? 'Master rejected policy publish.'),
            ];
        }

        $deadline = self::monotonicSeconds() + 18.0;
        $lastState = (string)($result['data']['policy_state'] ?? 'accepted');
        do {
            $remaining = $deadline - self::monotonicSeconds();
            if ($remaining <= 0.0) {
                break;
            }
            $statusResult = $gateway->getStatusBrief($instance, \max(0.1, \min(0.75, $remaining)));
            if (!empty($statusResult['success'])) {
                $statusData = \is_array($statusResult['data'] ?? null) ? $statusResult['data'] : [];
                $lastState = (string)($statusData['policy_state'] ?? 'unknown');
                $publishedDigest = \strtolower(\trim((string)($statusData['policy_digest'] ?? '')));
                if ($lastState === 'active'
                    && $publishedDigest !== ''
                    && \hash_equals($digest, $publishedDigest)
                ) {
                    return [
                        'success' => true,
                        'mode' => 'ipc',
                        'digest' => $publishedDigest,
                        'message' => 'Runtime policy active on all critical processes.',
                    ];
                }
                if ($lastState === 'failed') {
                    return [
                        'success' => false,
                        'mode' => 'ipc',
                        'digest' => $digest,
                        'message' => 'Runtime policy publish failed before commit.',
                    ];
                }
            }
            SchedulerSystem::yieldDelay(50);
        } while (true);

        return [
            'success' => false,
            'mode' => 'ipc',
            'digest' => $digest,
            'message' => 'Runtime policy did not become active in time (state=' . ($lastState !== '' ? $lastState : 'unknown') . ').',
        ];
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
