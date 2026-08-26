<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutValueHydrationRegistry;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\WidgetImageContentContractValidator;
use Weline\Widget\Service\WidgetConfigService;

final class WidgetImageContentContractValidatorTest extends TestCase
{
    public function testDashboardWidgetResolvesBackendRegistryArea(): void
    {
        $widgetConfig = $this->createMock(WidgetConfigService::class);
        $widgetConfig->expects(self::once())
            ->method('getParamDefinitions')
            ->with('Weline_Visitor', 'pixel_overview', 'backend')
            ->willReturn([
                'range' => ['type' => 'select', 'default' => '7d'],
            ]);

        $placeables = $this->createMock(ThemePlaceableRegistry::class);
        $placeables->expects(self::never())->method('find');

        $validator = new WidgetImageContentContractValidator(
            $widgetConfig,
            $placeables,
            new LayoutValueHydrationRegistry(),
        );

        $validator->validate([
            'content' => [[
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'stats',
                'widget_code' => 'pixel_overview',
                'config' => ['range' => '7d'],
            ]],
        ], [
            'page_type' => 'dashboard',
            'target_type' => 'website',
            'layout_area' => 'content',
        ]);

        self::assertTrue(true);
    }

    public function testFrontendWidgetStillResolvesFrontendRegistryArea(): void
    {
        $widgetConfig = $this->createMock(WidgetConfigService::class);
        $widgetConfig->expects(self::once())
            ->method('getParamDefinitions')
            ->with('Weline_Theme', 'all-menu', 'frontend')
            ->willReturn([
                'label' => ['type' => 'string', 'default' => '全部'],
            ]);

        $placeables = $this->createMock(ThemePlaceableRegistry::class);
        $placeables->expects(self::never())->method('find');

        $validator = new WidgetImageContentContractValidator(
            $widgetConfig,
            $placeables,
            new LayoutValueHydrationRegistry(),
        );

        $validator->validate([
            'header' => [[
                'widget_module' => 'Weline_Theme',
                'widget_type' => 'navigation',
                'widget_code' => 'all-menu',
                'config' => ['label' => '全部'],
            ]],
        ], [
            'page_type' => 'default',
            'layout_area' => 'header',
        ]);

        self::assertTrue(true);
    }
}
