<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Service\PixelDispatcher;

class PixelDispatcherTest extends TestCase
{
    public function testTrackDelegatesToActiveProviders(): void
    {
        $provider = new class() implements PixelProviderInterface {
            public array $events = [];

            public function isEnabled(): bool
            {
                return true;
            }

            public function sendEvent(string $eventName, array $eventData): bool
            {
                $this->events[] = [$eventName, $eventData];
                return true;
            }

            public function getPixelCode(): string
            {
                return '';
            }
        };

        $dispatcher = new class([$provider]) extends PixelDispatcher {
            public function __construct(private readonly array $providers)
            {
            }

            protected function getActiveProviders(): array
            {
                return $this->providers;
            }
        };

        $dispatcher->track('purchase', ['value' => 99.5]);

        $this->assertCount(1, $provider->events);
        $this->assertSame('purchase', $provider->events[0][0]);
        $this->assertSame(99.5, $provider->events[0][1]['value']);
    }

    public function testDispatchNormalizesAddToCartObserverPayload(): void
    {
        $provider = new class() implements PixelProviderInterface {
            public array $events = [];

            public function isEnabled(): bool
            {
                return true;
            }

            public function sendEvent(string $eventName, array $eventData): bool
            {
                $this->events[] = [$eventName, $eventData];

                return true;
            }

            public function getPixelCode(): string
            {
                return '';
            }
        };

        $dispatcher = new class([$provider]) extends PixelDispatcher {
            public function __construct(private readonly array $providers)
            {
            }

            protected function getActiveProviders(): array
            {
                return $this->providers;
            }
        };

        $dispatcher->dispatch('AddToCart', [
            'product_id' => 11,
            'quantity' => 2,
            'price' => 12.5,
        ]);

        $this->assertCount(1, $provider->events);
        $this->assertSame('add_to_cart', $provider->events[0][0]);
        $this->assertSame(25.0, $provider->events[0][1]['value']);
        $this->assertSame(11, $provider->events[0][1]['items'][0]['product_id'] ?? null);
        $this->assertSame(2, $provider->events[0][1]['items'][0]['quantity'] ?? null);
        $this->assertSame(2, $provider->events[0][1]['items'][0]['qty'] ?? null);
    }

    public function testDispatchNormalizesPurchaseObserverPayload(): void
    {
        $provider = new class() implements PixelProviderInterface {
            public array $events = [];

            public function isEnabled(): bool
            {
                return true;
            }

            public function sendEvent(string $eventName, array $eventData): bool
            {
                $this->events[] = [$eventName, $eventData];

                return true;
            }

            public function getPixelCode(): string
            {
                return '';
            }
        };

        $dispatcher = new class([$provider]) extends PixelDispatcher {
            public function __construct(private readonly array $providers)
            {
            }

            protected function getActiveProviders(): array
            {
                return $this->providers;
            }
        };

        $dispatcher->dispatch('Purchase', [
            'order_id' => 15,
            'order_number' => 'WS1001',
            'total' => 88.6,
            'currency' => 'EUR',
        ]);

        $this->assertCount(1, $provider->events);
        $this->assertSame('purchase', $provider->events[0][0]);
        $this->assertSame('WS1001', $provider->events[0][1]['transaction_id']);
        $this->assertSame(88.6, $provider->events[0][1]['value']);
        $this->assertSame('EUR', $provider->events[0][1]['currency']);
    }

    public function testDispatchPromotesAdditionalFieldsAndEventSourceUrl(): void
    {
        $provider = new class() implements PixelProviderInterface {
            public array $events = [];

            public function isEnabled(): bool
            {
                return true;
            }

            public function sendEvent(string $eventName, array $eventData): bool
            {
                $this->events[] = [$eventName, $eventData];

                return true;
            }

            public function getPixelCode(): string
            {
                return '';
            }
        };

        $dispatcher = new class([$provider]) extends PixelDispatcher {
            public function __construct(private readonly array $providers)
            {
            }

            protected function getActiveProviders(): array
            {
                return $this->providers;
            }
        };

        $dispatcher->track('begin_checkout', [
            'url' => '/checkout',
            'additional' => [
                'increment_id' => 'WS1002',
                'order_id' => 16,
            ],
        ]);

        $this->assertCount(1, $provider->events);
        $this->assertSame('begin_checkout', $provider->events[0][0]);
        $this->assertSame('/checkout', $provider->events[0][1]['event_source_url']);
        $this->assertSame('WS1002', $provider->events[0][1]['transaction_id']);
        $this->assertSame(16, $provider->events[0][1]['order_id']);
    }
}
