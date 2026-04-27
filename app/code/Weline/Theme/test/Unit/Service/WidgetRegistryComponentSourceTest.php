<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\WidgetRegistryComponentSource;
use Weline\Widget\Service\WidgetRegistry;

final class WidgetRegistryComponentSourceTest extends TestCase
{
    public function testCollectInfersHeaderPositionWhenRegistryMetadataIsMissing(): void
    {
        $registry = $this->createMock(WidgetRegistry::class);
        $registry->method('getRegistry')->willReturn([
            'header' => [
                'cart-icon' => [
                    'module' => 'WeShop_Cart',
                    'type' => 'header',
                    'code' => 'cart-icon',
                    'name' => '购物车图标',
                    'template' => 'WeShop_Cart::theme/frontend/widgets/header/cart-icon.phtml',
                ],
            ],
            'container' => [
                'header-container' => [
                    'module' => 'Weline_Theme',
                    'type' => 'container',
                    'code' => 'header-container',
                    'name' => 'Header 容器',
                    'template' => 'Weline_Theme::theme/frontend/widgets/container/header/default.phtml',
                ],
            ],
        ]);

        $source = new WidgetRegistryComponentSource($registry);
        $definitions = $source->collect('frontend');

        self::assertCount(2, $definitions);
        self::assertSame(['header'], $definitions[0]->position);
        self::assertSame(['header'], $definitions[1]->position);
    }
}
