<?php

declare(strict_types=1);

namespace Weline\Dropship\Observer;

use Weline\Dropship\Model\DropshipOrderLine;
use Weline\Dropship\Service\DropshipOutboxService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class OrderCancelledObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $this->admit((array)$event->getData());
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function admit(array $data): void
    {
        $orderUuid = (string)($data['order_uuid'] ?? '');
        if ($orderUuid === '') {
            return;
        }
        /** @var DropshipOrderLine $model */
        $model = ObjectManager::getInstance(DropshipOrderLine::class);
        $rows = $model->clear()->where(DropshipOrderLine::schema_fields_ORDER_UUID, $orderUuid)->select()->fetchArray();
        $providers = [];
        foreach ((array)$rows as $row) {
            $providers[(string)($row['provider_code'] ?? '')] = true;
        }
        /** @var DropshipOutboxService $outbox */
        $outbox = ObjectManager::getInstance(DropshipOutboxService::class);
        foreach (array_keys($providers) as $code) {
            if ($code === '') {
                continue;
            }
            $outbox->admitCancel($orderUuid, $code);
        }
    }
}
