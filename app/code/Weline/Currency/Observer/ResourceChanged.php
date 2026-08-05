<?php

declare(strict_types=1);

namespace Weline\Currency\Observer;

use Weline\Currency\Data\CurrencyData;
use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;

final class ResourceChanged implements AsyncObserverInterface
{
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
                'resource_change_contract_mismatch',
                __('Currency ResourceChange Observer 只接受 v1 契约'),
            );
        }
        if (!$this->affectsCurrency($change)) {
            return;
        }
        CurrencyData::clearCache();
    }

    private function affectsCurrency(ResourceChange $change): bool
    {
        if ($change->resourceType() === 'currency') {
            return true;
        }
        if ($change->resourceType() !== 'system_config') {
            return false;
        }
        $after = $change->toArray()['after'] ?? null;
        return is_array($after) && ($after['module'] ?? '') === 'Weline_Currency';
    }
}
