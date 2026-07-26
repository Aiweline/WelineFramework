<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelLandingDeviceDerivation;

/**
 * D00：landing/device 派生纯函数（不查库）。
 */
final class PixelLandingDeviceDerivationTest extends TestCase
{
    private PixelLandingDeviceDerivation $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PixelLandingDeviceDerivation();
    }

    public function testNormalizePagePathBasics(): void
    {
        self::assertSame('/', $this->svc->normalizePagePath(''));
        self::assertSame('/', $this->svc->normalizePagePath('/'));
        self::assertSame('/about', $this->svc->normalizePagePath('about'));
        self::assertSame('/promo', $this->svc->normalizePagePath('/promo?utm_source=x#top'));
        self::assertSame('/landing', $this->svc->normalizePagePath('https://example.test/landing?wch=a'));
        self::assertSame('/a/b', $this->svc->normalizePagePath('//cdn.example/a/b'));
        self::assertSame('/a/b', $this->svc->normalizePagePath('/a//b/'));
    }

    public function testNormalizePagePathTruncates(): void
    {
        $long = '/' . str_repeat('a', 200);
        $out = $this->svc->normalizePagePath($long);
        self::assertSame(PixelLandingDeviceDerivation::PATH_MAX_LEN, \strlen($out));
        self::assertStringStartsWith('/', $out);
    }

    public function testResolvePagePathPriority(): void
    {
        $info = [
            'page_path' => '/from-top',
            'additionalInfo' => [
                'environment' => ['page_path' => '/from-env'],
                'navigation' => ['current_path' => '/from-nav'],
            ],
        ];
        self::assertSame('/from-top', $this->svc->resolvePagePath($info, 'https://x.test/url-path'));

        unset($info['page_path']);
        self::assertSame('/from-env', $this->svc->resolvePagePath($info, 'https://x.test/url-path'));

        unset($info['additionalInfo']['environment']);
        self::assertSame('/from-nav', $this->svc->resolvePagePath($info, 'https://x.test/url-path'));

        self::assertSame('/url-path', $this->svc->resolvePagePath([], 'https://x.test/url-path?q=1'));
        self::assertSame('/', $this->svc->resolvePagePath([]));
    }

    public function testResolvePagePathFromEncodedBrowserInfoRow(): void
    {
        $row = [
            'browser_info' => json_encode([
                'additionalInfo' => [
                    'environment' => ['page_path' => '/encoded'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];
        self::assertSame('/encoded', $this->svc->resolvePagePath($row));
    }

    public function testDeriveDeviceCategoryExplicitAndUa(): void
    {
        self::assertSame('tablet', $this->svc->deriveDeviceCategory('', ['category' => 'tablet']));
        self::assertSame(
            'mobile',
            $this->svc->deriveDeviceCategory('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)')
        );
        self::assertSame(
            'tablet',
            $this->svc->deriveDeviceCategory('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)')
        );
        self::assertSame(
            'desktop',
            $this->svc->deriveDeviceCategory('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Chrome/120.0')
        );
    }

    public function testDeriveDeviceCategoryScreenWidthFallback(): void
    {
        self::assertSame('mobile', $this->svc->deriveDeviceCategory('', ['screen_width' => 390]));
        self::assertSame('tablet', $this->svc->deriveDeviceCategory('', ['screen_width' => 800]));
        self::assertSame('desktop', $this->svc->deriveDeviceCategory('', ['screen_width' => 1440]));
        self::assertSame('', $this->svc->deriveDeviceCategory('', []));
    }

    public function testDeriveDeviceCategoryNarrowDesktopUaUsesWidth(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0';
        self::assertSame('mobile', $this->svc->deriveDeviceCategory($ua, ['screen_width' => 400]));
        self::assertSame('desktop', $this->svc->deriveDeviceCategory($ua, ['screen_width' => 1400]));
    }

    public function testDeriveLandingPagePrefersFirstPageView(): void
    {
        $events = [
            [
                'event' => 'click',
                'page_path' => '/header',
                'created_at' => '2026-07-25 10:00:01',
            ],
            [
                'event' => 'page_view',
                'page_path' => '/promo?x=1',
                'created_at' => '2026-07-25 10:00:02',
            ],
            [
                'event' => 'page_view',
                'page_path' => '/later',
                'created_at' => '2026-07-25 10:00:03',
            ],
        ];
        self::assertSame('/promo', $this->svc->deriveLandingPage($events));
    }

    public function testDeriveLandingPageFallsBackToFirstEventPath(): void
    {
        $events = [
            [
                'event' => 'add_to_cart',
                'url' => 'https://shop.test/cart',
                'created_at' => '2026-07-25 11:00:00',
            ],
            [
                'event' => 'purchase',
                'page_path' => '/thanks',
                'created_at' => '2026-07-25 11:00:01',
            ],
        ];
        self::assertSame('/cart', $this->svc->deriveLandingPage($events));
        self::assertSame('', $this->svc->deriveLandingPage([]));
    }

    public function testDeriveLandingPageSortsByCreatedAt(): void
    {
        $events = [
            [
                'event' => 'page_enter',
                'path' => '/second',
                'created_at' => '2026-07-25 12:00:02',
            ],
            [
                'event' => 'page_view',
                'path' => '/first',
                'created_at' => '2026-07-25 12:00:01',
            ],
        ];
        self::assertSame('/first', $this->svc->deriveLandingPage($events));
    }

    public function testLandingEventsConstantAlignedWithFunnel(): void
    {
        self::assertSame(
            \Weline\Visitor\Service\PixelChannelHotTotalsService::PAGE_VIEW_EVENTS,
            PixelLandingDeviceDerivation::LANDING_EVENTS
        );
    }
}
