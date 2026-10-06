<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
final class ThemeLayoutEntityBakeCoordinatorContractTest extends TestCase
{
    public function testResourceReceiptRequiresRealOutputAndUsesContentDigest(): void
    {
        $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($coordinator, 'resourceArtifactReceipt');
        $path = tempnam(sys_get_temp_dir(), 'theme-resource-receipt-');
        try {
            file_put_contents($path, '<section>solidified</section>');
            $receipt = $method->invoke($coordinator, 'page', $path);
            self::assertTrue($receipt['exists']);
            self::assertSame('page', $receipt['type']);
            self::assertSame(hash_file('sha256', $path), $receipt['artifact_id']);
            self::assertArrayNotHasKey('path', $receipt);
            unlink($path);
            $this->expectException(\RuntimeException::class);
            $method->invoke($coordinator, 'page', $path);
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }
    public function testConfigurationCommandsDoNotRequireStructureGeneration(): void
    {
        $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
        self::assertFalse($coordinator->commandsAreStructural([
            ['op' => 'SET', 'path' => '/nodes/example/config/title', 'value' => 'updated'],
        ]));
        foreach (['slot_id', 'sort_order', 'is_active', 'area'] as $field) {
            self::assertTrue($coordinator->commandsAreStructural([
                ['op' => 'SET', 'path' => '/nodes/example/' . $field, 'value' => 'changed'],
            ]));
        }
        foreach (['ADD_NODE', 'REMOVE_NODE', 'MOVE_NODE'] as $op) {
            self::assertTrue($coordinator->commandsAreStructural([['op' => $op]]));
        }
    }

    public function testChromeBakeMergesMiniCartFooterExtrasAndKeepsMiniCartPageExtras(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("'mini-cart'", $source);
        self::assertStringContainsString('attachHeaderNativeMiniCartOwners', $source);
        self::assertStringContainsString('contentNodesForLayout', $source);
        self::assertStringContainsString('footer-extras', $source);
        self::assertMatchesRegularExpression(
            "/mergeIntoNodes\\(\\s*\\\$nodes,\\s*\\\$version->getThemeId\\(\\),\\s*'mini-cart'/s",
            $source,
        );

        $coordinator = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
        $content = new \ReflectionMethod($coordinator, 'contentNodesForLayout');
        $content->setAccessible(true);
        $nodes = [
            'coupon' => [
                'node_uid' => 'coupon',
                'widget_code' => 'mini-cart-coupon',
                'slot_id' => 'footer-extras',
                'area' => 'footer',
            ],
            'page' => [
                'node_uid' => 'page',
                'widget_code' => 'hero',
                'slot_id' => 'content',
                'area' => 'content',
            ],
        ];
        $miniCart = $content->invoke($coordinator, 'mini-cart', $nodes);
        self::assertArrayHasKey('coupon', $miniCart);
        self::assertArrayHasKey('page', $miniCart);
        $homepage = $content->invoke($coordinator, 'homepage', $nodes);
        self::assertArrayNotHasKey('coupon', $homepage);
        self::assertArrayHasKey('page', $homepage);
    }

    public function testRebakeDedupesLayoutTargetsAndSkipsPageDependencies(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('function uniqueLayoutTargets', $source);
        self::assertStringContainsString('page_dependency', $source);
        self::assertStringContainsString('$expandCatalog = false', $source);
        self::assertStringContainsString('function generatedPageMatchesInputs', $source);
        self::assertStringContainsString('function scopeOwnsSolidify', $source);
        self::assertStringContainsString('function hasOwnThemeApplication', $source);
        self::assertStringContainsString('$includeSelectedDraft = true', $source);
        $upgrade = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
        self::assertStringContainsString('rebakeAfterInjectionCollect(null, [], $this->progress(...), false)', (string)file_get_contents($upgrade));
        $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
        $unique = new \ReflectionMethod($coordinator, 'uniqueLayoutTargets');
        $out = $unique->invoke($coordinator, [
            ['layout_type' => 'checkout', 'layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0],
            ['layout_type' => 'checkout', 'layout_option' => 'default', 'resource_type' => 'page_dependency', 'target_id' => null],
            ['layout_type' => 'cart', 'layout_option' => 'default'],
        ]);
        self::assertCount(2, $out);
        self::assertSame('checkout', $out[0]['layout_type']);
        self::assertSame('cart', $out[1]['layout_type']);
    }
}
