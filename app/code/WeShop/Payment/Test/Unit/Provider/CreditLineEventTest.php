<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\EventsManager;
use WeShop\Order\Model\Order;
use WeShop\Payment\Provider\CreditLineEvent;

class CreditLineEventTest extends TestCase
{
    public function testProcessPaymentOnlyDispatchesCreditRequestEvent(): void
    {
        $eventsManager = new class() extends EventsManager {
            public string $eventName = '';
            public array $payload = [];

            public function __construct()
            {
            }

            public function dispatch(string $eventName, mixed &$data = []): static
            {
                $this->eventName = $eventName;
                $this->payload = $data;
                $data['accepted'] = true;
                $data['status'] = 'pending';
                $data['provider_reference'] = 'credit-review-001';

                return $this;
            }
        };

        $provider = new CreditLineEvent($eventsManager);
        $order = new class() extends Order {
            public function __construct()
            {
            }

            public function getId(mixed $default = 0)
            {
                return 42;
            }

            public function getIncrementId(): string
            {
                return 'WS-CREDIT-001';
            }
        };

        $result = $provider->processPayment($order, ['currency' => 'USD'], []);

        $this->assertSame(CreditLineEvent::EVENT_NAME, $eventsManager->eventName);
        $this->assertSame('WS-CREDIT-001', $eventsManager->payload['order_number'] ?? '');
        $this->assertSame('pending', $result['status']);
        $this->assertTrue((bool) ($result['event_accepted'] ?? false));
        $this->assertSame('credit-review-001', $result['provider_reference'] ?? '');
        $this->assertFalse((bool) ($result['requires_action'] ?? true));
    }
}
