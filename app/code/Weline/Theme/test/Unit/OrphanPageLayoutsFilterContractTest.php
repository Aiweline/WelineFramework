<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SlotRendererService;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';

/**
 * 孤儿检测须按 page_layouts 排除非当前 pageType 的专有部件（如 mini-cart footer-extras）。
 */
final class OrphanPageLayoutsFilterContractTest extends TestCase
{
    public function testHomepageSkipsMiniCartOnlyOrphans(): void
    {
        $service = $this->newServiceWithRegistry($this->stubRegistry([
            'Weline_Marketing::content::mini-cart-coupon' => new ThemeComponentDefinition(
                module: 'Weline_Marketing',
                type: 'content',
                code: 'mini-cart-coupon',
                name: '迷你购物车优惠券',
                pageLayouts: ['mini-cart'],
                supports: ['layout-mini-cart-footer-extras', 'mini-cart-coupon'],
            ),
            'Weline_Order::form::order-notice' => new ThemeComponentDefinition(
                module: 'Weline_Order',
                type: 'form',
                code: 'order-notice',
                name: '订单留言',
                pageLayouts: ['mini-cart'],
                supports: ['layout-mini-cart-footer-extras', 'order-notice'],
            ),
            'Weline_Theme::content::hero' => new ThemeComponentDefinition(
                module: 'Weline_Theme',
                type: 'content',
                code: 'hero',
                name: '英雄区',
                pageLayouts: ['*'],
            ),
        ]));

        $detect = new ReflectionMethod(SlotRendererService::class, 'detectOrphanWidgets');
        $detect->setAccessible(true);
        $detect->invoke($service, [
            'footer-extras' => [
                [
                    'widget_module' => 'Weline_Marketing',
                    'widget_type' => 'content',
                    'widget_code' => 'mini-cart-coupon',
                    'meta' => ['name' => '迷你购物车优惠券'],
                ],
                [
                    'widget_module' => 'Weline_Order',
                    'widget_type' => 'form',
                    'widget_code' => 'order-notice',
                    'meta' => ['name' => '订单留言'],
                ],
            ],
            'hero' => [
                [
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'content',
                    'widget_code' => 'hero',
                    'meta' => ['name' => '英雄区'],
                ],
            ],
        ], [], 'homepage');

        $orphans = $service->getOrphanWidgets();
        self::assertCount(1, $orphans);
        self::assertSame('hero', $orphans[0]['slot_id'] ?? null);
        self::assertSame('hero', $orphans[0]['widget_code'] ?? null);
    }

    public function testMiniCartStillReportsMissingFooterExtras(): void
    {
        $service = $this->newServiceWithRegistry($this->stubRegistry([
            'Weline_Marketing::content::mini-cart-coupon' => new ThemeComponentDefinition(
                module: 'Weline_Marketing',
                type: 'content',
                code: 'mini-cart-coupon',
                name: '迷你购物车优惠券',
                pageLayouts: ['mini-cart'],
                supports: ['layout-mini-cart-footer-extras'],
            ),
        ]));

        $detect = new ReflectionMethod(SlotRendererService::class, 'detectOrphanWidgets');
        $detect->setAccessible(true);
        $detect->invoke($service, [
            'footer-extras' => [
                [
                    'widget_module' => 'Weline_Marketing',
                    'widget_type' => 'content',
                    'widget_code' => 'mini-cart-coupon',
                    'meta' => ['name' => '迷你购物车优惠券'],
                ],
            ],
        ], [], 'mini-cart');

        $orphans = $service->getOrphanWidgets();
        self::assertCount(1, $orphans);
        self::assertSame('footer-extras', $orphans[0]['slot_id'] ?? null);
        self::assertSame('mini-cart-coupon', $orphans[0]['widget_code'] ?? null);
    }

    /**
     * @param array<string, ThemeComponentDefinition> $map
     */
    private function stubRegistry(array $map): ThemePlaceableRegistryInterface
    {
        return new class ($map) implements ThemePlaceableRegistryInterface {
            /** @param array<string, ThemeComponentDefinition> $map */
            public function __construct(private array $map)
            {
            }

            public function getAvailableList(?string $pageType = null, ?array $filterOptions = null, ?WelineTheme $theme = null, string $area = 'frontend'): array
            {
                return [];
            }

            public function find(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
            {
                return $this->map[$module . '::' . $type . '::' . $code] ?? null;
            }

            public function getParamDefinitions(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): array
            {
                return [];
            }

            public function renderPreview(string $module, string $type, string $code, array $config = [], ?WelineTheme $theme = null, string $area = 'frontend'): string
            {
                return '';
            }
        };
    }

    private function newServiceWithRegistry(ThemePlaceableRegistryInterface $registry): SlotRendererService
    {
        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(SlotRendererService::class, 'placeableRegistry');
        $prop->setAccessible(true);
        $prop->setValue($service, $registry);

        $area = new ReflectionProperty(SlotRendererService::class, 'renderArea');
        $area->setAccessible(true);
        $area->setValue($service, 'frontend');

        $theme = new ReflectionProperty(SlotRendererService::class, 'renderTheme');
        $theme->setAccessible(true);
        $theme->setValue($service, null);

        $orphans = new ReflectionProperty(SlotRendererService::class, 'orphanWidgets');
        $orphans->setAccessible(true);
        $orphans->setValue($service, []);

        return $service;
    }
}
