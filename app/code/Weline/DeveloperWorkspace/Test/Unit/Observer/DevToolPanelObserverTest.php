<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\DeveloperWorkspace\Observer\DevToolPanelObserver;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;

final class DevToolPanelObserverTest extends TestCase
{
    public function testWriteBackPayloadMutatesStructuredTelemetryDataWithoutNestedDataKey(): void
    {
        $observer = new DevToolPanelObserver($this->createMock(Request::class));
        $event = new Event([
            'data' => [
                'result' => '<html><body>before</body></html>',
                'trace' => [
                    'spans' => [],
                ],
            ],
        ]);

        $method = new ReflectionMethod(DevToolPanelObserver::class, 'writeBackPayload');
        $method->setAccessible(true);
        $method->invoke($observer, $event, [
            'result' => '<html><body>after</body></html>',
            'trace' => [
                'spans' => [
                    ['name' => 'dev_tool_panel'],
                ],
            ],
        ]);

        $inner = $event->getEvenData();

        self::assertIsArray($inner);
        self::assertSame('<html><body>after</body></html>', $inner['result']);
        self::assertSame([['name' => 'dev_tool_panel']], $inner['trace']['spans']);
        self::assertArrayNotHasKey('data', $inner);
    }
}
