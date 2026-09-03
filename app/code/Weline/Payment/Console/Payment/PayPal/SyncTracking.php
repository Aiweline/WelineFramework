<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\PayPal;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\PayPalShipmentTrackingSyncService;

class SyncTracking extends CommandAbstract
{
    public function tip(): string
    {
        return 'Sync local shipment tracking to PayPal capture (Add Tracking API)';
    }

    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $orderUuid = trim((string) ($this->optionValue($args, 'order-uuid') ?? ''));
        $tracking = trim((string) ($this->optionValue($args, 'tracking-number') ?? ''));
        $carrier = trim((string) ($this->optionValue($args, 'carrier') ?? ''));
        $status = trim((string) ($this->optionValue($args, 'status') ?? 'SHIPPED'));

        if ($orderUuid === '' || $tracking === '') {
            $printing->error('需要 --order-uuid= 与 --tracking-number=');

            return '';
        }

        $result = ObjectManager::getInstance(PayPalShipmentTrackingSyncService::class)
            ->syncForOrder($orderUuid, $tracking, $carrier, $status);

        $printing->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

        return empty($result['ok']) ? 'FAIL' : 'OK';
    }
}
