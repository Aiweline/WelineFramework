<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Observer\TaglibPixelGoogleBridge;
use WeShop\Analytics\Service\AnalyticsConfigService;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;

class TaglibPixelGoogleBridgeTest extends TestCase
{
    public function testExecuteAppendsGoogleBridgeWhenProviderIsReady(): void
    {
        $configService = $this->createMock(AnalyticsConfigService::class);
        $configService->expects(self::once())
            ->method('isProviderReady')
            ->with(AnalyticsConfigService::PROVIDER_GOOGLE)
            ->willReturn(true);

        $observer = new TaglibPixelGoogleBridge($configService);
        $data = new DataObject(['pixel_code' => "window.customPixelHook = 'keep';"]);
        $event = new Event(['data' => $data]);

        $observer->execute($event);

        $pixelCode = (string) $data->getData('pixel_code');
        self::assertStringContainsString("window.customPixelHook = 'keep';", $pixelCode);
        self::assertStringContainsString("typeof parentWindow.gtag !== 'function'", $pixelCode);
        self::assertStringContainsString("case 'checkout_success':", $pixelCode);
        self::assertStringContainsString("return 'purchase';", $pixelCode);
        self::assertStringContainsString("return 'sign_up';", $pixelCode);
        self::assertStringContainsString("parentWindow.gtag('event', eventName, params);", $pixelCode);
    }

    public function testExecuteSkipsBridgeWhenGoogleProviderIsNotReady(): void
    {
        $configService = $this->createMock(AnalyticsConfigService::class);
        $configService->expects(self::once())
            ->method('isProviderReady')
            ->with(AnalyticsConfigService::PROVIDER_GOOGLE)
            ->willReturn(false);

        $observer = new TaglibPixelGoogleBridge($configService);
        $data = new DataObject(['pixel_code' => "window.customPixelHook = 'keep';"]);
        $event = new Event(['data' => $data]);

        $observer->execute($event);

        self::assertSame("window.customPixelHook = 'keep';", $data->getData('pixel_code'));
    }
}
