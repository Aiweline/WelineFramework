<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\PixelCronSourceCompatService;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * B13：旧 Cron 不再用 PixelSource referer 规则覆盖新归因结果。
 */
class PixelCronSourceCompatServiceTest extends TestCore
{
    private PixelCronSourceCompatService $service;

    /** @var array<int, array<string, mixed>> */
    private array $legacyMaps = [
        ['code' => 'Facebook', 'referer_domain_contains' => 'facebook,fb.com'],
        ['code' => 'Google', 'referer_domain_contains' => 'google.'],
        ['code' => 'Empty', 'referer_domain_contains' => ''],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelCronSourceCompatService();
    }

    public function testChannelCodeSyncsIntoSource(): void
    {
        $decision = $this->service->decide([
            Pixel::schema_fields_ID => 11,
            Pixel::schema_fields_CHANNEL_CODE => 'summer_sale',
            Pixel::schema_fields_SOURCE => 'direct',
            'referer' => 'https://www.facebook.com/x',
        ], true, $this->legacyMaps);

        self::assertSame(11, $decision['pixel_id']);
        self::assertSame('summer_sale', $decision['source']);
        self::assertSame(PixelCronSourceCompatService::REASON_CHANNEL_CODE, $decision['reason']);
    }

    public function testChannelCodeAlreadySyncedDoesNotRewrite(): void
    {
        $decision = $this->service->decide([
            Pixel::schema_fields_ID => 12,
            Pixel::schema_fields_CHANNEL_CODE => 'summer_sale',
            Pixel::schema_fields_SOURCE => 'summer_sale',
        ], true, $this->legacyMaps);

        self::assertNull($decision['source']);
        self::assertSame(PixelCronSourceCompatService::REASON_ALREADY_SYNCED, $decision['reason']);
    }

    public function testLegacyRefererDoesNotOverrideNewAttributionByDefault(): void
    {
        $row = [
            Pixel::schema_fields_ID => 13,
            Pixel::schema_fields_CHANNEL_CODE => '',
            Pixel::schema_fields_SOURCE => 'direct',
            'referer' => 'https://m.facebook.com/post/1',
        ];

        $off = $this->service->decide($row, false, $this->legacyMaps);
        self::assertNull($off['source'], '默认关闭时不得反写 source');
        self::assertSame(PixelCronSourceCompatService::REASON_SKIPPED, $off['reason']);

        $on = $this->service->decide($row, true, $this->legacyMaps);
        self::assertSame('Facebook', $on['source'], '显式开启兼容开关时才走旧映射');
        self::assertSame(PixelCronSourceCompatService::REASON_LEGACY_REFERER, $on['reason']);
    }

    public function testMatchLegacySourceHandlesHostAndEmptyRules(): void
    {
        self::assertSame('Google', $this->service->matchLegacySource('https://www.google.com/search?q=1', $this->legacyMaps));
        self::assertSame('', $this->service->matchLegacySource('', $this->legacyMaps));
        self::assertSame('', $this->service->matchLegacySource('not-a-url', $this->legacyMaps));
        self::assertSame('', $this->service->matchLegacySource('https://example.com/', $this->legacyMaps));
        self::assertSame('', $this->service->matchLegacySource('https://example.com/', []));
    }

    public function testProcessCountsDecisionsAndAlwaysMarksRows(): void
    {
        $rows = [
            [Pixel::schema_fields_ID => 1, Pixel::schema_fields_CHANNEL_CODE => 'wch1', Pixel::schema_fields_SOURCE => ''],
            [Pixel::schema_fields_ID => 2, Pixel::schema_fields_CHANNEL_CODE => '', Pixel::schema_fields_SOURCE => 'direct', 'referer' => 'https://fb.com/a'],
            [Pixel::schema_fields_ID => 3, Pixel::schema_fields_CHANNEL_CODE => '', Pixel::schema_fields_SOURCE => '', 'referer' => ''],
        ];

        $service = new class () extends PixelCronSourceCompatService {
            /** @var array<int, array<string, mixed>> */
            public array $persisted = [];

            protected function persist(array $decision): bool
            {
                $this->persisted[] = $decision;

                return true;
            }
        };

        $stat = $service->process($rows, false);
        self::assertSame(3, $stat['total']);
        self::assertSame(3, $stat['updated'], '所有行都应被标记 cron_deal');
        self::assertSame(1, $stat['synced']);
        self::assertSame(0, $stat['legacy']);
        self::assertSame(2, $stat['skipped']);
        self::assertFalse($stat['legacy_enabled']);
        self::assertSame(['wch1', null, null], array_column($service->persisted, 'source'));
    }

    public function testLegacyConfigKeyDefaultsOff(): void
    {
        $config = new VisitorTrackingConfig();
        self::assertSame(
            'visitor/tracking/legacy_cron_source_enabled',
            VisitorTrackingConfig::CONFIG_KEY_LEGACY_CRON_SOURCE_ENABLED
        );
        self::assertFalse($config->isLegacyCronSourceEnabled(), '兼容开关默认关闭');

        $runtime = $config->getRuntimeConfig();
        self::assertArrayHasKey('legacyCronSourceEnabled', $runtime['attribution']);
        self::assertFalse($runtime['attribution']['legacyCronSourceEnabled']);
    }

    public function testCronNoLongerWritesSourceDirectly(): void
    {
        $root = BP . '/app/code/Weline/Visitor';
        $cron = (string)\file_get_contents($root . '/Cron/Pixel.php');
        self::assertStringNotContainsString('setSource(', $cron, 'Cron 不得再直接写 source');
        self::assertStringNotContainsString('referer_domain_contains', $cron, 'referer 映射已下沉到兼容服务');
        self::assertStringContainsString('PixelCronSourceCompatService', $cron);

        $tpl = (string)\file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml');
        self::assertStringContainsString('visitor/tracking/legacy_cron_source_enabled', $tpl);
    }
}
