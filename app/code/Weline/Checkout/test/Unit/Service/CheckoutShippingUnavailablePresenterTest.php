<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutShippingUnavailablePresenter;

final class CheckoutShippingUnavailablePresenterTest extends TestCase
{
    public function testMissingWeightIsExplicit(): void
    {
        $out = (new CheckoutShippingUnavailablePresenter())->present(
            ['missing_weight' => true, 'unavailable_reasons' => ['missing_weight'], 'country_code' => 'US'],
            [['requires_shipping' => true, 'weight_minor' => 0]],
            'US',
        );
        self::assertSame('missing_weight', $out['reason_code']);
        self::assertStringContainsString('暂时无法计算运费', $out['title']);
        self::assertStringContainsString('缺少重量', $out['message']);
        self::assertStringNotContainsString('拒因码', $out['message']);
        self::assertStringNotContainsString('weight_kg', $out['message']);
        self::assertStringNotContainsString('后台', $out['message']);
        self::assertStringNotContainsString('请调整收货地址或商品', $out['message']);
        self::assertStringContainsString('US', $out['message']);
    }

    public function testNoMatchedLaneIsExplicit(): void
    {
        $out = (new CheckoutShippingUnavailablePresenter())->present(
            ['unavailable_reasons' => ['no_matched_lane'], 'country_code' => 'US'],
            [],
            'US',
        );
        self::assertSame('no_matched_lane', $out['reason_code']);
        self::assertStringContainsString('暂无可用配送', $out['title']);
        self::assertStringContainsString('可送达', $out['message']);
        self::assertStringContainsString('US', $out['message']);
        self::assertStringNotContainsString('拒因码', $out['message']);
        self::assertStringNotContainsString('白名单', $out['message']);
    }
}
