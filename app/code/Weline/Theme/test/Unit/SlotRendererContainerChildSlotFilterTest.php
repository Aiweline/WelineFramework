<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Service\SlotRendererService;
use Weline\Widget\Api\Rendering\RuntimeTemplateRendererInterface;

final class SlotRendererContainerChildSlotFilterTest extends TestCase
{
    public function testPublishedFilterKeepsContainerChildSlotWidgets(): void
    {
        $placeableRegistry = new class implements ThemePlaceableRegistryInterface {
            public function getAvailableList(
                ?string $pageType = null,
                ?array $filterOptions = null,
                ?\Weline\Theme\Model\WelineTheme $theme = null,
                string $area = 'frontend',
            ): array {
                return [];
            }

            public function find(
                string $module,
                string $type,
                string $code,
                ?\Weline\Theme\Model\WelineTheme $theme = null,
                string $area = 'frontend',
            ): ?ThemeComponentDefinition {
                if ($module === 'Weline_Product' && $code === 'product-info') {
                    return new ThemeComponentDefinition(
                        module: $module,
                        type: $type,
                        code: $code,
                        name: 'Product Info',
                        isContainer: true,
                        slots: [
                            'product-purchase-actions' => [
                                'name' => '购买操作',
                            ],
                        ],
                    );
                }

                return null;
            }

            public function getParamDefinitions(
                string $module,
                string $type,
                string $code,
                ?\Weline\Theme\Model\WelineTheme $theme = null,
                string $area = 'frontend',
            ): array {
                return [];
            }

            public function renderPreview(
                string $module,
                string $type,
                string $code,
                array $config = [],
                ?\Weline\Theme\Model\WelineTheme $theme = null,
                string $area = 'frontend',
            ): string {
                return '';
            }
        };

        $service = new SlotRendererService(
            $this->createMock(\Weline\Theme\Service\ThemeLayoutService::class),
            $this->createMock(\Weline\Widget\Api\WidgetRegistryInterface::class),
            $placeableRegistry,
            $this->createMock(\Weline\Theme\Service\ThemeComponentRenderer::class),
            $this->createMock(\Weline\Framework\View\Template::class),
            $this->createMock(RuntimeTemplateRendererInterface::class),
        );

        $slotWidgets = [
            'product-main' => [[
                'widget_module' => 'Weline_Product',
                'widget_type' => 'product',
                'widget_code' => 'product-info',
                'slot_id' => 'product-main',
            ]],
            'product-purchase-actions' => [[
                'widget_module' => 'Weline_Cart',
                'widget_type' => 'product',
                'widget_code' => 'product-add-to-cart',
                'slot_id' => 'product-purchase-actions',
            ]],
        ];

        $html = '<div data-wslot="product-main"></div>';

        $method = new \ReflectionMethod(SlotRendererService::class, 'filterWidgetsForHtmlSlots');
        $method->setAccessible(true);
        /** @var array<string, list<array<string, mixed>>> $filtered */
        $filtered = $method->invoke($service, $slotWidgets, $html);

        self::assertArrayHasKey('product-main', $filtered);
        self::assertArrayHasKey('product-purchase-actions', $filtered);
    }
}
