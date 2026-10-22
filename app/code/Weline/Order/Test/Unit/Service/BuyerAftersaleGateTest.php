<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Order\Model\BuyerAftersaleRequest;
use Weline\Order\Observer\BuyerAftersaleLinkPaymentObserver;
use Weline\Order\Service\BuyerAftersaleConfig;
use Weline\Order\Service\BuyerAftersaleService;

/**
 * 买家售后总闸：关闭时拒绝提交，且联动观察者不调用退款协调。
 */
final class BuyerAftersaleGateTest extends TestCase
{
    public function testSubmitReturnsDisabledWhenMasterGateIsOff(): void
    {
        $service = new BuyerAftersaleService($this->closedConfig());
        $result = $service->submit(9, [
            'order_uuid' => '11111111-1111-4111-8111-111111111111',
            'resolution' => BuyerAftersaleRequest::RESOLUTION_RETURN,
            'reason_code' => BuyerAftersaleRequest::REASON_QUALITY,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(BuyerAftersaleService::ERROR_DISABLED, $result['error_code']);
    }

    public function testLinkObserverDoesNotCallCoordinatorWhenGatesAreClosed(): void
    {
        $observer = new class($this->closedConfig()) extends BuyerAftersaleLinkPaymentObserver {
            public int $coordinatorCalls = 0;

            protected function requestCoordinatorRefund(array $data): void
            {
                $this->coordinatorCalls++;
            }
        };
        $payload = [
            'request_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'order_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'resolution' => BuyerAftersaleRequest::RESOLUTION_REFUND_ONLY,
            'status' => BuyerAftersaleRequest::STATUS_APPROVED,
            'reason_code' => BuyerAftersaleRequest::REASON_QUALITY,
        ];
        $event = Event::forArrayPayload($payload, [], 'Weline_Order::buyer_after_sale_link_payment');
        $observer->execute($event);

        self::assertSame(0, $observer->coordinatorCalls);
    }

    private function closedConfig(): BuyerAftersaleConfig
    {
        return new class extends BuyerAftersaleConfig {
            public function isBuyerAftersaleEnabled(?string $storageScope = null): bool
            {
                return false;
            }

            public function isLinkPaymentEnabled(?string $storageScope = null): bool
            {
                return false;
            }
        };
    }
}
