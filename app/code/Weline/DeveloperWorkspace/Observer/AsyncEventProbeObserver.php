<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Observer;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;

final class AsyncEventProbeObserver implements AsyncObserverInterface
{
    public const OBSERVER_NAME = 'async_event_probe_receipt';

    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool
    {
        return $eventName === ResourceChange::EVENT_NAME
            && $schemaVersion === ResourceChange::SCHEMA_VERSION;
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new NonRetryableAsyncEventException(
                'async_probe_contract_mismatch',
                __('异步验收探针只接受 ResourceChange v1'),
            );
        }
        if ($change->resourceType() !== 'async_probe') {
            return;
        }

        $payload = $change->toArray();
        if (($payload['after']['probe_marker'] ?? null) !== $change->resourceId()) {
            throw new NonRetryableAsyncEventException(
                'async_probe_marker_mismatch',
                __('异步验收探针标识不匹配'),
            );
        }
    }
}
