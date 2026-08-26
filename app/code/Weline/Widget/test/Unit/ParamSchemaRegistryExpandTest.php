<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit;

use Weline\Framework\Test\TestCore;
use Weline\Widget\Service\ParamSchemaRegistry;

final class ParamSchemaRegistryExpandTest extends TestCore
{
    public function testExpandBannerItemsProvidesArrayItemSchema(): void
    {
        /** @var ParamSchemaRegistry $registry */
        $registry = $this->objectManager->getInstance(ParamSchemaRegistry::class);

        $expanded = $registry->expandParams([
            'slides' => [
                'type' => 'banner_items',
                'label' => '轮播图片',
            ],
        ]);

        self::assertSame('array', $expanded['slides']['type'] ?? null);
        self::assertSame('banner_items', $expanded['slides']['schema_type'] ?? null);
        self::assertIsArray($expanded['slides']['item_schema'] ?? null);
        self::assertArrayHasKey('image', $expanded['slides']['item_schema']);
        self::assertArrayHasKey('title', $expanded['slides']['item_schema']);
        self::assertArrayHasKey('subtitle', $expanded['slides']['item_schema']);
        self::assertArrayHasKey('link', $expanded['slides']['item_schema']);
        self::assertArrayHasKey('button_text', $expanded['slides']['item_schema']);
    }

    public function testExpandAllMenuTreeRewritesUiTypeAndInputToNavTree(): void
    {
        /** @var ParamSchemaRegistry $registry */
        $registry = $this->objectManager->getInstance(ParamSchemaRegistry::class);

        $expanded = $registry->expandParams([
            'menu_tree' => [
                'type' => 'all_menu_tree',
                'ui_type' => 'all_menu_tree',
                'input' => 'all_menu_tree',
                'label' => '导航树',
            ],
        ]);

        self::assertSame('nav_tree', $expanded['menu_tree']['type'] ?? null);
        self::assertSame('nav_tree', $expanded['menu_tree']['ui_type'] ?? null);
        self::assertSame('nav_tree', $expanded['menu_tree']['input'] ?? null);
        self::assertSame('all_menu_tree', $expanded['menu_tree']['schema_type'] ?? null);
        self::assertFalse($expanded['menu_tree']['i18n'] ?? true);
    }
}
