<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelLookupService;
use Weline\Visitor\Service\PixelTrafficAttributionService;

/**
 * B07：S2 campaign 绑定（查表可注入；A03 仍纯函数）。
 */
class PixelChannelLookupServiceTest extends TestCore
{
    private PixelChannelLookupService $lookup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lookup = new PixelChannelLookupService();
    }

    public function testApplyCampaignBindingPrefersSiteOverGlobalAndKeepsEmptyTypeFromS0(): void
    {
        $calls = [];
        $finder = function (string $code, int $websiteId) use (&$calls): ?array {
            $calls[] = [$code, $websiteId];
            // 模拟 findCampaignByCode 已做站点优先：注入器直接返回站点行
            if ($code === 'summer_ad' && $websiteId === 7) {
                return [
                    'code' => 'summer_ad',
                    'name' => '夏季投放',
                    'traffic_type' => '', // 空则保留 S0
                    'website_id' => 7,
                    'enabled' => 1,
                    'kind' => PixelChannel::KIND_CAMPAIGN,
                ];
            }

            return null;
        };

        $bound = $this->lookup->applyCampaignBinding([
            'channel_code' => 'summer_ad',
            'channel_name' => '',
            'traffic_type' => 'paid',
            'utm_source' => 'weline',
        ], 7, $finder);

        self::assertTrue($bound['campaign_bound']);
        self::assertSame('summer_ad', $bound['channel_code']);
        self::assertSame('夏季投放', $bound['channel_name']);
        self::assertSame('paid', $bound['traffic_type']); // 表 type 空 → 保留 S0
        self::assertSame([['summer_ad', 7]], $calls);
    }

    public function testApplyCampaignBindingOverridesTypeFromTableAndAcceptsDisabled(): void
    {
        $finder = static fn(string $code, int $websiteId): ?array => [
            'code' => $code,
            'name' => '社媒投放',
            'traffic_type' => PixelChannel::TRAFFIC_SOCIAL,
            'website_id' => 0,
            'enabled' => 0, // 停用仍归因
            'kind' => PixelChannel::KIND_CAMPAIGN,
        ];

        $bound = $this->lookup->applyCampaignBinding([
            'channel_code' => 'fb_ad',
            'channel_name' => '',
            'traffic_type' => 'paid',
        ], 3, $finder);

        self::assertTrue($bound['campaign_bound']);
        self::assertSame('社媒投放', $bound['channel_name']);
        self::assertSame('social', $bound['traffic_type']);
        self::assertSame(0, $bound['campaign_enabled']);
    }

    public function testUnregisteredCodeGetsS4DisplayName(): void
    {
        $bound = $this->lookup->applyCampaignBinding([
            'channel_code' => 'unknown_raw',
            'channel_name' => '',
            'traffic_type' => 'paid',
        ], 1, static fn() => null);

        self::assertFalse($bound['campaign_bound']);
        self::assertSame('unknown_raw', $bound['channel_code']);
        self::assertSame((string)__(PixelChannelLookupService::UNREGISTERED_NAME), $bound['channel_name']);
    }

    public function testEmptyCodeSkipsLookup(): void
    {
        $called = false;
        $bound = $this->lookup->applyCampaignBinding([
            'channel_code' => '',
            'channel_name' => '',
            'traffic_type' => 'direct',
        ], 1, static function () use (&$called) {
            $called = true;

            return null;
        });

        self::assertFalse($called);
        self::assertSame('', $bound['channel_name']);
        self::assertArrayNotHasKey('campaign_bound', $bound);
    }

    public function testSiteThenGlobalLookupOrderContractViaFinder(): void
    {
        // findCampaignByCode 内部：站点 websiteId → 全局 0；此处用 finder 固化该顺序契约
        $queries = [];
        $finder = function (string $code, int $websiteId) use (&$queries): ?array {
            $queries[] = [$code, $websiteId];
            $site = null; // 站点未命中
            if ($site === null && $websiteId > 0) {
                $queries[] = [$code, 0];

                return [
                    'code' => $code,
                    'name' => '全局渠道',
                    'traffic_type' => 'custom',
                    'website_id' => 0,
                    'enabled' => 1,
                ];
            }

            return null;
        };
        $bound = $this->lookup->applyCampaignBinding([
            'channel_code' => 'g',
            'channel_name' => '',
            'traffic_type' => 'custom',
        ], 5, $finder);
        self::assertSame('全局渠道', $bound['channel_name']);
        self::assertSame([['g', 5], ['g', 0]], $queries);
    }

    public function testAttributionServiceRemainsPureWithoutDbLookup(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelTrafficAttributionService.php'
        );
        self::assertStringNotContainsString('ObjectManager', $src);
        self::assertStringNotContainsString('findCampaign', $src);
        self::assertStringNotContainsString('use Weline\\Visitor\\Model\\PixelChannel', $src);

        $pure = new PixelTrafficAttributionService();
        $resolved = $pure->resolve([
            'url' => 'https://x.test/?wch=pure_code&utm_medium=cpc',
        ]);
        self::assertSame('pure_code', $resolved['channel_code']);
        self::assertSame('', $resolved['channel_name']);
    }
}
