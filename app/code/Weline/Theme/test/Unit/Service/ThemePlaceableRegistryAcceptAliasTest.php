<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeComponentCatalog;
use Weline\Theme\Service\ThemeComponentRenderer;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Widget\Service\WidgetConfigService;
use Weline\Widget\Service\WidgetPreviewService;

final class ThemePlaceableRegistryAcceptAliasTest extends TestCase
{
    public function testLayoutVariantAcceptMatchesGenericLayoutSupportCode(): void
    {
        $registry = new ThemePlaceableRegistry(
            $this->createMock(ThemeComponentCatalog::class),
            $this->createMock(ThemeComponentRenderer::class),
            $this->createMock(WidgetConfigService::class),
            $this->createMock(WidgetPreviewService::class)
        );

        $method = new \ReflectionMethod(ThemePlaceableRegistry::class, 'matchesSlotCodeProtocol');
        $method->setAccessible(true);

        $accept = ['layout-homepage-minimal-content'];
        $widgetCodes = ['layout-homepage-content'];

        self::assertTrue($method->invoke($registry, $accept, $widgetCodes));
    }

    public function testWidgetCodeAloneDoesNotMatchLayoutAcceptToken(): void
    {
        $registry = new ThemePlaceableRegistry(
            $this->createMock(ThemeComponentCatalog::class),
            $this->createMock(ThemeComponentRenderer::class),
            $this->createMock(WidgetConfigService::class),
            $this->createMock(WidgetPreviewService::class)
        );

        $method = new \ReflectionMethod(ThemePlaceableRegistry::class, 'matchesSlotCodeProtocol');
        $method->setAccessible(true);

        $accept = ['layout-homepage-minimal-content'];
        $widgetCodes = ['hero-slider'];

        self::assertFalse($method->invoke($registry, $accept, $widgetCodes));
    }
}
