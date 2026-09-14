<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\OrderPaymentMethodCatalogInterface;
use Weline\Order\Api\OrderShippingMethodCatalogInterface;
use Weline\Order\Service\BackendOrderPaymentChromePresenter;

final class BackendOrderPaymentChromePresenterTest extends TestCase
{
    public function testEmptyPaymentMethodSummaryShowsUnspecifiedAndStatus(): void
    {
        $catalog = new class implements OrderPaymentMethodCatalogInterface {
            public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
            {
                return [['code' => 'fake_card', 'label' => '本地测试支付']];
            }

            public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
            {
                return $code === 'fake_card' ? '本地测试支付' : $code;
            }
        };
        $shippingCatalog = new class implements OrderShippingMethodCatalogInterface {
            public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
            {
                return [['code' => 'SEED_LANE_DOMESTIC', 'label' => '国内标快']];
            }

            public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
            {
                return strtoupper($code) === 'SEED_LANE_DOMESTIC' ? '国内标快' : $code;
            }
        };
        $presenter = new BackendOrderPaymentChromePresenter($catalog, $shippingCatalog);
        $chrome = $presenter->present([
            'payment_method' => '',
            'payment_status' => 'partial',
            'shipping_method' => 'SEED_LANE_DOMESTIC',
            'shipping_amount' => 0,
            'currency' => 'CNY',
            'type_payload_json' => json_encode(['hang_status' => 'awaiting_merchant_approval'], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame('', $chrome['payment_method']);
        self::assertStringContainsString('未指定', $chrome['payment_summary']);
        self::assertStringContainsString('部分支付', $chrome['payment_summary']);
        self::assertNotSame('', trim($chrome['payment_summary']));
        self::assertNotSame('', trim($chrome['payment_hint']));
        self::assertSame('国内标快', $chrome['shipping_method_label']);
        self::assertStringContainsString('国内标快', $chrome['shipping_summary']);
        self::assertStringContainsString('运费', $chrome['shipping_summary']);
        self::assertStringNotContainsString('SEED_LANE_DOMESTIC', $chrome['shipping_summary']);
        self::assertSame('fake_card', $chrome['payment_options'][0]['code']);
    }

    public function testKnownPaymentMethodUsesCatalogLabel(): void
    {
        $catalog = new class implements OrderPaymentMethodCatalogInterface {
            public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
            {
                return [['code' => 'paypal', 'label' => 'PayPal']];
            }

            public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
            {
                return $code === 'paypal' ? 'PayPal' : $code;
            }
        };
        $chrome = (new BackendOrderPaymentChromePresenter($catalog))->present([
            'payment_method' => 'paypal',
            'payment_status' => 'paid',
            'shipping_method' => '',
        ]);

        self::assertSame('PayPal', $chrome['payment_method_label']);
        self::assertStringContainsString('PayPal', $chrome['payment_summary']);
        self::assertStringContainsString('已支付', $chrome['payment_summary']);
        self::assertStringContainsString('未指定配送方式', $chrome['shipping_summary']);
    }
}
