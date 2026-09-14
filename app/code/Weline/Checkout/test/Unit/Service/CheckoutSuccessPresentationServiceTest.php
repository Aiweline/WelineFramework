<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutSuccessPresentationService;
use Weline\Order\Api\OrderCatalogImageResolverInterface;
use Weline\Order\Api\OrderShippingMethodCatalogInterface;

final class CheckoutSuccessPresentationServiceTest extends TestCase
{
    public function testPresentHydratesProductImagesAndShippingLabel(): void
    {
        $images = new class implements OrderCatalogImageResolverInterface {
            public function resolveReference(string $reference, int $websiteId = 0, int $storeId = 0): string
            {
                return $reference === '/media/snap.jpg' ? 'https://cdn.example/snap.jpg' : '';
            }

            public function resolveProductMainImages(int $websiteId, array $productIds, int $storeId = 0): array
            {
                return [42 => 'https://cdn.example/p42.jpg'];
            }
        };
        $shipping = new class implements OrderShippingMethodCatalogInterface {
            public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
            {
                return [['code' => 'SEED_LANE_AMERICAS', 'label' => '美洲']];
            }

            public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
            {
                return strtoupper(trim($code)) === 'SEED_LANE_AMERICAS' ? '美洲' : $code;
            }
        };

        $service = new CheckoutSuccessPresentationService($images, $shipping);
        $result = $service->present(
            items: [
                ['product_id' => 7, 'name' => '有快照图', 'image' => '/media/snap.jpg', 'qty' => 1],
                ['product_id' => 42, 'name' => '无快照图', 'qty' => 2],
            ],
            shippingMethodCode: 'SEED_LANE_AMERICAS',
            websiteId: 1,
            storeId: 1,
        );

        self::assertSame('美洲', $result['shipping_method_label']);
        self::assertSame('https://cdn.example/snap.jpg', $result['items_display'][0]['image_src']);
        self::assertSame('https://cdn.example/p42.jpg', $result['items_display'][1]['image_src']);
        self::assertNotSame('', (string)($result['items_display'][0]['image_fallback'] ?? ''));
    }

    public function testPresentFallsBackToCodeWhenShippingLabelEmpty(): void
    {
        $shipping = new class implements OrderShippingMethodCatalogInterface {
            public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
            {
                return [];
            }

            public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
            {
                return '';
            }
        };
        $service = new CheckoutSuccessPresentationService(null, $shipping);
        $result = $service->present(
            items: [['product_id' => 0, 'name' => '无图']],
            shippingMethodCode: 'SEED_LANE_AMERICAS',
            websiteId: 0,
            storeId: 0,
        );

        self::assertSame('SEED_LANE_AMERICAS', $result['shipping_method_label']);
        self::assertNotSame('', (string)($result['items_display'][0]['image_src'] ?? ''));
    }
}
