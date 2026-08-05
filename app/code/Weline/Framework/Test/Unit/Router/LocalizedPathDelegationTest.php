<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\Controller\Data\DataInterface as ControllerDataInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Router\Core;
use Weline\Framework\Router\Observer\StripCurrencyLocalePrefix;
use Weline\Framework\Router\UrlProcessor;

final class LocalizedPathDelegationTest extends TestCase
{
    public function testObserverUsesSharedShapeParserAndPreservesArea(): void
    {
        $backendPrefix = $this->backendPrefix();

        self::assertSame(
            $backendPrefix . '/admin/login',
            $this->observe($backendPrefix . '/en_US/USD/admin/login')
        );
        self::assertSame('catalog', $this->observe('USD/en_US/catalog'));
        self::assertSame('catalog', $this->observe('en_US/USD/catalog'));
        self::assertSame('USD/catalog', $this->observe('CNY/USD/catalog'));
        self::assertSame('zh_Hans_CN/catalog', $this->observe('en_US/zh_Hans_CN/catalog'));
    }

    public function testUrlProcessorConsumesOnlyExactFirstAreaAndEitherLocalizationOrder(): void
    {
        $processor = new UrlProcessor();
        $backendPrefix = $this->backendPrefix();

        self::assertSame('catalog', $processor->normalize('/USD/catalog'));
        self::assertSame('catalog', $processor->normalize('/en_US/catalog'));
        self::assertSame('catalog', $processor->normalize('/USD/en_US/catalog'));
        self::assertSame('catalog', $processor->normalize('/en_US/USD/catalog'));
        self::assertSame(
            'admin/login',
            $processor->normalize(
                '/' . $backendPrefix . '/en_US/USD/admin/login',
                $backendPrefix,
                true,
                ControllerDataInterface::type_pc_BACKEND
            )
        );

        $wrongCase = strtolower($backendPrefix);
        if ($wrongCase !== $backendPrefix) {
            self::assertSame(
                $wrongCase . '/USD/en_US/admin/login',
                $processor->normalize(
                    '/' . $wrongCase . '/USD/en_US/admin/login',
                    $backendPrefix,
                    true,
                    ControllerDataInterface::type_pc_BACKEND
                )
            );
        }

        self::assertSame('USD/catalog', $processor->normalize('/CNY/USD/catalog'));
    }

    public function testProcessWithEventsSharesTheSameRouteResult(): void
    {
        $backendPrefix = $this->backendPrefix();
        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn('/' . $backendPrefix . '/en_US/USD/admin/login');

        self::assertSame(
            'admin/login',
            (new UrlProcessor())->processWithEvents(
                $request,
                $backendPrefix,
                true,
                ControllerDataInterface::type_pc_BACKEND
            )
        );
    }

    public function testCoreFastPathStripUsesSharedShapeParser(): void
    {
        $backendPrefix = $this->backendPrefix();

        self::assertSame('catalog', $this->coreStrip('USD/en_US/catalog'));
        self::assertSame('catalog', $this->coreStrip('en_US/USD/catalog'));
        self::assertSame('admin/login', $this->coreStrip($backendPrefix . '/en_US/USD/admin/login'));
        self::assertSame('USD/catalog', $this->coreStrip('CNY/USD/catalog'));
        self::assertSame('zh_Hans_CN/catalog', $this->coreStrip('en_US/zh_Hans_CN/catalog'));
    }

    private function observe(string $path): string
    {
        $data = new DataObject(['path' => $path]);
        $event = new Event(['data' => $data]);
        (new StripCurrencyLocalePrefix())->execute($event);

        return (string)$data->getData('path');
    }

    private function coreStrip(string $path): string
    {
        $reflection = new ReflectionClass(Core::class);
        $core = $reflection->newInstanceWithoutConstructor();

        return (string)$reflection->getMethod('stripLeadingLocaleCurrencySegments')->invoke($core, $path);
    }

    private function backendPrefix(): string
    {
        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);

        return $backendPrefix;
    }
}
