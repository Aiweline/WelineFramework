<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelCreateService;
use Weline\Visitor\Service\PixelChannelValidationService;

/**
 * B04：新建 campaign 自动 UTM 包 + 组装/校验（不依赖表已落库）。
 */
class PixelChannelCreateServiceTest extends TestCore
{
    private PixelChannelCreateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelCreateService(new PixelChannelValidationService());
    }

    public function testBuildUtmPackageDefaults(): void
    {
        $pack = $this->service->buildUtmPackage('summer_ad', PixelChannel::TRAFFIC_PAID);
        self::assertSame([
            'utm_source' => 'weline',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer_ad',
            'wch' => 'summer_ad',
        ], $pack);

        self::assertSame('social', $this->service->defaultMediumForTrafficType(PixelChannel::TRAFFIC_SOCIAL));
        self::assertSame('email', $this->service->defaultMediumForTrafficType(PixelChannel::TRAFFIC_EMAIL));
        self::assertSame('none', $this->service->defaultMediumForTrafficType(PixelChannel::TRAFFIC_DIRECT));
    }

    public function testBuildUtmPackageAllowsOverrideSourceAndMedium(): void
    {
        $pack = $this->service->buildUtmPackage('x', PixelChannel::TRAFFIC_PAID, 'google', 'paid');
        self::assertSame('google', $pack['utm_source']);
        self::assertSame('paid', $pack['utm_medium']);
        self::assertSame('x', $pack['utm_campaign']);
        self::assertSame('x', $pack['wch']);
    }

    public function testAssembleCampaignRowFillsUtmAndKind(): void
    {
        $row = $this->service->assembleCampaignRow([
            'code' => 'Summer_Ad',
            'name' => '夏季',
            'traffic_type' => PixelChannel::TRAFFIC_SOCIAL,
            'website_id' => 3,
        ]);
        self::assertSame(PixelChannel::KIND_CAMPAIGN, $row['kind']);
        self::assertSame('summer_ad', $row['code']); // 小写规范化
        self::assertSame('夏季', $row['name']);
        self::assertSame('weline', $row['utm_source']);
        self::assertSame('social', $row['utm_medium']);
        self::assertSame('summer_ad', $row['utm_campaign']);
        self::assertSame('summer_ad', $row['_wch']);
        self::assertSame(3, $row['website_id']);
        self::assertSame(1, $row['enabled']);
        self::assertNull($row['match_mode']);
    }

    public function testCreateCampaignRejectsInvalidWithoutTouchingDb(): void
    {
        $result = $this->service->createCampaign([
            'code' => 'BAD CODE',
            'name' => 'n',
        ], static fn() => false);
        self::assertFalse($result['ok']);
        self::assertNotSame([], $result['errors']);
        self::assertNull($result['id']);
    }

    public function testCreateCampaignRejectsConflictViaInjectedChecker(): void
    {
        $result = $this->service->createCampaign([
            'code' => 'summer',
            'name' => 'n',
            'website_id' => 1,
        ], static fn() => true);
        self::assertFalse($result['ok']);
        self::assertCount(1, $result['errors']);
    }

    public function testCreateCampaignPersistsWhenTableReadyOtherwiseSurfacesError(): void
    {
        $code = 'b04_' . \substr(\md5((string)\microtime(true)), 0, 8);
        $result = $this->service->createCampaign([
            'code' => $code,
            'name' => 'B04 测试渠道',
            'traffic_type' => PixelChannel::TRAFFIC_PAID,
            'website_id' => 0,
        ], static fn() => false);

        if ($result['ok']) {
            self::assertGreaterThan(0, (int)$result['id']);
            self::assertSame($code, $result['row']['code']);
            self::assertSame('cpc', $result['row']['utm_medium']);
            // 清理
            try {
                w_obj(PixelChannel::class)->load((int)$result['id'])->delete();
            } catch (\Throwable) {
            }
            return;
        }

        // 表未 upgrade 时允许失败，但必须是保存错误而非校验错误，且 row 已正确组装
        self::assertSame($code, $result['row']['code']);
        self::assertSame('cpc', $result['row']['utm_medium']);
        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('保存渠道失败', $result['errors'][0]);
    }
}
