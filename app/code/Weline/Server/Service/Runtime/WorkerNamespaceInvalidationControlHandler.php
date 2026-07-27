<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlMessage;

/** Shared thin adapter used by all three Worker transports. */
final class WorkerNamespaceInvalidationControlHandler
{
    public static function handle(
        array $message,
        ?ChildControlClientInterface $client,
        string $role,
        int $workerId,
    ): void {
        if ($client === null) {
            return;
        }
        $source = [
            'client_id' => 0,
            'role' => $role,
            'worker_id' => $workerId,
            'slot_id' => $role . '#' . \max(1, $workerId),
            'lease_id' => '',
            'slot_generation' => 0,
            'pid' => \getmypid(),
        ];
        if (\method_exists($client, 'runtimeSourceIdentity')) {
            $reported = $client->runtimeSourceIdentity();
            if (\is_array($reported)) {
                $source = \array_replace($source, $reported);
            }
        }

        try {
            $result = ObjectManager::getInstance(WorkerNamespaceGenerationApplier::class)->apply($message);
        } catch (\Throwable) {
            $result = [
                'operation_id' => (string)($message['operation_id'] ?? ''),
                'success' => false,
                'applied' => false,
                'authority_clock' => 0,
                'generations' => [],
                'error_code' => 'applier_unavailable',
                'error' => (string)__('缓存命名空间代际应用器不可用。'),
            ];
        }
        $client->send(ControlMessage::cacheNamespaceInvalidationAckV1($result, $source));
    }
}
