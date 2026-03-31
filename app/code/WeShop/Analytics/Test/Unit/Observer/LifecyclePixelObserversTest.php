<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Observer\CustomerLoginPixel;
use WeShop\Analytics\Observer\CustomerRegisterPixel;
use WeShop\Analytics\Observer\OrderCreatedPixel;
use WeShop\Analytics\Service\PixelDispatcher;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;

class LifecyclePixelObserversTest extends TestCase
{
    public function testCustomerLoginPixelSupportsArrayPayload(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->once())
            ->method('track')
            ->with('login', $this->callback(static function (array $payload): bool {
                return (int) ($payload['user_id'] ?? 0) === 9
                    && (string) ($payload['module'] ?? '') === 'WeShop_Customer';
            }));

        $observer = new CustomerLoginPixel($pixelDispatcher);
        $event = new Event(['data' => [
            'customer' => new class {
                public function getId(): int { return 9; }
                public function getCurrency(): string { return 'USD'; }
                public function getLocale(): string { return 'en_US'; }
            },
        ]]);

        $observer->execute($event);
    }

    public function testCustomerRegisterPixelSupportsDataObjectPayload(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->once())
            ->method('track')
            ->with('register', $this->callback(static function (array $payload): bool {
                return (int) ($payload['user_id'] ?? 0) === 13
                    && (string) ($payload['module'] ?? '') === 'WeShop_Customer';
            }));

        $observer = new CustomerRegisterPixel($pixelDispatcher);
        $event = new Event(['data' => new DataObject([
            'customer' => new class {
                public function getId(): int { return 13; }
                public function getCurrency(): string { return 'CNY'; }
                public function getLocale(): string { return 'zh_CN'; }
            },
        ])]);

        $observer->execute($event);
    }

    public function testOrderCreatedPixelSupportsArrayPayload(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->once())
            ->method('track')
            ->with('begin_checkout', $this->callback(static function (array $payload): bool {
                return (int) ($payload['user_id'] ?? 0) === 21
                    && (float) ($payload['value'] ?? 0.0) === 88.8
                    && (string) ($payload['module'] ?? '') === 'WeShop_Order';
            }));

        $observer = new OrderCreatedPixel($pixelDispatcher);
        $event = new Event(['data' => [
            'order' => new class {
                public function getCustomerId(): int { return 21; }
                public function getTotal(): float { return 88.8; }
                public function getCurrency(): string { return 'EUR'; }
                public function getId(): int { return 1001; }
                public function getIncrementId(): string { return 'WS1001'; }
            },
        ]]);

        $observer->execute($event);
    }

    public function testCustomerLifecycleObserversSkipInvalidPayloadGracefully(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->never())->method('track');

        $loginObserver = new CustomerLoginPixel($pixelDispatcher);
        $registerObserver = new CustomerRegisterPixel($pixelDispatcher);

        $invalidCustomerEvent = new Event(['data' => [
            'customer' => new \stdClass(),
        ]]);

        $loginObserver->execute($invalidCustomerEvent);
        $registerObserver->execute($invalidCustomerEvent);
    }

    public function testOrderCreatedPixelSkipsInvalidOrderPayloadGracefully(): void
    {
        $pixelDispatcher = $this->createMock(PixelDispatcher::class);
        $pixelDispatcher->expects($this->never())->method('track');

        $observer = new OrderCreatedPixel($pixelDispatcher);
        $event = new Event(['data' => [
            'order' => new \stdClass(),
        ]]);

        $observer->execute($event);
    }
}
