<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionPlanner;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionSlotInventory;

final class RequiredDefaultInjectionInventoryPlannerTest extends TestCase
{
    public function testInventoryClosesNestedSlotsUnderRequiredContainer(): void
    {
        $inventory = RequiredDefaultInjectionSlotInventory::build($this->productDeclarations(), 'product');

        self::assertArrayHasKey('product-main', $inventory['slots']);
        self::assertArrayHasKey('product-purchase-actions', $inventory['slots']);
        self::assertArrayHasKey('product-express-payment', $inventory['slots']);
        self::assertSame(0, $inventory['slots']['product-main']['depth']);
        self::assertSame(1, $inventory['slots']['product-purchase-actions']['depth']);
        self::assertSame('product-info', $inventory['slots']['product-purchase-actions']['parent_widget']);
        self::assertNotSame([], $inventory['edges']);
    }

    public function testPlannerOrdersContainerBeforeNestedTargets(): void
    {
        $declarations = $this->productDeclarations();
        $inventory = RequiredDefaultInjectionSlotInventory::build($declarations, 'product');
        $plan = RequiredDefaultInjectionPlanner::plan($declarations, 'product', [], $inventory);

        $codes = \array_column($plan, 'widget_code');
        $infoPos = \array_search('product-info', $codes, true);
        $addPos = \array_search('product-add-to-cart', $codes, true);
        self::assertNotFalse($infoPos);
        self::assertNotFalse($addPos);
        self::assertLessThan($addPos, $infoPos);

        foreach ($plan as $item) {
            if ($item['widget_code'] === 'product-info') {
                self::assertSame(0, $item['depth']);
            }
            if ($item['widget_code'] === 'product-add-to-cart') {
                self::assertSame(1, $item['depth']);
            }
        }
    }

    public function testPlannerSkipsUninstalled(): void
    {
        $declarations = $this->productDeclarations();
        $inventory = RequiredDefaultInjectionSlotInventory::build($declarations, 'product');
        $plan = RequiredDefaultInjectionPlanner::plan($declarations, 'product', [[
            'slot_id' => 'product-purchase-actions',
            'widget_module' => 'Weline_Cart',
            'widget_code' => 'product-add-to-cart',
        ]], $inventory);

        $codes = \array_column($plan, 'widget_code');
        self::assertNotContains('product-add-to-cart', $codes);
        self::assertContains('product-info', $codes);
    }

    public function testOverlayUsesInventoryPlannerNotDiscoveryLoop(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        self::assertStringContainsString('RequiredDefaultInjectionSlotInventory::build', $src);
        self::assertStringContainsString('RequiredDefaultInjectionPlanner::plan', $src);
        self::assertStringContainsString('One execute wave per depth', $src);
        self::assertStringNotContainsString('while ($pass < 16)', $src);
        self::assertStringNotContainsString('Multi-pass: parent container', $src);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productDeclarations(): array
    {
        return [
            [
                'module' => 'Weline_Product',
                'type' => 'product',
                'code' => 'product-info',
                'slots' => [
                    'product-purchase-actions' => ['name' => '购买操作'],
                    'product-express-payment' => ['name' => '快捷支付'],
                ],
                'default_injections' => [[
                    'layout_type' => 'product',
                    'layout_option' => 'default',
                    'slot' => 'product-main',
                    'area' => 'content',
                    'sort_order' => 0,
                    'required' => true,
                ]],
            ],
            [
                'module' => 'Weline_Cart',
                'type' => 'product',
                'code' => 'product-add-to-cart',
                'slots' => [],
                'default_injections' => [[
                    'layout_type' => 'product',
                    'layout_option' => 'default',
                    'slot' => 'product-purchase-actions',
                    'area' => 'content',
                    'sort_order' => 0,
                    'required' => true,
                ]],
            ],
            [
                'module' => 'Weline_Checkout',
                'type' => 'product',
                'code' => 'product-buy-now',
                'slots' => [],
                'default_injections' => [[
                    'layout_type' => 'product',
                    'layout_option' => 'default',
                    'slot' => 'product-purchase-actions',
                    'area' => 'content',
                    'sort_order' => 10,
                    'required' => true,
                ]],
            ],
        ];
    }
}
