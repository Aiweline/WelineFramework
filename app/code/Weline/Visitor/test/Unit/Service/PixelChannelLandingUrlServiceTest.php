<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelCreateService;
use Weline\Visitor\Service\PixelChannelLandingUrlService;

/**
 * B06：投放链接拼装（Websites 基址可注入；不接归因）。
 */
class PixelChannelLandingUrlServiceTest extends TestCore
{
    private PixelChannelLandingUrlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelLandingUrlService(new PixelChannelCreateService());
    }

    public function testNormalizeLandingPath(): void
    {
        self::assertSame('/', $this->service->normalizeLandingPath(''));
        self::assertSame('/', $this->service->normalizeLandingPath('/'));
        self::assertSame('/promo', $this->service->normalizeLandingPath('promo'));
        self::assertSame('/promo/summer', $this->service->normalizeLandingPath('/promo/summer?x=1#y'));
        self::assertSame('/', $this->service->normalizeLandingPath('https://evil.example/x'));
        self::assertSame('/', $this->service->normalizeLandingPath('//evil.example/x'));
    }

    public function testBuildPreviewUsesWebsiteBaseAndUtmWch(): void
    {
        $preview = $this->service->buildPreview(
            [
                'code' => 'summer_ad',
                'traffic_type' => PixelChannel::TRAFFIC_PAID,
                'website_id' => 3,
                'enabled' => 1,
                'utm_source' => 'weline',
                'utm_medium' => 'cpc',
            ],
            '/promo',
            null,
            static fn(int $id): ?string => $id === 3 ? 'https://shop.example.com/' : null,
        );

        self::assertTrue($preview['showable']);
        self::assertSame('https://shop.example.com', $preview['base_url']);
        self::assertSame('/promo', $preview['landing_path']);
        self::assertSame(
            'https://shop.example.com/promo?utm_source=weline&utm_medium=cpc&utm_campaign=summer_ad&wch=summer_ad',
            $preview['url']
        );
        self::assertSame('summer_ad', $preview['params']['wch']);
    }

    public function testDisabledChannelIsNotShowableInLinkHelper(): void
    {
        $preview = $this->service->buildPreview(
            [
                'code' => 'summer_ad',
                'traffic_type' => PixelChannel::TRAFFIC_PAID,
                'website_id' => 1,
                'enabled' => 0,
            ],
            '/',
            'https://a.example',
        );
        self::assertFalse($preview['showable']);
        self::assertSame('', $preview['url']);
        self::assertStringContainsString('utm_campaign=summer_ad', $preview['query']);
    }

    public function testGlobalWebsiteFallsBackToInjectedOrEmptyBase(): void
    {
        $preview = $this->service->buildPreview(
            [
                'code' => 'g1',
                'traffic_type' => PixelChannel::TRAFFIC_CUSTOM,
                'website_id' => 0,
                'enabled' => 1,
            ],
            '/',
            'https://fallback.example',
        );
        self::assertSame(
            'https://fallback.example/?utm_source=weline&utm_medium=custom&utm_campaign=g1&wch=g1',
            $preview['url']
        );
    }

    public function testResolveBaseUrlAddsSchemeAndTrimsSlash(): void
    {
        $base = $this->service->resolveBaseUrl(9, static fn() => 'shop.example.com/');
        self::assertSame('https://shop.example.com', $base);
    }
}
