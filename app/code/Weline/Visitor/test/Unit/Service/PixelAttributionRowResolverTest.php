<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelAttributionRowResolver;

/**
 * A15：扁平列空时回退 browser_info / url / referer。
 */
class PixelAttributionRowResolverTest extends TestCore
{
    private function resolver(): PixelAttributionRowResolver
    {
        /** @var PixelAttributionRowResolver $resolver */
        $resolver = ObjectManager::getInstance(PixelAttributionRowResolver::class);

        return $resolver;
    }

    public function testPrefersFlatColumnsWhenPresent(): void
    {
        $resolved = $this->resolver()->resolve([
            'url' => 'https://example.test/?utm_source=later',
            'referer' => '',
            'source' => 'worker',
            'session_id' => 'wps-flat',
            'channel_code' => 'summer',
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'welcome',
            'traffic_type' => 'email',
            'browser_info' => json_encode([
                'additionalInfo' => [
                    'utm' => ['source' => 'should_ignore', 'medium' => 'cpc'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame('flat', $resolved['origin']);
        self::assertSame('wps-flat', $resolved['session_id']);
        self::assertSame('summer', $resolved['channel_code']);
        self::assertSame('newsletter', $resolved['utm_source']);
        self::assertSame('newsletter/email', $resolved['source_label']);
    }

    public function testFallsBackToBrowserInfoUtmWhenFlatEmpty(): void
    {
        $resolved = $this->resolver()->resolve([
            'url' => 'https://example.test/about',
            'referer' => '',
            'source' => 'worker',
            'session_id' => '',
            'channel_code' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'traffic_type' => '',
            'browser_info' => json_encode([
                'session_id' => 'wps-browser',
                'additionalInfo' => [
                    'utm' => [
                        'source' => 'newsletter',
                        'medium' => 'email',
                        'campaign' => 'spring',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame('browser', $resolved['origin']);
        self::assertSame('wps-browser', $resolved['session_id']);
        self::assertSame('newsletter', $resolved['utm_source']);
        self::assertSame('email', $resolved['utm_medium']);
        self::assertSame('newsletter/email', $resolved['source_label']);
        self::assertNotSame('direct', $resolved['source_label']);
        self::assertNotSame('worker', $resolved['source_label']);
    }

    public function testFallsBackToUrlQueryWhenBrowserUtmMissing(): void
    {
        $resolved = $this->resolver()->resolve([
            'url' => 'https://example.test/l?wch=ad_a15&utm_source=google&utm_medium=cpc&utm_campaign=x',
            'referer' => 'https://google.com/',
            'source' => '',
            'browser_info' => '{}',
        ]);

        self::assertSame('browser', $resolved['origin']);
        self::assertSame('ad_a15', $resolved['channel_code']);
        self::assertSame('paid', $resolved['traffic_type']);
        self::assertSame('google/cpc', $resolved['source_label']);
    }

    public function testFallsBackToStickyInBrowserInfo(): void
    {
        $resolved = $this->resolver()->resolve([
            'url' => 'https://example.test/',
            'referer' => '',
            'source' => 'worker',
            'browser_info' => json_encode([
                'additionalInfo' => [
                    'sticky' => [
                        'wch' => 'first',
                        'utm_source' => 'facebook',
                        'utm_medium' => 'paid_social',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame('browser', $resolved['origin']);
        self::assertSame('first', $resolved['channel_code']);
        self::assertSame('facebook/paid_social', $resolved['source_label']);
    }

    public function testEmptyFallsBackToRefererHostOrDirect(): void
    {
        $referral = $this->resolver()->resolve([
            'url' => 'https://example.test/',
            'referer' => 'https://news.example/story',
            'source' => 'worker',
            'browser_info' => '{}',
        ]);
        self::assertSame('empty', $referral['origin']);
        self::assertSame('news.example', $referral['source_label']);

        $direct = $this->resolver()->resolve([
            'url' => 'https://example.test/',
            'referer' => '',
            'source' => '',
            'browser_info' => '{}',
        ]);
        self::assertSame('empty', $direct['origin']);
        self::assertSame('direct', $direct['source_label']);
    }

    public function testInsightAndStatisticsWireResolver(): void
    {
        $insight = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelAnalyticsInsightService.php'
        );
        self::assertStringContainsString('PixelAttributionRowResolver', $insight);
        self::assertStringContainsString('attributionResolver()', $insight);

        $stats = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'
        );
        self::assertStringContainsString('getDashboardSourceRowsViaResolver', $stats);
        self::assertStringContainsString('PixelAttributionRowResolver', $stats);
    }
}
