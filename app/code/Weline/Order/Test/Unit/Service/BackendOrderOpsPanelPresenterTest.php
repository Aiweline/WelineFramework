<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\BackendOrderOpsPanelPresenter;

final class BackendOrderOpsPanelPresenterTest extends TestCase
{
    public function testFiltersCandidatesByOrderId(): void
    {
        $commands = new class {
            /** @return list<array<string,mixed>> */
            public function shipmentCandidates(int $limit = 50): array
            {
                return [
                    ['order_id' => 59, 'fulfillment_unit_uuid' => 'u-59'],
                    ['order_id' => 12, 'fulfillment_unit_uuid' => 'u-12'],
                ];
            }

            /** @return list<array<string,mixed>> */
            public function shipmentProgress(int $limit = 50): array
            {
                return [
                    ['order_id' => 59, 'qty_minor' => 1],
                ];
            }

            /** @return list<array<string,mixed>> */
            public function refundCandidates(int $limit = 50): array
            {
                return [
                    ['order_id' => 59, 'item_uuid' => 'i-59'],
                    ['order_id' => 7, 'item_uuid' => 'i-7'],
                ];
            }

            /** @return list<array<string,mixed>> */
            public function refundCases(int $limit = 50): array
            {
                return [];
            }
        };

        $presenter = new BackendOrderOpsPanelPresenter($commands);
        $ship = $presenter->shipmentPanel(59);
        self::assertCount(1, $ship['candidates']);
        self::assertSame('u-59', $ship['candidates'][0]['fulfillment_unit_uuid']);
        self::assertCount(1, $ship['progress']);

        $refund = $presenter->refundPanel(59);
        self::assertCount(1, $refund['candidates']);
        self::assertSame('i-59', $refund['candidates'][0]['item_uuid']);
    }
}
