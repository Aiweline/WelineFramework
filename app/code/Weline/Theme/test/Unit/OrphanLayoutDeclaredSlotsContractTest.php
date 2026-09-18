<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeResourceCatalog;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';

/**
 * 布局源码已声明、但 meta 门控未写入 HTML 的插槽不得误报孤儿
 * （例：product showRelatedProducts=false → product-related-products / product-cross-sell）。
 */
final class OrphanLayoutDeclaredSlotsContractTest extends TestCase
{
    public function testProductLayoutSourceDeclaresRelatedAndCrossSellSlots(): void
    {
        /** @var ThemeResourceCatalog $catalog */
        $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
        $theme = $this->loadHanfuTheme();
        $resource = $catalog->getLayoutResource('frontend', $theme, 'product', 'default');
        self::assertNotNull($resource);

        $slotIds = [];
        foreach ($resource['slots'] ?? [] as $slot) {
            if (\is_object($slot) && isset($slot->id)) {
                $slotIds[(string)$slot->id] = true;
            } elseif (\is_array($slot) && !empty($slot['id'])) {
                $slotIds[(string)$slot['id']] = true;
            }
        }

        self::assertArrayHasKey('product-related-products', $slotIds);
        self::assertArrayHasKey('product-cross-sell', $slotIds);
    }

    public function testExpandSlotIdsMergesLayoutDeclaredSlotsEvenWhenHtmlOmitsThem(): void
    {
        $service = ObjectManager::getInstance(SlotRendererService::class);
        $theme = $this->loadHanfuTheme();

        $renderTheme = new ReflectionProperty(SlotRendererService::class, 'renderTheme');
        $renderTheme->setAccessible(true);
        $renderTheme->setValue($service, $theme);

        $renderArea = new ReflectionProperty(SlotRendererService::class, 'renderArea');
        $renderArea->setAccessible(true);
        $renderArea->setValue($service, 'frontend');

        $expand = new ReflectionMethod(SlotRendererService::class, 'expandSlotIdsWithLayoutDeclaredSlots');
        $expand->setAccessible(true);
        /** @var array<string, true> $merged */
        $merged = $expand->invoke($service, 'product', ['product-main' => true]);

        self::assertArrayHasKey('product-main', $merged);
        self::assertArrayHasKey('product-related-products', $merged);
        self::assertArrayHasKey('product-cross-sell', $merged);
        self::assertArrayHasKey('product-bestsellers', $merged);
    }

    public function testDetectOrphanSkipsWidgetsWhenSlotDeclaredInLayoutSource(): void
    {
        $service = ObjectManager::getInstance(SlotRendererService::class);
        $theme = $this->loadHanfuTheme();

        $renderTheme = new ReflectionProperty(SlotRendererService::class, 'renderTheme');
        $renderTheme->setAccessible(true);
        $renderTheme->setValue($service, $theme);

        $renderArea = new ReflectionProperty(SlotRendererService::class, 'renderArea');
        $renderArea->setAccessible(true);
        $renderArea->setValue($service, 'frontend');

        $expand = new ReflectionMethod(SlotRendererService::class, 'expandSlotIdsWithLayoutDeclaredSlots');
        $expand->setAccessible(true);
        /** @var array<string, true> $existing */
        $existing = $expand->invoke($service, 'product', []);

        $detect = new ReflectionMethod(SlotRendererService::class, 'detectOrphanWidgets');
        $detect->setAccessible(true);
        $detect->invoke($service, [
            'product-related-products' => [[
                'widget_module' => 'Weline_Product',
                'widget_type' => 'content',
                'widget_code' => 'related-products',
                'meta' => ['name' => '相关产品'],
            ]],
            'product-cross-sell' => [[
                'widget_module' => 'Weline_Product',
                'widget_type' => 'content',
                'widget_code' => 'cross-sell',
                'meta' => ['name' => '经常一起购买'],
            ]],
            'truly-missing-slot' => [[
                'widget_module' => 'Weline_Theme',
                'widget_type' => 'content',
                'widget_code' => 'hero',
                'meta' => ['name' => '英雄区'],
            ]],
        ], $existing, 'product');

        $orphans = $service->getOrphanWidgets();
        $orphanSlots = \array_map(static fn(array $row): string => (string)($row['slot_id'] ?? ''), $orphans);

        self::assertNotContains('product-related-products', $orphanSlots);
        self::assertNotContains('product-cross-sell', $orphanSlots);
        self::assertContains('truly-missing-slot', $orphanSlots);
    }

    public function testExpandMergesProductLayoutWhenPageTypeWrongButProductWidgetsPresent(): void
    {
        $service = ObjectManager::getInstance(SlotRendererService::class);
        $theme = $this->loadHanfuTheme();

        $renderTheme = new ReflectionProperty(SlotRendererService::class, 'renderTheme');
        $renderTheme->setAccessible(true);
        $renderTheme->setValue($service, $theme);

        $renderArea = new ReflectionProperty(SlotRendererService::class, 'renderArea');
        $renderArea->setAccessible(true);
        $renderArea->setValue($service, 'frontend');

        $expand = new ReflectionMethod(SlotRendererService::class, 'expandSlotIdsWithLayoutDeclaredSlots');
        $expand->setAccessible(true);
        $widgets = [
            'product-related-products' => [[
                'widget_module' => 'Weline_Product',
                'widget_type' => 'content',
                'widget_code' => 'related-products',
            ]],
            'product-cross-sell' => [[
                'widget_module' => 'Weline_Product',
                'widget_type' => 'content',
                'widget_code' => 'cross-sell',
            ]],
        ];
        /** @var array<string, true> $merged */
        $merged = $expand->invoke($service, 'default', ['content' => true], $widgets);

        self::assertArrayHasKey('product-related-products', $merged);
        self::assertArrayHasKey('product-cross-sell', $merged);

        $detect = new ReflectionMethod(SlotRendererService::class, 'detectOrphanWidgets');
        $detect->setAccessible(true);
        $detect->invoke($service, $widgets, $merged, 'default');
        $orphanSlots = \array_map(
            static fn(array $row): string => (string)($row['slot_id'] ?? ''),
            $service->getOrphanWidgets(),
        );
        self::assertNotContains('product-related-products', $orphanSlots);
        self::assertNotContains('product-cross-sell', $orphanSlots);
    }

    private function loadHanfuTheme(): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load(3);
        if (!(int)$theme->getId()) {
            $theme->clearData()->clearQuery()->where('path', 'hanfu')->find()->fetch();
        }
        self::assertGreaterThan(0, (int)$theme->getId(), 'hanfu theme required');

        return $theme;
    }
}
