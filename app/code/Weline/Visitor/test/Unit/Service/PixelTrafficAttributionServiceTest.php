<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelTrafficAttributionService;

class PixelTrafficAttributionServiceTest extends TestCore
{
    private PixelTrafficAttributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelTrafficAttributionService();
    }

    public function testWchWinsAsChannelCode(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/landing?wch=summer_ad&utm_campaign=other&utm_medium=cpc',
        ]);

        self::assertSame('summer_ad', $result['channel_code']);
        self::assertSame('paid', $result['traffic_type']);
        self::assertSame('cpc', $result['utm_medium']);
        self::assertFalse($result['from_sticky']);
    }

    public function testChannelCodeAliasSupported(): void
    {
        $result = $this->service->resolve([
            'query' => ['channel_code' => 'alias_code', 'utm_medium' => 'email'],
        ]);

        self::assertSame('alias_code', $result['channel_code']);
        self::assertSame('email', $result['traffic_type']);
    }

    public function testUtmCampaignUsedWhenNoWch(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/?utm_source=newsletter&utm_medium=email&utm_campaign=spring',
        ]);

        self::assertSame('spring', $result['channel_code']);
        self::assertSame('email', $result['traffic_type']);
        self::assertSame('newsletter', $result['utm_source']);
    }

    public function testGclidMapsToGoogleAdsPaid(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/?gclid=abc123',
        ]);

        self::assertSame('google_ads', $result['channel_code']);
        self::assertSame('paid', $result['traffic_type']);
        self::assertSame('abc123', $result['gclid']);
    }

    public function testFbclidMapsToMetaAdsPaid(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/?fbclid=xyz',
        ]);

        self::assertSame('meta_ads', $result['channel_code']);
        self::assertSame('paid', $result['traffic_type']);
    }

    public function testMsclkidMapsToMicrosoftAdsPaid(): void
    {
        $result = $this->service->resolve([
            'query' => ['msclkid' => 'm1'],
        ]);

        self::assertSame('microsoft_ads', $result['channel_code']);
        self::assertSame('paid', $result['traffic_type']);
    }

    public function testPaidMediumWithoutClickId(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/?utm_source=bing&utm_medium=cpc&utm_campaign=brand',
        ]);

        self::assertSame('brand', $result['channel_code']);
        self::assertSame('paid', $result['traffic_type']);
    }

    public function testSocialRefererWithoutUtm(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/about',
            'referer' => 'https://m.facebook.com/l.php?u=1',
        ]);

        self::assertSame('', $result['channel_code']);
        self::assertSame('social', $result['traffic_type']);
        self::assertSame('m.facebook.com', $result['referer_host']);
    }

    public function testGenericRefererIsReferral(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/',
            'referer' => 'https://news.example.org/story',
        ]);

        self::assertSame('referral', $result['traffic_type']);
    }

    public function testDirectWhenNoSignals(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/',
        ]);

        self::assertSame('', $result['channel_code']);
        self::assertSame('direct', $result['traffic_type']);
    }

    public function testStickyOverridesConflictingUrlUtm(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/?wch=second&utm_medium=cpc',
            'sticky' => [
                'wch' => 'first',
                'utm_medium' => 'email',
                'utm_campaign' => 'locked',
                'locked_at' => 1,
            ],
        ]);

        self::assertTrue($result['from_sticky']);
        self::assertSame('first', $result['channel_code']);
        self::assertSame('email', $result['traffic_type']);
        self::assertSame('locked', $result['utm_campaign']);
    }

    public function testCustomTypeWhenOnlyWchWithoutMedium(): void
    {
        $result = $this->service->resolve([
            'url' => 'https://example.test/?wch=my_ops',
        ]);

        self::assertSame('my_ops', $result['channel_code']);
        self::assertSame('custom', $result['traffic_type']);
    }
}
