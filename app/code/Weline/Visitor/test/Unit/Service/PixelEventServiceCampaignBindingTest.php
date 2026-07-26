<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelChannelLookupService;
use Weline\Visitor\Service\PixelEventService;

/**
 * B07：prepare/hydrate 经 S2 绑定后写入 channel_name。
 */
class PixelEventServiceCampaignBindingTest extends TestCore
{
    protected function tearDown(): void
    {
        $this->resetChannelLookupOnSharedService();
        parent::tearDown();
    }

    private function resetChannelLookupOnSharedService(): void
    {
        try {
            /** @var PixelEventService $service */
            $service = ObjectManager::getInstance(PixelEventService::class);
            $prop = (new \ReflectionClass($service))->getProperty('channelLookupService');
            $prop->setAccessible(true);
            $prop->setValue($service, null);
        } catch (\Throwable) {
        }
    }

    public function testHydrateAppliesInjectedCampaignBinding(): void
    {
        $lookup = new class extends PixelChannelLookupService {
            public function applyCampaignBinding(array $attribution, int $websiteId, ?callable $finder = null): array
            {
                if (($attribution['channel_code'] ?? '') === 'summer_ad') {
                    $attribution['channel_name'] = '夏季投放';
                    $attribution['traffic_type'] = 'social';
                    $attribution['campaign_bound'] = true;
                }

                return $attribution;
            }
        };

        /** @var PixelEventService $service */
        $service = ObjectManager::getInstance(PixelEventService::class);
        $prop = (new \ReflectionClass($service))->getProperty('channelLookupService');
        $prop->setAccessible(true);
        $prop->setValue($service, $lookup);

        try {
            $data = $service->hydratePreparedAttribution(
                [
                    'url' => 'https://example.test/?wch=summer_ad&utm_medium=cpc',
                    'websiteId' => 42,
                ],
                [
                    'url' => 'https://example.test/?wch=summer_ad&utm_medium=cpc',
                    'website_id' => 42,
                    'session_id' => 'wps-b07',
                ]
            );

            self::assertSame('summer_ad', $data['channel_code']);
            self::assertSame('夏季投放', $data['channel_name']);
            self::assertSame('social', $data['traffic_type']);
        } finally {
            $prop->setValue($service, null);
        }
    }

    public function testPrepareWiresLookupForWchEvent(): void
    {
        /** @var PixelEventService $service */
        $service = ObjectManager::getInstance(PixelEventService::class);
        $method = new ReflectionMethod(PixelEventService::class, 'prepare');
        $method->setAccessible(true);
        /** @var array{data: array<string,mixed>} $prepared */
        $prepared = $method->invoke($service, [
            'eventName' => 'page_view',
            'websiteId' => 930407,
            'url' => 'https://example.test/?wch=b07_raw&utm_medium=cpc',
            'session_id' => 'wps-b07-prepare',
        ]);

        $data = $prepared['data'];
        self::assertSame('b07_raw', $data['channel_code']);
        // 无表或未登记 → S4
        self::assertNotSame('', $data['channel_name']);
        self::assertSame('未登记', $data['channel_name']);
    }
}
