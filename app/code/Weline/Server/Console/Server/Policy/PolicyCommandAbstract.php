<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Policy;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Policy\RuntimePolicyControlService;
use Weline\Server\Service\ServerInstanceManager;

abstract class PolicyCommandAbstract extends CommandAbstract
{
    protected function policyService(): RuntimePolicyControlService
    {
        return new RuntimePolicyControlService();
    }

    protected function instanceName(array $args): string
    {
        $positional = [];
        foreach ($args as $key => $value) {
            if (\is_int($key) && !\str_starts_with((string)$value, '-')) {
                $positional[] = (string)$value;
            }
        }
        \array_shift($positional);
        $instance = (string)($args['instance'] ?? $args['n'] ?? ($positional[0] ?? 'default'));
        return \trim($instance) !== '' ? \trim($instance) : 'default';
    }

    protected function topology(array $args): string
    {
        $topology = \strtolower(\trim((string)($args['topology'] ?? 'both')));
        if (!\in_array($topology, ['both', 'direct', 'dispatcher'], true)) {
            throw new \InvalidArgumentException((string)__('topology 必须是 both、direct 或 dispatcher。'));
        }
        return $topology;
    }

    protected function digest(array $args): ?string
    {
        $digest = \strtolower(\trim((string)($args['digest'] ?? '')));
        return $digest !== '' ? $digest : null;
    }

    protected function json(array $args, array $result): bool
    {
        if (!isset($args['json'])) {
            return false;
        }
        echo \json_encode(
            $result,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ) . \PHP_EOL;
        return true;
    }

    /**
     * Policy commands expose operational facts, never route secrets. The
     * descriptor list contains the concrete backend prefixes, so command
     * output returns only counts plus a one-way route summary.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    protected function policyBundleSummary(array $bundle): array
    {
        $metadata = \is_array($bundle['metadata'] ?? null) ? $bundle['metadata'] : [];
        $backendPrefix = \trim((string)($metadata['backend_prefix'] ?? ''), '/');
        $restBackendPrefix = \trim((string)($metadata['rest_backend_prefix'] ?? ''), '/');
        unset($metadata['backend_prefix'], $metadata['rest_backend_prefix']);
        $metadata['backend_prefix_configured'] = $backendPrefix !== '';
        $metadata['rest_backend_prefix_configured'] = $restBackendPrefix !== '';
        $metadata['backend_routes_digest'] = \hash(
            'sha256',
            $backendPrefix . "\0" . $restBackendPrefix,
        );

        return [
            'format' => (int)($bundle['format'] ?? 0),
            'version' => (string)($bundle['version'] ?? ''),
            'digest' => (string)($bundle['digest'] ?? ''),
            'generated_at' => (int)($bundle['generated_at'] ?? 0),
            'topology' => (string)($bundle['topology'] ?? ''),
            'descriptor_count' => \count((array)($bundle['descriptors'] ?? [])),
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function publishToRuntime(string $instance, string $digest, bool $rollback = false): array
    {
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $info = $manager->getInstanceInfo($instance);
        if ($info !== null && $info->isMasterRunning()) {
            $action = $rollback ? ControlMessage::ACTION_POLICY_ROLLBACK : ControlMessage::ACTION_POLICY_PUBLISH;
            $gateway = new IpcControlGateway();
            $result = $gateway->command(
                $instance,
                $action,
                '',
                ['digest' => $digest],
                6.0,
            );
            if (empty($result['success'])) {
                throw new \RuntimeException((string)($result['message'] ?? __('Master 拒绝了策略发布。')));
            }

            // The command ACK only proves that Master accepted the transition.
            // Do not report a successful publish until every critical
            // participant has ACKed COMMIT and Master exposes the target digest
            // as its active runtime policy.
            $deadline = \microtime(true) + 18.0;
            $lastState = (string)($result['data']['policy_state'] ?? 'accepted');
            $lastError = '';
            do {
                $remaining = $deadline - \microtime(true);
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
                            'mode' => 'ipc',
                            'status' => 'completed',
                            'policy_state' => 'active',
                            'policy_digest' => $publishedDigest,
                            'message' => (string)__('所有关键进程已完成运行时策略提交。'),
                        ];
                    }
                    if ($lastState === 'failed') {
                        $fullStatus = $gateway->getStatus($instance, \max(0.1, \min(1.0, $remaining)));
                        $fullData = \is_array($fullStatus['data'] ?? null) ? $fullStatus['data'] : [];
                        $lastError = \trim((string)($fullData['policy_error'] ?? ''));
                        throw new \RuntimeException(
                            $lastError !== ''
                                ? $lastError
                                : (string)__('运行时策略在所有关键进程提交前失败。')
                        );
                    }
                }
                SchedulerSystem::yieldDelay(50);
            } while (true);

            throw new \RuntimeException(
                (string)__('运行时策略在控制时限内未进入 active 状态（state=%{1}）。', [
                    $lastState !== '' ? $lastState : 'unknown',
                ])
            );
        }

        $state = $this->policyService()->activate($instance, $digest);
        return [
            'mode' => 'offline',
            'status' => 'active_on_next_start',
            'state' => $state,
        ];
    }
}
