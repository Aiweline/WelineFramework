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

    public function testLegacyFooterHelpCenterLinkAliasResolvesFaqPlaceable(): void
    {
        $widgetConfig = $this->createMock(WidgetConfigService::class);
        $widgetConfig->expects(self::once())
            ->method('getParamDefinitions')
            ->with('Weline_Theme', 'footer-faq-link', 'frontend')
            ->willReturn([
                'label' => ['type' => 'string', 'default' => 'FAQ'],
            ]);

        $placeables = $this->createMock(ThemePlaceableRegistry::class);
        $placeables->expects(self::never())->method('find');

        $validator = new WidgetImageContentContractValidator(
            $widgetConfig,
            $placeables,
            new LayoutValueHydrationRegistry(),
        );

        $validator->validate([
            'footer' => [[
                'widget_module' => 'Weline_Theme',
                'widget_type' => 'footer',
                'widget_code' => 'footer-help-center-link',
                'config' => ['label' => 'FAQ'],
            ]],
        ], [
            'page_type' => 'homepage',
            'layout_area' => 'footer',
        ]);
    }

    public function testAudioMediaImageAllowsPathUrl(): void
    {
        $widgetConfig = $this->createMock(WidgetConfigService::class);
        $widgetConfig->method('getParamDefinitions')->willReturn([
            'tracks' => [
                'type' => 'array',
                'item_schema' => [
                    'url' => [
                        'type' => 'media_image',
                        'media_options' => [
                            'kind' => 'audio',
                            'usage' => '0',
                            'value_mode' => 'path',
                        ],
                    ],
                    'title' => ['type' => 'string'],
                ],
            ],
        ]);

        $validator = new WidgetImageContentContractValidator(
            $widgetConfig,
            $this->createMock(ThemePlaceableRegistry::class),
            new LayoutValueHydrationRegistry(),
        );

        $validator->validate([
            'content' => [[
                'widget_module' => 'Weline_StoreMusic',
                'widget_type' => 'feature',
                'widget_code' => 'store-music',
                'config' => [
                    'tracks' => [
                        [
                            'url' => '/media/websites/default/default/store-music/a.mp3',
                            'title' => 'A',
                        ],
                    ],
                ],
            ]],
        ], [
            'page_type' => 'homepage',
            'layout_area' => 'content',
        ]);

        self::assertTrue(true);
    }

    public function testNestedArrayImageRejectsLegacyUrlString(): void
    {
        $widgetConfig = $this->createMock(WidgetConfigService::class);
        $widgetConfig->method('getParamDefinitions')->willReturn([
            'slides' => [
                'type' => 'array',
                'item_schema' => [
                    'image' => ['type' => 'media_image'],
                    'title' => ['type' => 'string'],
                ],
            ],
        ]);

        $validator = new WidgetImageContentContractValidator(
            $widgetConfig,
            $this->createMock(ThemePlaceableRegistry::class),
            new LayoutValueHydrationRegistry(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/旧 URL/');

        $validator->validate([
            'content' => [[
                'widget_module' => 'Weline_Theme',
                'widget_type' => 'banner',
                'widget_code' => 'hero-slider',
                'config' => [
                    'slides' => [
                        ['image' => 'https://cdn.example/a.jpg', 'title' => 'A'],
                    ],
                ],
            ]],
        ], [
            'page_type' => 'homepage',
            'layout_area' => 'content',
        ]);
    }

    public function testNavTreeNestedImageRejectsLegacyUrlString(): void
    {
        $widgetConfig = $this->createMock(WidgetConfigService::class);
        $widgetConfig->method('getParamDefinitions')->willReturn([
            'menu_tree' => [
                'type' => 'nav_tree',
                'item_schema' => [
                    'name' => ['type' => 'string'],
                    'image' => ['type' => 'media_image'],
                ],
            ],
        ]);

        $validator = new WidgetImageContentContractValidator(
            $widgetConfig,
            $this->createMock(ThemePlaceableRegistry::class),
            new LayoutValueHydrationRegistry(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/旧 URL/');

        $validator->validate([
            'header' => [[
                'widget_module' => 'Weline_Theme',
                'widget_type' => 'navigation',
                'widget_code' => 'all-menu',
                'config' => [
                    'menu_tree' => [
                        [
                            'name' => '根',
                            'image' => '/media/old.png',
                            'children' => [
                                ['name' => '子', 'image' => 'https://cdn.example/child.jpg'],
                            ],
                        ],
                    ],
                ],
            ]],
        ], [
            'page_type' => 'homepage',
            'layout_area' => 'header',
        ]);
    }

    public function testNestedListJunkSlotsDoNotFailValidation(): void
    {
        $widgetConfig = $this->createMock(WidgetConfigService::class);
        $widgetConfig->method('getParamDefinitions')->willReturn([
            'slides' => [
                'type' => 'array',
                'item_schema' => [
                    'image' => ['type' => 'media_image'],
                ],
            ],
        ]);

        $validator = new WidgetImageContentContractValidator(
            $widgetConfig,
            $this->createMock(ThemePlaceableRegistry::class),
            new LayoutValueHydrationRegistry(),
        );

        $validator->validate([
            'content' => [[
                'widget_module' => 'Weline_Theme',
                'widget_type' => 'banner',
                'widget_code' => 'hero-slider',
                'config' => [
                    'slides' => [[]],
                ],
            ]],
        ], [
            'page_type' => 'homepage',
            'layout_area' => 'content',
        ]);

        self::assertTrue(true);
    }
}
