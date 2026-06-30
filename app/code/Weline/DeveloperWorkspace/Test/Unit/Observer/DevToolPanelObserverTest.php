<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\DeveloperWorkspace\Observer\DevToolPanelObserver;
use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestLifecycleTrace;

final class DevToolPanelObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLifecycleTrace::reset();
    }

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

    public function testStoreTracePayloadDoesNotDependOnIsEnabledWhenSpansAlreadyExist(): void
    {
        $property = new \ReflectionProperty(RequestLifecycleTrace::class, 'spans');
        $property->setAccessible(true);
        $property->setValue(null, [
            ['name' => 'router_start', 'duration_ms' => 1.5, 'category' => 'framework'],
        ]);

        $store = $this->createMock(DevToolPayloadStore::class);
        $store->expects(self::once())
            ->method('set')
            ->with(
                'trace',
                'trace:req-12345678',
                self::callback(static function (array $payload): bool {
                    return (int)($payload['summary']['span_count'] ?? 0) === 1
                        && (string)($payload['trace'] ?? '') !== '';
                }),
                60
            )
            ->willReturn(true);

        $observer = new DevToolPanelObserver($this->createMock(Request::class), $store);

        $method = new ReflectionMethod(DevToolPanelObserver::class, 'storeTracePayload');
        $method->setAccessible(true);
        $method->invoke($observer, 'req-12345678');
    }

    public function testHtmlFragmentIsEligibleForDevToolInjection(): void
    {
        $observer = new DevToolPanelObserver($this->createMock(Request::class));
        $method = new ReflectionMethod(DevToolPanelObserver::class, 'isHtmlResponse');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke(
            $observer,
            '<div class="category-products-grid"><style>.category-products-grid{display:grid}</style></div>'
        ));
    }

    public function testJsonPayloadIsNotEligibleForDevToolInjection(): void
    {
        $observer = new DevToolPanelObserver($this->createMock(Request::class));
        $method = new ReflectionMethod(DevToolPanelObserver::class, 'isHtmlResponse');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($observer, '{"success":true,"data":[]}'));
    }

    public function testExtractRequestIdsFromExistingDevToolMarkup(): void
    {
        $observer = new DevToolPanelObserver($this->createMock(Request::class));
        $method = new ReflectionMethod(DevToolPanelObserver::class, 'extractRequestIdsFromResult');
        $method->setAccessible(true);

        self::assertSame([
            'old-script-12345678',
            'old-config-12345678',
        ], $method->invoke(
            $observer,
            '<script>window.__WELINE_REQUEST_ID__="old-script-12345678";'
            . 'window.__WELINE_DEV_TOOL__={"requestId":"old-config-12345678"};</script>'
        ));
    }
}
