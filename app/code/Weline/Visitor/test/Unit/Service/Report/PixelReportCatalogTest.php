<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service\Report;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Weline\Visitor\Service\Report\PixelReportCatalog;

/**
 * D05：report_catalog 可加载单测（不查库）。
 */
final class PixelReportCatalogTest extends TestCase
{
    public function testLoadsDefaultCatalogWithExpectedCodes(): void
    {
        $catalog = new PixelReportCatalog();
        $catalog->assertConsistent();

        self::assertSame('1.0.0', $catalog->getVersion());
        self::assertSame([
            'pixel_channels',
            'pixel_traffic_type',
            'pixel_paid',
            'pixel_social',
            'pixel_event_value',
            'pixel_value_by_channel',
        ], $catalog->codes());

        $channels = $catalog->require('pixel_channels');
        self::assertSame('channel_code', $channels['dimension']);
        self::assertContains('events', $channels['metrics']);
        self::assertContains('value_sum', $channels['metrics']);
        self::assertSame('pixel_channels', $channels['widget_code']);
        self::assertTrue($channels['enabled']);
    }

    public function testPaidAndSocialCarryTrafficFilters(): void
    {
        $catalog = new PixelReportCatalog();

        $paid = $catalog->require('pixel_paid');
        self::assertSame('utm_campaign', $paid['dimension']);
        self::assertSame('paid', $paid['filters']['traffic_type']);

        $social = $catalog->require('pixel_social');
        self::assertSame('channel_code', $social['dimension']);
        self::assertSame('social', $social['filters']['traffic_type']);
    }

    public function testRequireUnknownCodeFails(): void
    {
        $catalog = new PixelReportCatalog();
        $this->expectException(InvalidArgumentException::class);
        $catalog->require('no_such_report');
    }

    public function testDisabledReportsHiddenFromEnabledLookup(): void
    {
        $path = sys_get_temp_dir() . '/pixel_report_catalog_' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode([
            'version' => '9.9.9',
            'reports' => [
                [
                    'code' => 'pixel_channels',
                    'label' => 'Channels',
                    'dimension' => 'channel_code',
                    'metrics' => ['events'],
                    'enabled' => false,
                ],
                [
                    'code' => 'pixel_traffic_type',
                    'label' => 'Types',
                    'dimension' => 'traffic_type',
                    'metrics' => ['events'],
                    'enabled' => true,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $catalog = new PixelReportCatalog(catalogPath: $path);
            self::assertSame('9.9.9', $catalog->getVersion());
            self::assertFalse($catalog->has('pixel_channels'));
            self::assertTrue($catalog->has('pixel_channels', false));
            self::assertSame(['pixel_traffic_type'], $catalog->codes());
            $catalog->assertConsistent();
        } finally {
            @unlink($path);
        }
    }

    public function testAssertConsistentRejectsUnknownDimension(): void
    {
        $path = sys_get_temp_dir() . '/pixel_report_catalog_bad_' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode([
            'version' => '0.0.1',
            'reports' => [[
                'code' => 'bad',
                'dimension' => 'not_a_real_dim',
                'metrics' => ['events'],
            ]],
        ]));

        try {
            $catalog = new PixelReportCatalog(catalogPath: $path);
            $this->expectException(InvalidArgumentException::class);
            $catalog->assertConsistent();
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidJsonThrows(): void
    {
        $path = sys_get_temp_dir() . '/pixel_report_catalog_invalid_' . uniqid('', true) . '.json';
        file_put_contents($path, '{not-json');

        try {
            $catalog = new PixelReportCatalog(catalogPath: $path);
            $this->expectException(RuntimeException::class);
            $catalog->getVersion();
        } finally {
            @unlink($path);
        }
    }
}
