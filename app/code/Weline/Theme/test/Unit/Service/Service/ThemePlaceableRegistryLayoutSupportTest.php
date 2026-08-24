<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Service\ThemeComponentCatalog;
use Weline\Theme\Service\ThemeComponentRenderer;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Widget\Service\WidgetConfigService;
use Weline\Widget\Service\WidgetPreviewService;

final class ThemePlaceableRegistryLayoutSupportTest extends TestCase
{
    public function testPageTypeFilterAllowsLayoutSupportCode(): void
    {
        $registry = new ThemePlaceableRegistry(
            $this->createMock(ThemeComponentCatalog::class),
            $this->createMock(ThemeComponentRenderer::class),
            $this->createMock(WidgetConfigService::class),
            $this->createMock(WidgetPreviewService::class)
        );

        $definition = new ThemeComponentDefinition(
            module: 'Weline_Theme',
            type: 'theme_component',
            code: 'hero-slider',
            name: 'Hero Slider',
            position: ['content'],
            pageLayouts: ['homepage', 'cms_page'],
            supports: ['layout-homepage-hero', 'layout-default-content']
        );

        $method = new \ReflectionMethod(ThemePlaceableRegistry::class, 'matchesPageType');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($registry, $definition, 'default', []));
        self::assertFalse($method->invoke($registry, $definition, 'product', []));
    }
}
