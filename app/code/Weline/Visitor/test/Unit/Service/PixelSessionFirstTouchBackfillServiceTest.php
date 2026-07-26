<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelSessionFirstTouchBackfillService;

/**
 * A04b：同会话首触回填纯逻辑（不依赖 session_id 列已落库）。
 */
class PixelSessionFirstTouchBackfillServiceTest extends TestCore
{
    public function testLacksMarketingSignals(): void
    {
        $service = new PixelSessionFirstTouchBackfillService();
        self::assertTrue($service->lacksMarketingSignals([
            'channel_code' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'traffic_type' => 'direct',
        ]));
        self::assertFalse($service->lacksMarketingSignals([
            'channel_code' => 'summer',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
        ]));
        self::assertFalse($service->lacksMarketingSignals([
            'channel_code' => '',
            'utm_source' => 'google',
            'utm_medium' => '',
            'utm_campaign' => '',
        ]));
    }

    public function testApplyFirstTouchCopiesWhenCurrentEmpty(): void
    {
        $service = new PixelSessionFirstTouchBackfillService();
        $merged = $service->applyFirstTouch(
            [
                'website_id' => 1,
                'session_id' => 's1',
                'channel_code' => '',
                'channel_name' => '',
                'traffic_type' => 'direct',
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
            ],
            [
                'channel_code' => 'summer_ad',
                'channel_name' => '',
                'traffic_type' => 'paid',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'summer',
            ]
        );

        self::assertSame('summer_ad', $merged['channel_code']);
        self::assertSame('paid', $merged['traffic_type']);
        self::assertSame('google', $merged['utm_source']);
        self::assertSame('cpc', $merged['utm_medium']);
        self::assertSame('summer', $merged['utm_campaign']);
    }

    public function testApplyFirstTouchKeepsCurrentMarketing(): void
    {
        $service = new PixelSessionFirstTouchBackfillService();
        $merged = $service->applyFirstTouch(
            [
                'channel_code' => 'current',
                'channel_name' => '',
                'traffic_type' => 'email',
                'utm_source' => 'newsletter',
                'utm_medium' => 'email',
                'utm_campaign' => 'welcome',
            ],
            [
                'channel_code' => 'first',
                'traffic_type' => 'paid',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'summer',
            ]
        );

        self::assertSame('current', $merged['channel_code']);
        self::assertSame('email', $merged['traffic_type']);
        self::assertSame('newsletter', $merged['utm_source']);
    }

    public function testApplyFirstTouchIgnoresEmptyFirst(): void
    {
        $service = new PixelSessionFirstTouchBackfillService();
        $merged = $service->applyFirstTouch(
            [
                'channel_code' => '',
                'traffic_type' => 'referral',
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
            ],
            [
                'channel_code' => '',
                'traffic_type' => 'direct',
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
            ]
        );

        self::assertSame('referral', $merged['traffic_type']);
        self::assertSame('', $merged['channel_code']);
    }

    public function testBackfillNoopsWhenColumnsMissing(): void
    {
        $service = new PixelSessionFirstTouchBackfillService();
        $data = [
            'website_id' => 930405,
            'session_id' => 'wps-a04b-missing-cols',
            'channel_code' => '',
            'channel_name' => '',
            'traffic_type' => 'direct',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
        ];

        $result = $service->backfill($data);
        self::assertSame($data, $result);
    }
}
