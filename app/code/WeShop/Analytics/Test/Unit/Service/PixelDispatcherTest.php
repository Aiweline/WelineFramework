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
}
