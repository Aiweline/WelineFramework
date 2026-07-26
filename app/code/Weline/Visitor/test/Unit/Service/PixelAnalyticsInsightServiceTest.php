<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\PixelAnalyticsInsightService;
use Weline\Visitor\Service\PixelEventService;

class PixelAnalyticsInsightServiceTest extends TestCore
{
    /** @var int[] */
    private array $pixelIds = [];

    protected function tearDown(): void
    {
        foreach (array_reverse(array_unique($this->pixelIds)) as $pixelId) {
            try {
                ObjectManager::make(Pixel::class)->load($pixelId)->delete();
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testInsightReportComputesBounceAndPages(): void
    {
        $siteId = 920101;
        $sessionA = 'wps-test-a-' . uniqid();
        $sessionB = 'wps-test-b-' . uniqid();

        $this->createPixel($siteId, 'page_view', $sessionA, '/home', 'desktop', 0, '198.51.100.1');
        $this->createPixel($siteId, 'page_exit', $sessionA, '/home', 'desktop', 2500, '198.51.100.1');
        $this->createPixel($siteId, 'page_view', $sessionB, '/contact', 'mobile', 0, '198.51.100.2');
        $this->createPixel($siteId, 'contact_click', $sessionB, '/contact', 'mobile', 0, '198.51.100.2');
        $this->createPixel($siteId, 'page_exit', $sessionB, '/contact', 'mobile', 18000, '198.51.100.2');

        /** @var PixelAnalyticsInsightService $service */
        $service = ObjectManager::getInstance(PixelAnalyticsInsightService::class);
        $report = $service->buildReport([
            'websiteId' => (string)$siteId,
            'range' => '30d',
        ]);

        self::assertSame(2, (int)$report['engagement']['sessions']);
        self::assertGreaterThan(0, (float)$report['engagement']['bounce_rate']);
        self::assertGreaterThan(0, (float)$report['engagement']['engagement_rate']);
        self::assertNotEmpty($report['pages']);
        self::assertNotEmpty($report['devices']);
    }

    public function testTrackPersistsDeviceAndEnvironment(): void
    {
        /** @var PixelEventService $tracker */
        $tracker = ObjectManager::getInstance(PixelEventService::class);
        $result = $tracker->track([
            'eventName' => 'page_view',
            'websiteId' => 920102,
            'url' => 'https://example.test/about?utm_source=newsletter&utm_medium=email',
            'module' => 'Weline_Visitor',
            'name' => 'unit',
            'userLang' => 'zh-CN',
            'currency' => 'CNY',
            'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
            'ip' => '203.0.113.9',
            'screen' => ['width' => 390, 'height' => 844],
            'additionalInfo' => [
                'schema' => 'weline_behavior_timing_v2',
                'environment' => [
                    'page_path' => '/about',
                    'session_id' => 'wps-unit-env',
                    'page_id' => 'p1',
                ],
                'funnel' => ['session_id' => 'wps-unit-env', 'page_id' => 'p1', 'step' => 10, 'chain' => []],
                'device' => ['category' => 'mobile', 'screen_width' => 390, 'screen_height' => 844],
                'utm' => ['source' => 'newsletter', 'medium' => 'email'],
                'engagement' => ['engaged' => false, 'dwell_ms' => 0],
                'navigation' => ['current_path' => '/about'],
                'viewport' => ['inner_width' => 390],
                'meta' => [],
            ],
        ]);

        $pixelId = (int)($result['data']['pixel_id'] ?? 0);
        self::assertGreaterThan(0, $pixelId);
        $this->pixelIds[] = $pixelId;

        $row = ObjectManager::make(Pixel::class)->load($pixelId)->getData();
        $info = json_decode((string)($row['browser_info'] ?? ''), true);
        self::assertIsArray($info);
        self::assertSame('wps-unit-env', $info['session_id'] ?? null);
        self::assertSame('/about', $info['page_path'] ?? null);
        self::assertSame('mobile', $info['device_category'] ?? null);
        self::assertSame('newsletter', $info['additionalInfo']['utm']['source'] ?? null);
    }

    private function createPixel(
        int $websiteId,
        string $event,
        string $sessionId,
        string $path,
        string $device,
        int $dwellMs,
        string $ip
    ): void {
        $pixel = ObjectManager::make(Pixel::class);
        $pixel->setData([
            'url' => 'https://example.test' . $path,
            'module' => 'Weline_Visitor',
            'name' => 'insight-test',
            'event' => $event,
            'value' => 0,
            'lang' => 'zh-CN',
            'currency' => 'CNY',
            'website_id' => $websiteId,
            'source' => 'unit-test',
            'referer' => '',
            'user_id' => 0,
            'user_agent' => 'PHPUnit Insight',
            'ip' => $ip,
            'cron_deal' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'browser_info' => json_encode([
                'schema' => 'weline_pixel_browser_v2',
                'session_id' => $sessionId,
                'page_path' => $path,
                'device_category' => $device,
                'dwell_ms' => $dwellMs,
                'screen' => ['width' => $device === 'mobile' ? 390 : 1440, 'height' => $device === 'mobile' ? 844 : 900],
                'additionalInfo' => [
                    'environment' => ['page_path' => $path, 'session_id' => $sessionId],
                    'device' => ['category' => $device],
                    'engagement' => [
                        'engaged' => in_array($event, ['contact_click', 'cta_click'], true) || $dwellMs >= 10000,
                        'dwell_ms' => $dwellMs,
                    ],
                    'utm' => [],
                    'meta' => ['duration_ms' => $dwellMs],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ])->save();
        $this->pixelIds[] = (int)$pixel->getId();
    }
}
