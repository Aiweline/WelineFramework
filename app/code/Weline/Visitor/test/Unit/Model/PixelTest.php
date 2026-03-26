<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Model\Pixel;

class PixelTest extends TestCore
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

    public function testSavePixelData(): void
    {
        $data = [
            'url' => 'https://example.com/test',
            'module' => 'Weline_Frontend',
            'name' => 'test_event',
            'event' => 'click',
            'value' => 100,
            'lang' => 'zh-CN',
            'currency' => 'RMB',
            'website_id' => 1,
            'referer' => 'https://example.com',
            'user_id' => 123,
            'user_agent' => 'Mozilla/5.0 Test',
            'ip' => '192.168.1.1',
            'browser_info' => json_encode(['test' => 'data'], JSON_UNESCAPED_UNICODE),
        ];

        $pixel = $this->createPixel($data);

        $this->assertNotEmpty($pixel->getId());
        $this->assertSame($data['url'], $pixel->getUrl());
        $this->assertSame($data['event'], $pixel->getEvent());
        $this->assertSame($data['value'], $pixel->getValue());
        $this->assertSame($data['website_id'], $pixel->getWebsiteId());
    }

    public function testGetPixelsByWebsiteId(): void
    {
        $websiteId = 999;
        $pixel = $this->createPixel([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => $websiteId,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent',
        ]);

        $pixels = Pixel::getPixelsByWebsiteId($websiteId);

        $this->assertIsArray($pixels);
        $this->assertNotEmpty($pixels);

        $found = false;
        foreach ($pixels as $item) {
            if ((int)$item->getId() === (int)$pixel->getId()) {
                $found = true;
                $this->assertSame($websiteId, $item->getWebsiteId());
                break;
            }
        }

        $this->assertTrue($found);
    }

    public function testCountPixelsByWebsiteId(): void
    {
        $websiteId = 998;

        $this->createPixel([
            'url' => 'https://example.com/test1',
            'event' => 'click',
            'website_id' => $websiteId,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent',
        ]);

        $this->createPixel([
            'url' => 'https://example.com/test2',
            'event' => 'view',
            'website_id' => $websiteId,
            'ip' => '192.168.1.2',
            'user_agent' => 'Test Agent',
        ]);

        $count = Pixel::countPixelsByWebsiteId($websiteId);

        $this->assertGreaterThanOrEqual(2, $count);
    }

    public function testGetWebsiteSummary(): void
    {
        $websiteId = 997;
        $this->createPixel([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => $websiteId,
            'value' => 100,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent',
        ]);

        $summary = Pixel::getWebsiteSummary($websiteId);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_count', $summary);
        $this->assertArrayHasKey('un_deal_count', $summary);
        $this->assertArrayHasKey('event_counts', $summary);
        $this->assertGreaterThanOrEqual(1, $summary['total_count']);
    }

    public function testIpAddressCollection(): void
    {
        $ips = [
            '192.168.1.1',
            '10.0.0.1',
            '172.16.0.1',
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
        ];

        foreach ($ips as $ip) {
            $pixel = $this->createPixel([
                'url' => 'https://example.com/test',
                'event' => 'click',
                'website_id' => 1,
                'ip' => $ip,
                'user_agent' => 'Test Agent',
            ]);

            $this->assertSame($ip, $pixel->getIp());
        }
    }

    public function testBrowserLanguageWebsiteCurrencyCollection(): void
    {
        $pixel = $this->createPixel([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => 888,
            'ip' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 Chrome',
            'lang' => 'zh-CN',
            'currency' => 'RMB',
            'browser_info' => json_encode([
                'browser' => 'Chrome',
                'version' => '91.0.4472.124',
                'os' => 'Windows 10',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertNotEmpty($pixel->getUserAgent());
        $this->assertStringContainsString('Chrome', $pixel->getUserAgent());
        $this->assertSame('zh-CN', $pixel->getLang());
        $this->assertSame(888, $pixel->getWebsiteId());
        $this->assertSame('RMB', $pixel->getCurrency());
        $this->assertSame('Chrome', json_decode($pixel->getBrowserInfo(), true)['browser']);
    }

    public function testCompletePixelDataCollection(): void
    {
        $completeData = [
            'url' => 'https://example.com/product/123',
            'module' => 'Weline_Frontend',
            'name' => 'product_view',
            'event' => 'view',
            'value' => 299.99,
            'lang' => 'en-US',
            'currency' => 'USD',
            'website_id' => 777,
            'referer' => 'https://example.com/search?q=product',
            'user_id' => 456,
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            'ip' => '203.0.113.1',
            'browser_info' => json_encode([
                'browser' => 'Safari',
                'version' => '14.1',
                'os' => 'macOS',
                'screen' => ['width' => 1920, 'height' => 1080],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $pixel = $this->createPixel($completeData);

        $this->assertSame($completeData['url'], $pixel->getUrl());
        $this->assertSame($completeData['module'], $pixel->getModule());
        $this->assertSame($completeData['name'], $pixel->getName());
        $this->assertSame($completeData['event'], $pixel->getEvent());
        $this->assertSame((int)round($completeData['value']), $pixel->getValue());
        $this->assertSame($completeData['lang'], $pixel->getLang());
        $this->assertSame($completeData['currency'], $pixel->getCurrency());
        $this->assertSame($completeData['website_id'], $pixel->getWebsiteId());
        $this->assertSame($completeData['referer'], $pixel->getReferer());
        $this->assertSame($completeData['user_id'], $pixel->getUserId());
        $this->assertSame($completeData['user_agent'], $pixel->getUserAgent());
        $this->assertSame($completeData['ip'], $pixel->getIp());
    }

    private function createPixel(array $data): Pixel
    {
        $pixel = ObjectManager::make(Pixel::class);
        $pixel->setData($data)->save();

        $this->pixelIds[] = $pixel->getId();

        return $pixel;
    }
}
